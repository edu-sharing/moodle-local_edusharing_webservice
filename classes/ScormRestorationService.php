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

use core\exception\moodle_exception;
use dml_exception;
use Exception;
use stdClass;

/**
 * Class ScormRestorationService
 */
class ScormRestorationService extends RenderMoodleService {
    private int $categoryid;
    private string $title;
    private string $nodeid;
    private ?object $course = null;

    public function __construct(int $categoryid, string $title, string $nodeid) {
        parent::__construct();
        $this->categoryid = $categoryid;
        $this->title = $title;
        $this->nodeid = $nodeid;
    }

    /**
     * @throws moodle_exception
     */
    public function scorm(): int {
        $existing_course_id = $this->get_existing_course_id($this->nodeid);
        if ($existing_course_id !== null) {
            return $existing_course_id;
        }
        try {
            $this->create_course();
            $this->add_scorm_to_course();
        } catch (Exception $exception) {
            // Adding the SCORM activity (or fetching its package) can fail after the
            // course has already been created; remove the half-built course so a
            // retry starts clean instead of leaving an orphaned, empty course behind.
            $this->rollback();
            throw $exception;
        }
        return (int) $this->course->id;
    }

    /**
     * Delete the course created during a failed SCORM restoration, if any.
     */
    private function rollback(): void {
        global $CFG;
        if ($this->course === null) {
            return;
        }
        require_once($CFG->dirroot . '/course/lib.php');
        try {
            delete_course($this->course->id, false);
        } catch (Exception $exception) {
            error_log('edu-sharing SCORM rollback: failed to delete course '
                . $this->course->id . ': ' . $exception->getMessage());
        }
        $this->course = null;
    }

    /**
     * @throws \moodle_exception
     */
    private function create_course(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $unique = uniqid();
        $data = new stdClass();
        $data->category = $this->categoryid;
        $data->fullname = $this->title . '_' . $unique;
        $data->shortname = $this->title . '_' . $unique;
        $data->format= 'singleactivity';
        $data->activitytype = 'scorm';
        $data->idnumber = $this->nodeid;
        $this->course = create_course($data);
    }

    /**
     * @throws dml_exception
     * @throws Exception
     */
    private function get_content_url(): string {
        $timestamp = round(microtime(true) * 1000);
        $signData = $this->nodeid . $timestamp;
        $signed = urlencode($this->eduservice->sign($signData));
        $contentUrl = trim($this->utils->get_internal_url(), '/') . '/content';
        $contentUrl .= '?appId=' . $this->utils->get_config_entry('application_appid');
        $contentUrl .= '&nodeId=' . $this->nodeid;
        $contentUrl .= '&timeStamp=' . $timestamp;
        $contentUrl .= '&authToken=' . $signed;
        $contentUrl .= '&signedAlg=' . $this->eduservice->get_signing_algorithm();
        return $contentUrl;
    }

    /**
     * Download the edu-sharing package into the current user's draft file area and
     * return the draft item id, so it can be imported as a SCORM_TYPE_LOCAL package.
     *
     * An explicit filename is used so the stored file is not named after the (very
     * long, query-string-only) content URL.
     *
     * @throws dml_exception
     * @throws moodle_exception
     * @throws Exception
     */
    private function download_package_to_draft(): int {
        global $CFG, $USER;
        require_once($CFG->libdir . '/filelib.php');
        raise_memory_limit(MEMORY_EXTRA);

        // Stream the download to a temp file so large packages do not exhaust memory.
        $tempfile = tempnam($CFG->tempdir, 'edusharing_scorm_');
        if ($tempfile === false) {
            throw new Exception('Failed to create temp file for SCORM package download');
        }
        $ok = download_file_content($this->get_content_url(), null, null, false, 300, 20, false, $tempfile);
        clearstatcache(true, $tempfile);
        if ($ok !== true || filesize($tempfile) === 0) {
            @unlink($tempfile);
            throw new Exception('Failed to download SCORM package for node ' . $this->nodeid);
        }

        $fs = get_file_storage();
        $draftitemid = file_get_unused_draft_itemid();
        $filename = clean_param($this->nodeid . '.zip', PARAM_FILE);
        $filerecord = [
            'contextid' => \context_user::instance($USER->id)->id,
            'component' => 'user',
            'filearea'  => 'draft',
            'itemid'    => $draftitemid,
            'filepath'  => '/',
            'filename'  => $filename,
            // file_save_draft_area_files() unserialises the draft file's source and
            // reads ->source from it, so store a serialised object rather than the bare
            // (very long) content URL, which both overflows the column and fails to
            // unserialise.
            'source'    => serialize((object) ['source' => $filename]),
        ];
        try {
            $fs->create_file_from_pathname($filerecord, $tempfile);
        } finally {
            @unlink($tempfile);
        }
        return $draftitemid;
    }

