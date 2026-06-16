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

use context_course;
use context_system;
use core\exception\coding_exception;
use dml_exception;
use mod_edusharing\UtilityFunctions;
use moodle_exception;
use stdClass;

class UserService {
    private int $restrictedroleid;
    private UtilityFunctions $utils;

    /**
     * @throws dml_exception
     */
    public function __construct() {
        global $DB;
        $this->utils = new UtilityFunctions();
        $this->restrictedroleid = (int)$DB->get_field(table:'role', return: 'id', conditions: ['shortname' => 'restrictedrenderinguser']);
    }

    /**
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function get_token(UserDataDTO $user, int $courseid): string {
        $userid = $this->get_or_create_user(userData: $user);
        $this->enrol_user(userid: $userid, courseid: $courseid);
        return $this->generate_token(userid: $userid, courseid: $courseid);
    }

    /**
     * @throws coding_exception
     * @throws dml_exception
     */
    private function get_or_create_user(UserDataDTO $userData): int {
        global $DB;
        $user = $DB->get_record(table: "user", conditions: ["username" => $userData->username]);
        if(empty($user)) {
            $user = create_user_record($user->username, uniqid());
            $user->firstname = $userData->firstname;
            $user->lastname = $userData->lastname;
            $user->email = $userData->email;
            $DB->update_record(table: 'user', dataobject: $user);
            role_assign(roleid: $this->restrictedroleid, userid: $user->id, contextid: context_system::instance()->id);
        }
        return (int)$user->id;
    }

    /**
     * @throws moodle_exception
     *
     * Users are always enroled as restricted rendering users.
     * In the legacy plugin there was an unused option to provide
     * other roles (editingteacher) which has been removed.
     */
    private function enrol_user(int $userid, int $courseid): void {
        $context = context_course::instance($courseid);
        if (!is_enrolled(context: $context, user: $userid)) {
            if (!enrol_try_internal_enrol(courseid: $courseid, userid: $userid, roleid: $this->restrictedroleid, timestart: time())) {
                throw new moodle_exception('unabletoenrolerrormessage', 'langsourcefile');
            }
        }
    }

    /**
     * @throws dml_exception
     */
    private function generate_token(int $userid, int $courseid): string {
        global $DB;

        $hash = new stdClass;
        $hash->userid = $userid;
        $hash->courseid = $courseid;
        $hash->ts = time();
        $hash->uniqid = uniqid();
        $DB->insert_record('edusharingtoken', $hash);
        $hash = json_encode($hash);
        return base64_encode($this->encrypt($hash));
    }

    /**
     * @throws dml_exception
     */
    private function encrypt(string $data): string {
        $privatekey = openssl_get_privatekey($this->utils->get_config_entry('application_private_key'));
        openssl_private_encrypt($data,$encrypted,$privatekey);
        return $encrypted;
    }
}
