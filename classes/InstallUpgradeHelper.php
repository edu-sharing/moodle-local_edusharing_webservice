<?php

declare(strict_types=1);

namespace local_edusharing_webservice;

global $CFG;

use coding_exception;
use context_system;
use dml_exception;

require_once $CFG->dirroot."/user/lib.php";
require_once $CFG->dirroot."/admin/lib.php";
require_once $CFG->dirroot."/lib/moodlelib.php";

class InstallUpgradeHelper
{
    /**
     * @throws coding_exception
     * @throws dml_exception
     */
    public function delete_users(): void {
        global $DB;
        $users = $DB->get_records('user', ['deleted' => 0]);

        foreach ($users as $user) {
            error_log("Found user {$user->username}");
            if (is_siteadmin($user) || isguestuser($user) || $user->username == 'es-web') {
                continue;
            }
            error_log("Deleting user {$user->username}");
            delete_user($user);
        }
    }

    /**
     * @throws dml_exception
     */
    public function update_scorm_packages(): void {
        global $DB;
        $scormrecords = $DB->get_records('scorm');
        foreach ($scormrecords as $record) {
            $record->skipview ='2';
            $record->hidetoc = '1';
            $DB->update_record('scorm', $record);
        }
    }

    /**
     * @throws coding_exception
     * @throws dml_exception
     */
    public function create_restricted_role(): void {
        global $DB;
        $systemcontext = context_system::instance();
        $restrictedRoleId = create_role(
            'Restricted Rendering-User',
            'restrictedrenderinguser',
            'A restricted edu-sharing rendering user with minimal access'
        );
        set_role_contextlevels($restrictedRoleId, [CONTEXT_SYSTEM, CONTEXT_COURSE]);
        $role = $DB->get_record('role', ['shortname' => 'user'], '*', MUST_EXIST);
        $rolecaps = role_context_capabilities($role->id, $systemcontext);
        $standardallowedcaps = array_keys(array_filter($rolecaps, fn($permission) => $permission == CAP_ALLOW));
        $whitelist = [
            'moodle/course:view',
            'moodle/block:view',
            'mod/url:view',
            'mod/resource:view',
            'mod/page:view',
            'mod/lesson:view',
            'mod/label:view',
            'mod/choice:view',
            'moodle/blog:view',
        ];
        foreach ($standardallowedcaps as $cap) {
            if (!in_array($cap, $whitelist)) {
                assign_capability($cap, CAP_PROHIBIT, $restrictedRoleId, $systemcontext, true);
            }
        }
    }
}