    /**
     * @throws dml_exception
     * @throws moodle_exception
     */
    private function add_scorm_to_course(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->dirroot . '/mod/scorm/lib.php');
        $scorm_module_id = $DB->get_field('modules', 'id', ['name' => 'scorm']);
        $scormdata = new stdClass();
        // Download the package ourselves into a draft file area with a clean filename
        // and import it as a LOCAL package. Handing SCORM the signed content URL
        // (SCORM_TYPE_LOCALSYNC) makes Moodle name the stored file after the URL's
        // last segment — the whole query string — which overflows the 255-char
        // files.filename column and aborts the transaction.
        $scormdata->scormtype = SCORM_TYPE_LOCAL;
        $scormdata->packagefile = $this->download_package_to_draft();
        $scormdata->intro = 'scorm';
        $scormdata->datadir = '';
        $scormdata->pkgtype = '';
        $scormdata->launch = '';
        $scormdata->redirect = 'yes';
        $scormdata->redirecturl = '../course/view.php?id=' . $this->course->id;
        $scormdata->completionunlocked = '1';
        $scormdata->course = $this->course->id;
        $scormdata->section = 0;
        $scormdata->module = $scorm_module_id;
        $scormdata->modulename = 'scorm';
        $scormdata->instance = '';
        $scormdata->return = '0';
        $scormdata->sr = '0';
        $scormdata->mform_showmore_id_displaysettings = '0';
        $scormdata->mform_isexpanded_id_general = '1';
        $scormdata->mform_isexpanded_id_displaysettings = '0';
        $scormdata->mform_isexpanded_id_availability = '0';
        $scormdata->mform_isexpanded_id_gradesettings = '0';
        $scormdata->mform_isexpanded_id_attemptsmanagementhdr = '0';
        $scormdata->mform_isexpanded_id_compatibilitysettingshdr = '0';
        $scormdata->mform_isexpanded_id_modstandardelshdr = '0';
        $scormdata->mform_isexpanded_id_availabilityconditionsheader = '0';
        $scormdata->mform_isexpanded_id_activitycompletionheader = '0';
        $scormdata->mform_isexpanded_id_tagshdr = '0';
        $scormdata->mform_isexpanded_id_competenciessection = '0';
        $scormdata->updatefreq = '0';
        $scormdata->popup = '0';
        $scormdata->displayactivityname = '1';
        $scormdata->skipview = '2';
        $scormdata->hidebrowse = '0';
        $scormdata->displaycoursestructure = '0';
        $scormdata->hidetoc = '1';
        $scormdata->nav = '1';
        $scormdata->displayattemptstatus = '1';
        $scormdata->grademethod = '1';
        $scormdata->maxgrade = '100';
        $scormdata->maxattempt = '0';
        $scormdata->whatgrade = '0';
        $scormdata->forcenewattempt = '0';
        $scormdata->lastattemptlock = '0';
        $scormdata->forcecompleted = '0';
        $scormdata->auto = '0';
        $scormdata->autocommit = '0';
        $scormdata->masteryoverride = '1';
        $scormdata->cmidnumber = '';
        $scormdata->groupmode = '0';
        $scormdata->completion = '1';
        $scormdata->competency_rule = '0';
        $scormdata->name = $this->title;
        $scormdata->add = 'scorm';
        $scormdata->visible = 1;
        $scormdata->availablefrom = 0;
        $scormdata->availableuntil = 0;
        $scormdata->showavailability = 1;
        $scormdata->width = 100;
        $scormdata->height = 100;

        add_moduleinfo($scormdata, $this->course);
    }
}
