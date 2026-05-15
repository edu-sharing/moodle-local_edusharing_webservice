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
        $this->create_course();
        $this->add_scorm_to_course();
        return $this->course->id;
    }

    /**
     * @throws \moodle_exception
     */
    private function create_course(): void {
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
        $contentUrl = trim($this->utils->get_config_entry('application_cc_gui_url'), '/') . '/content';
        $contentUrl .= '?appId=' . $this->utils->get_config_entry('application_appid');
        $contentUrl .= '&nodeId=' . $this->nodeid;
        $contentUrl .= '&timeStamp=' . $timestamp;
        $contentUrl .= '&authToken=' . $signed;
        $contentUrl .= '&signedAlg=' . $this->eduservice->get_signing_algorithm();
        return $contentUrl;
    }

    /**
     * @throws dml_exception
     * @throws moodle_exception
     */
    private function add_scorm_to_course(): void {
        global $DB;
        set_config('allowtypelocalsync', 1, 'scorm');
        $scorm_module_id = $DB->get_field('modules', 'id', ['name' => 'scorm']);
        $scormdata = new stdClass();
        $scormdata->scormtype = SCORM_TYPE_LOCALSYNC;
        $scormdata->packageurl = $this->get_content_url();
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
