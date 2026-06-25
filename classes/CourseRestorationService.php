<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace local_edusharing_webservice;

use backup;
use base_plan_exception;
use base_setting_exception;
use core\exception\coding_exception;
use core\task\manager;
use core_plugin_manager;
use dml_exception;
use Exception;
use local_edusharing_webservice\task\RestoreTask;
use PharData;
use progressive_parser;
use progressive_parser_exception;
use restore_controller;
use restore_controller_exception;
use restore_dbops;
use restore_moodlexml_parser_processor;
use RuntimeException;
use stdClass;
use ZipArchive;

/**
 * Class CourseRestorationService
 *
 * Service responsible for course restoration operations, including fetching course content,
 * preparing backup files, validating backup formats, and triggering restoration processes.
 */
class CourseRestorationService extends RenderMoodleService {
    public string $tempDir;
    private ?int $adminId = null;
    private string $testPrefix = 'test_';
    private ?int $courseId = null;

    public function __construct() {
        parent::__construct();
        $this->tempDir = uniqid();
    }

    /**
     * Function course
     *
     * Prepares the course and edu_restore record for the restore job,
     * and triggers the restore process by queueing an ad-hoc task.
     *
     * Returns a course ID if the course is already in the system
     *
     * @param string $nodeId The unique identifier of the course node.
     * @param string $title The title of the course.
     * @param int $category The category ID of the course.
     *
     * @throws Exception
     * @throws dml_exception
     */
    public function course(string $nodeId, string $title, int $category): RestoreCourseDTO {
        global $DB;
        $existingCourse = $DB->get_record(table: 'course', conditions: ['idnumber' => $nodeId]);
        if ($existingCourse !== false) {
            if ($existingCourse->fullname !== $title || $existingCourse->shortname !== $title) {
                $DB->update_record(table: 'course', dataobject: [
                    'id'        => $existingCourse->id,
                    'fullname'  => $title,
                    'shortname' => $title,
                ]);
            }
            return new RestoreCourseDTO(courseId: (int)$existingCourse->id, restoreId: null);
        }
        $restore = $DB->get_record(table: 'edu_restore', conditions: ['nodeid' => $nodeId]);
        if ($restore !== false && !$this->isRestoreStale($restore)) {
            return new RestoreCourseDTO(courseId: null, restoreId: (int)$restore->id);
        }

        if ($restore !== false) {
            // Stale restore (crashed, hung, or never picked up): reset the existing
            // record and re-queue against the same restoreId so callers polling the
            // status keep their handle.
            $DB->update_record(table: 'edu_restore', dataobject: [
                'id'           => $restore->id,
                'status'       => RestoreStatus::QUEUED,
                'lastmodified' => time(),
                'message'      => null,
                'usermessage'  => null,
                'courseid'     => null,
            ]);
            $restoreId = (int)$restore->id;
        } else {
            $restoreId = $DB->insert_record(table: 'edu_restore', dataobject: [
                'nodeid'       => $nodeId,
                'status'       => RestoreStatus::QUEUED,
                'lastmodified' => time(),
            ]);
        }

        $task = new RestoreTask();
        $task->set_custom_data(
            customdata: [
                'restoreid' => $restoreId,
                'title' => $title,
                'category' => $category
            ]
        );
        $task->set_userid(userid: $this->getAdminId());
        manager::queue_adhoc_task(task: $task);
        return new RestoreCourseDTO(courseId: null, restoreId: $restoreId);
    }

    /**
     * Decide whether an existing restore record should be re-forced.
     *
     * A failed restore is re-forced immediately so a retry can start right away.
     * A queued/running restore is only re-forced once it has not been updated within
     * the configured timeout (its task most likely crashed, hung, or was never picked
     * up by cron). A successful restore is never re-forced.
     *
     * @throws dml_exception
     */
    private function isRestoreStale(stdClass $restore): bool {
        if ($restore->status === RestoreStatus::SUCCESS) {
            return false;
        }
        if ($restore->status === RestoreStatus::FAILURE) {
            // A failed restore can be retried immediately; no need to wait for the timeout.
            return true;
        }
        $timeout = (int) get_config('local_edusharing_webservice', 'restoretimeout');
        if ($timeout <= 0) {
            $timeout = 30 * MINSECS;
        }
        return (time() - (int) $restore->lastmodified) > $timeout;
    }

