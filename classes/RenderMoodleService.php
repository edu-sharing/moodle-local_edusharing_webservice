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

use Exception;
use mod_edusharing\EduSharingService;
use mod_edusharing\UtilityFunctions;

abstract class RenderMoodleService
{
    protected UtilityFunctions $utils;
    protected EduSharingService $eduservice;

    public function __construct() {
        $this->utils = new UtilityFunctions();
        $this->eduservice = new EduSharingService();
    }

    protected function get_existing_course_id(string $nodeid): ?int {
        global $DB;
        try {
            $course = $DB->get_record(table: 'course', conditions: ['idnumber' => $nodeid], strictness: MUST_EXIST);
            return (int)$course->id;
        } catch (Exception) {
            return null;
        }
    }
}
