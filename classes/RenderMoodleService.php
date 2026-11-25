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

use dml_exception;
use mod_edusharing\EduSharingService;
use mod_edusharing\UtilityFunctions;
use stdClass;

abstract class RenderMoodleService
{
    protected UtilityFunctions $utils;
    protected EduSharingService $eduservice;

    public function __construct() {
        $this->utils = new UtilityFunctions();
        $this->eduservice = new EduSharingService();
    }

    /**
     * The course idnumber a node version is stored under: "<nodeId>_<hash>", or the bare
     * node id when the caller sends no hash (rendering services older than course versioning).
     */
    protected static function course_idnumber(string $nodeid, string $hash): string {
        return $hash === '' ? $nodeid : $nodeid . '_' . $hash;
    }

    /**
     * Find the course restored for a node version.
     *
     * With a hash, the course of exactly that version is looked up. If there is none, a course
     * restored before versioning existed (idnumber = bare node id) is adopted: its idnumber gets
     * the hash, so existing courses are not imported again. The adopted course may stem from an
     * older version of the node - the accepted trade-off against re-importing every course.
     *
     * Without a hash (an older rendering service), the legacy course is looked up first, then
     * the newest versioned course of the node, so courses adopted earlier are still found and
     * nothing is imported again after a rollback of the rendering service.
     *
     * @throws dml_exception
     */
    protected function find_course(string $nodeid, string $hash): ?stdClass {
        global $DB;
        if ($hash !== '') {
            $idnumber = self::course_idnumber($nodeid, $hash);
            $course = $this->get_course_by_idnumber($idnumber);
            if ($course !== null) {
                return $course;
            }
            $legacy = $this->get_course_by_idnumber($nodeid);
            if ($legacy === null) {
                return null;
            }
            $DB->set_field(table: 'course', newfield: 'idnumber', newvalue: $idnumber, conditions: ['id' => $legacy->id]);
            $legacy->idnumber = $idnumber;
            return $legacy;
        }

        $course = $this->get_course_by_idnumber($nodeid);
        if ($course !== null) {
            return $course;
        }
        // "_" is a LIKE wildcard, hence the escaping of the prefix.
        $courses = $DB->get_records_select(
            table: 'course',
            select: $DB->sql_like('idnumber', ':prefix'),
            params: ['prefix' => $DB->sql_like_escape($nodeid . '_') . '%'],
            sort: 'id DESC',
            limitfrom: 0,
            limitnum: 1
        );
        return reset($courses) ?: null;
    }

    /**
     * The newest course with the given idnumber. Moodle does not enforce unique idnumbers on
     * the database level, so get_record() is avoided: it warns when several rows match.
     *
     * @throws dml_exception
     */
    private function get_course_by_idnumber(string $idnumber): ?stdClass {
        global $DB;
        $courses = $DB->get_records(
            table: 'course',
            conditions: ['idnumber' => $idnumber],
            sort: 'id DESC',
            limitfrom: 0,
            limitnum: 1
        );
        return reset($courses) ?: null;
    }
}