    /**
     * @throws dml_exception
     * @throws progressive_parser_exception
     * @throws restore_controller_exception
     * @throws base_plan_exception
     * @throws base_setting_exception
     * @throws UserException
     * @throws coding_exception
     * @throws Exception
     */
    public function prepareRestore(string $nodeId, int $category): restore_controller {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $this->getNodeContent(nodeId: $nodeId);
        $this->unpackFile(nodeId: $nodeId);
        $this->checkBackupFormat();
        $details = $this->getInfo(dir: $this->getFilePath());
        if (! empty($details->missingPlugins)) {
            throw new UserException(
                internalMessage: 'Missing plugins: ' . implode(', ', $details->missingPlugins),
                externalMessage: get_string('error_missing_plugins', 'local_edusharing_webservice')
                . ' ' . implode(', ', $details->missingPlugins),
            );
        }
        if (!in_array($details->type, [backup::TYPE_1ACTIVITY, backup::TYPE_1COURSE])) {
            throw new Exception('Backup type'. $details->type . 'not supported');
        }
        $isActivity = $details->type === backup::TYPE_1ACTIVITY;
        $this->courseId  = restore_dbops::create_new_course(fullname: '', shortname: '', categoryid: $category);
        $target = $isActivity ? backup::TARGET_EXISTING_ADDING : backup::TARGET_NEW_COURSE;

        $controller = new restore_controller(
            tempdir: $this->tempDir,
            courseid: $this->courseId,
            interactive: backup::INTERACTIVE_NO,
            mode: backup::MODE_ASYNC,
            userid: $this->getAdminId(),
            target: $target
        );
        $controller->get_plan()->get_setting('users')->set_value(false);
        $precheckok = $controller->execute_precheck();
        if (!$precheckok) {
            throw new RuntimeException('Restore precheck failed: ' . json_encode($controller->get_precheck_results()));
        }
        return $controller;
    }

