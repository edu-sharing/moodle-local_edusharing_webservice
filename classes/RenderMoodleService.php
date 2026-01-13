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
use core\task\manager;
use dml_exception;
use local_edusharing_webservice\task\RestoreTask;
use mod_edusharing\UtilityFunctions;
use PharData;
use PhpOffice\PhpSpreadsheet\Exception;
use progressive_parser;
use restore_controller;
use restore_controller_exception;
use restore_dbops;
use restore_moodlexml_parser_processor;
use RuntimeException;
use stdClass;
use ZipArchive;

class RenderMoodleService
{
    private UtilityFunctions $utils;
    public string $tempDir;
    private ?int $adminId = null;

    public function __construct() {
        $this->utils = new UtilityFunctions();
        $this->tempDir = uniqid();
    }

    /**
     * @throws Exception
     * @throws dml_exception
     */
    public function triggerRestore(string $nodeId, string $title, int $category): RestoreCourseDTO {
        global $DB;
        $course = $DB->get_record('course', ['idnumber' => $nodeId]);
        if ($course !== false) {
            return new RestoreCourseDTO(courseId: $course->id, restoreId: null);
        }
        $restore = $DB->get_record('edu_restore', ['nodeid' => $nodeId]);
        // Todo: Implement time based restore status invalidation (if job is running for longer than x minutes -> reforce)
        if ($restore !== false) {
            return new RestoreCourseDTO(courseId: null, restoreId: $restore->id);
        }

        $restoreId = $DB->insert_record('edu_restore', ['nodeid' => $nodeId, 'lastmodified' => time()]);
        $task = new RestoreTask();
        $task->set_custom_data(
            [
                'restoreid' => $restoreId,
                'title' => $title,
                'category' => $category
            ]
        );
        $task->set_userid($this->getAdminId());
        manager::queue_adhoc_task($task);
        return new RestoreCourseDTO(courseId: null, restoreId: $restoreId);
    }

    /**
     * @throws dml_exception
     * @throws \progressive_parser_exception
     * @throws restore_controller_exception
     */
    public function prepareRestore(string $nodeId, string $category): restore_controller {
        global $CFG;
        require_once($CFG->libdir . '/adminlib.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        $this->getNodeContent($nodeId);
        $this->unpackFile();
        $this->checkBackupFormat();
        $details = $this->getInfo($this->getFilePath());
        if (!in_array($details->type, [backup::TYPE_1ACTIVITY, backup::TYPE_1COURSE])) {
            throw new Exception('Backup type'. $details->type . 'not supported');
        }
        $isActivity = $details->type === backup::TYPE_1ACTIVITY;
        $courseId  = restore_dbops::create_new_course('', '', $category);
        $target = $isActivity ? backup::TARGET_EXISTING_ADDING : backup::TARGET_NEW_COURSE;

        return new restore_controller(
            tempdir: $this->getFilePath(),
            courseid: $courseId,
            interactive: backup::INTERACTIVE_NO,
            mode: backup::MODE_ASYNC,
            userid: $this->getAdminId(),
            target: $target
        );
    }

    /**
     * @throws dml_exception
     */
    private function getNodeContent(string $nodeId): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        require_once($CFG->libdir . '/setuplib.php');

        $savePath = $this->getFilePath();
        mkdir($savePath, 0744, true);
        $timestamp = round(microtime(true) * 1000);
        $signData = $nodeId . $timestamp;
        $privateKey = openssl_get_privatekey($this->utils->get_config_entry('application_private_key'));
        openssl_sign($signData, $signature, $privateKey);
        $signature = urlencode(base64_encode($signature));

        $contentUrl = rtrim($this->utils->get_internal_url(), '/') . '/content';
        $contentUrl .= '?appId=' . get_config('edusharing', 'application_appid');
        $contentUrl .= '&nodeId=' . $nodeId;
        $contentUrl .= '&timeStamp=' . $timestamp;
        $contentUrl .= '&authToken=' . $signature;

        $localFile = $savePath . '/course.mbz';

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
        $context = stream_context_create($opts);

        $remoteHandle = fopen($contentUrl, "rb", false, $context);
        if ($remoteHandle === false) {
            throw new \RuntimeException("Failed to open remote URL: $contentUrl");
        }

        $localHandle = fopen($localFile, "wb");
        if ($localHandle === false) {
            fclose($remoteHandle);
            throw new \RuntimeException("Failed to open local file for writing: $localFile");
        }

        while (!feof($remoteHandle)) {
            $chunk = fread($remoteHandle, 8192);
            if ($chunk === false) {
                fclose($remoteHandle);
                fclose($localHandle);
                throw new \RuntimeException("Error reading from remote stream");
            }
            $written = fwrite($localHandle, $chunk);
            if ($written === false) {
                fclose($remoteHandle);
                fclose($localHandle);
                throw new \RuntimeException("Error writing to local file: $localFile");
            }
        }

        fclose($remoteHandle);
        fclose($localHandle);
    }

    private function unpackFile(): void {
        $coursePath = $this->getFilePath();
        $zip        = new ZipArchive;
        $res        = $zip->open($coursePath . '/course.mbz');

        if ($res === true) {
            $zip->extractTo($coursePath);
            $zip->close();
        } else {
            // decompress from gz
            $p = new PharData($coursePath . '/course.mbz');
            $p->decompress();
            // unarchive from the tar
            $phar = new PharData($coursePath . '/course.tar');
            $phar->extractTo($coursePath);
        }
    }

    private function getFilePath(): string {
        global $CFG;
        return $CFG->dataroot . '/temp/backup/' . $this->tempDir;
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
            strpos($firstchars, '<moodle_backup>') !== false &&
            strpos($firstchars, '<information>') !== false) {
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
     * @throws \progressive_parser_exception
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
        return new CourseInfoDTO(type: $type);
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
        $this->adminId = $admin->id;
        return $this->adminId;
    }

    /**
     * @throws dml_exception
     */
    public function finalizeCourse(int $courseId, string $nodeId, string $title): void {
        global $DB;
        $updCourse = ['id' => $courseId, 'fullname' => $title, 'shortname' => $title, 'idnumber' => $nodeId];
        $DB->update_record('course', $updCourse, $bulk = false);

        //activity backups do not set enrolement method on restore, so do this manually
        $enrolId = $DB->get_record('enrol', ['courseid' => $courseId, 'enrol' => 'manual']);
        if (empty($enrolId)) {
            $enrol           = new stdClass();
            $enrol->enrol    = 'manual';
            $enrol->courseid = $courseId;
            $DB->insert_record('enrol', $enrol);
        }
    }
}