    /**
     * @throws dml_exception
     * @throws Exception
     */
    private function getNodeContent(string $nodeId): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->libdir . '/setuplib.php');

        $savePath = $this->getFilePath();
        if (!mkdir($savePath, 0744, true) && !is_dir($savePath)) {
            error_log("getNodeContent: failed to create save directory: $savePath");
            throw new RuntimeException("Failed to create save directory: $savePath");
        }
        $localFile = $savePath . '/' . $nodeId .'.mbz';

        if (str_starts_with(haystack: $nodeId, needle: $this->testPrefix)) {
            $testFile = $CFG->dirroot . '/test_data/zoerr1.mbz';
            if (is_readable(filename: $testFile)) {
                if (!copy(from: $testFile, to: $localFile)) {
                    throw new RuntimeException("Failed to copy local test backup file: $testFile");
                }
                return;
            }
        }

        $internalUrl = $this->utils->get_internal_url();
        if (empty($internalUrl)) {
            throw new RuntimeException('No internal edu-sharing URL configured');
        }

        $timestamp = round(microtime(true) * 1000);
        // sign() returns a base64-encoded signature which may contain +, / and =;
        // it must be url-encoded before being placed in the query string.
        $authToken = urlencode($this->eduservice->sign(input: $nodeId . $timestamp));
        $contentUrl = rtrim(string: $internalUrl, characters: '/') . '/content';
        $contentUrl .= '?appId=' . get_config(plugin: 'edusharing', name: 'application_appid');
        $contentUrl .= '&nodeId=' . $nodeId;
        $contentUrl .= '&timeStamp=' . $timestamp;
        $contentUrl .= '&authToken=' . $authToken;
        $contentUrl .= '&signedAlg=' . $this->eduservice->get_signing_algorithm();

        $opts    = [
            "http" => [
                "method"        => "GET",
                "header"        => "User-Agent: PHP\r\n",
                "ignore_errors" => true, // so you can get 4xx/5xx responses
            ],
            "ssl"  => [
                "verify_peer"      => false,
                "verify_peer_name" => false,
            ]
        ];
        $context = stream_context_create(options: $opts);

        $remoteHandle = fopen(filename: $contentUrl, mode: 'rb', context: $context);
        if ($remoteHandle === false) {
            error_log("getNodeContent: failed to open remote URL (see preceding PHP warning for cause)");
            throw new RuntimeException("Failed to open remote URL: $contentUrl");
        }

        // With ignore_errors enabled the stream opens even on 4xx/5xx; inspect the
        // response headers so a failed download is logged instead of silently saved.
        $responseHeaders = stream_get_meta_data($remoteHandle)['wrapper_data'] ?? [];
        $statusLine = $responseHeaders[0] ?? '';
        if ($statusLine !== '' && !preg_match('#\s2\d\d\s#', $statusLine)) {
            error_log("getNodeContent: non-2xx HTTP response from content endpoint: '$statusLine'. "
                . "Response headers: " . implode(' | ', $responseHeaders));
        }

        $localHandle = fopen(filename: $localFile, mode: "wb");
        if ($localHandle === false) {
            fclose(stream: $remoteHandle);
            error_log("getNodeContent: failed to open local file for writing: $localFile");
            throw new RuntimeException("Failed to open local file for writing: $localFile");
        }

        $totalBytes = 0;
        while (!feof(stream: $remoteHandle)) {
            $chunk = fread(stream: $remoteHandle, length: 8192);
            if ($chunk === false) {
                fclose($remoteHandle);
                fclose($localHandle);
                error_log("getNodeContent: error reading from remote stream after $totalBytes bytes");
                throw new RuntimeException("Error reading from remote stream");
            }
            $written = fwrite($localHandle, $chunk);
            if ($written === false) {
                fclose($remoteHandle);
                fclose($localHandle);
                error_log("getNodeContent: error writing to local file $localFile after $totalBytes bytes");
                throw new RuntimeException("Error writing to local file: $localFile");
            }
            $totalBytes += $written;
        }

        fclose($remoteHandle);
        fclose($localHandle);

        if ($totalBytes === 0) {
            error_log("getNodeContent: WARNING - downloaded 0 bytes for nodeId '$nodeId'; "
                . "the resulting backup file will be empty/invalid");
        }
    }

    private function unpackFile(string $nodeId): void {
        $coursePath = $this->getFilePath();
        $zip        = new ZipArchive;
        $res        = $zip->open($coursePath . '/' . $nodeId . '.mbz');

        if ($res === true) {
            $zip->extractTo($coursePath);
            $zip->close();
        } else {
            // decompress from gz
            $p = new PharData($coursePath . '/'. $nodeId. '.mbz');
            $p->decompress();
            // unarchive from the tar
            $phar = new PharData($coursePath . '/' . $nodeId. '.tar');
            $phar->extractTo($coursePath);
        }
    }

    private function getFilePath(): string {
        global $CFG;
        return $CFG->tempdir . '/backup/' . $this->tempDir;
    }

    private function checkBackupFormat(): void {
        $filepath = $this->getFilePath() . '/moodle_backup.xml';
        if (!file_exists($filepath)) {
            throw new RuntimeException('moodle_backup.xml not found');
        }

        $handle     = fopen($filepath, 'r');
        $firstchars = fread($handle, 200);
        fclose($handle);

        // Look for expected XML elements (case-insensitive to account for encoding attribute).
        if (stripos($firstchars, '<?xml version="1.0" encoding="UTF-8"?>') !== false &&
            str_contains($firstchars, '<moodle_backup>') &&
            str_contains($firstchars, '<information>')) {
            return;
        }

        throw new RuntimeException('moodle_backup.xml is not a valid backup file');
    }

    /**
     * Function getInfo returns the backup information from the moodle_backup.xml file.
     * The code is taken from the backup_general_helper::get_backup_information() function,
     * which does not work for this purpose. Please refer to it when changing this function.
     *
     * @param string $dir the directory where the moodle_backup.xml file is located
     *
     * @throws progressive_parser_exception
     * @throws Exception
     */
    private function getInfo(string $dir): CourseInfoDTO {
        $file = $dir . '/moodle_backup.xml';
        $xmlParser = new progressive_parser();
        $xmlParser->set_file($file);
        $xmlProcessor = new restore_moodlexml_parser_processor();
        $xmlParser->set_processor($xmlProcessor);
        $xmlParser->process();
        $infoArr = $xmlProcessor->get_all_chunks();
        $infoArr = $infoArr[0]['tags'];

        $type = $infoArr['details']['detail'][0]['type'];
        $plugins = [];

        if (!empty($infoArr['original_course_format'])) {
            $plugins[] = 'format_' . $infoArr['original_course_format'];
        }

        if (isset($infoArr['contents']['activities']['activity'])) {
            foreach ($infoArr['contents']['activities']['activity'] as $activity) {
                if (!empty($activity['modulename'])) {
                    $plugins[] = 'mod_' . $activity['modulename'];
                }
            }
        }

        if (isset($infoArr['contents']['blocks']['block'])) {
            foreach ($infoArr['contents']['blocks']['block'] as $block) {
                if (!empty($block['blockname'])) {
                    $plugins[] = 'block_' . $block['blockname'];
                }
            }
        }

        if (isset($infoArr['settings'])) {
            $plugins = array_merge($plugins, $this->getComponentsFromBackupSettings($infoArr['settings']));
        }

        $plugins = array_merge($plugins, $this->getQuestionTypeComponents($dir));

        $plugins = array_values(array_unique($plugins));
        [$thirdPartyPlugins, $missingPlugins] = $this->getThirdPartyPlugins($plugins);

        return new CourseInfoDTO(
            type: $type,
            plugins: $plugins,
            thirdPartyPlugins: $thirdPartyPlugins,
            missingPlugins: $missingPlugins
        );
    }

    /**
     * Extract question type components from question XML files in an extracted Moodle backup.
     *
     * @param string $dir The extracted backup directory.
     * @return string[]
     * @throws Exception
     */
    private function getQuestionTypeComponents(string $dir): array {
        $components = [];

        $fileName = $dir . '/questions.xml';
        if (!file_exists($fileName)) {
            return [];
        }

        $xml = simplexml_load_file($fileName);
        if ($xml === false) {
            throw new Exception("Failed to load question XML file: $fileName");
        }

        foreach ($xml->xpath('//question/qtype') ?: [] as $qtype) {
            $qtype = trim((string) $qtype);
            if ($qtype === '') {
                continue;
            }

            $components[] = 'qtype_' . $qtype;
        }

        return array_values(array_unique($components));
    }

    /**
     * Extract Moodle component names from the backup settings section.
     *
     * The settings section contains entries such as:
     * - <activity>forum_279049</activity>
     * - <name>forum_279049_included</name>
     * - <name>forum_279049_userinfo</name>
         */
        private function getComponentsFromBackupSettings(array $settings): array {
            $components = [];

            foreach ($settings['setting'] ?? [] as $setting) {
                if (($setting['level'] ?? null) !== 'activity') {
                    continue;
                }

                $activity = $setting['activity'] ?? null;
                if (!is_string($activity)) {
                    continue;
                }

                if (preg_match('/^([a-z][a-z0-9_]*)_\d+$/', $activity, $matches)) {
                    $components[] = 'mod_' . $matches[1];
                }
            }

            return array_values(array_unique($components));
        }

        /**
         * @param string[] $plugins
         * @return array{0: string[], 1: string[]} [thirdPartyPlugins, missingPlugins]
         */
        private function getThirdPartyPlugins(array $plugins): array {
        $thirdParty = [];
        $missing = [];
        foreach ($plugins as $plugin) {
            $plugininfo = core_plugin_manager::instance()->get_plugin_info($plugin);
            if ($plugininfo === null) {
                $missing[] = $plugin;
                $thirdParty[] = $plugin;
            } else if (!$plugininfo->is_standard()) {
                $thirdParty[] = $plugin;
            }
        }
        return [$thirdParty, $missing];
    }

    /**
     * @throws Exception
     */
    private function getAdminId(): int {
        if ($this->adminId !== null) {
            return $this->adminId;
        }
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        $admin = get_admin();
        if ($admin === false) {
            throw new Exception('Admin user not found');
        }
        $this->adminId = (int)$admin->id;
        return $this->adminId;
    }

    /**
     * @throws dml_exception
     */
    public function finalizeCourse(int $courseId, string $nodeId, string $title): void {
        global $DB;
        $updCourse = ['id' => $courseId, 'fullname' => $title, 'shortname' => $title, 'idnumber' => $nodeId];
        $DB->update_record(table: 'course', dataobject: $updCourse);

        //activity backups do not set enrolement method on restore, so do this manually
        $enrolId = $DB->get_record(table: 'enrol', conditions: ['courseid' => $courseId, 'enrol' => 'manual']);
        if (empty($enrolId)) {
            $enrol           = new stdClass();
            $enrol->enrol    = 'manual';
            $enrol->courseid = $courseId;
            $DB->insert_record(table: 'enrol', dataobject: $enrol);
        }
    }

    /**
     * Function getStatus returns the status of the restore job.
     *
     * @throws dml_exception
     */
    public function getStatus(int $restoreid): StatusDTO {
        global $DB;
        $restore = $DB->get_record(table: 'edu_restore', conditions: ['id' => $restoreid], strictness: MUST_EXIST);
        return new StatusDTO(
            status: $restore->status,
            userMessage: $restore->usermessage,
            internalMessage: $restore->message,
            courseId: (int)$restore->courseid,
        );
    }

    public function cleanup(): void {
        remove_dir($this->getFilePath());
    }

    public function rollback(): void {
        $this->cleanup();
        if ($this->courseId !== null) {
            try {
                $course = $this->courseId;
                delete_course($course, false);
            } catch (Exception) {
                mtrace('Course not found');
            }
        }
    }

    public function getCourseId(): ?int {
        return $this->courseId;
    }
}
