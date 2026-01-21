<?php

declare(strict_types=1);

namespace local_edusharing_webservice;

global $CFG;

use coding_exception;
use context_system;
use dml_exception;
use Exception;

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
            if (is_siteadmin($user) || isguestuser($user)) {
                continue;
            }
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
            'mod/h5pactivity:view',
            'mod/hvp:view'
        ];
        foreach ($standardallowedcaps as $cap) {
            if (!in_array($cap, $whitelist)) {
                assign_capability($cap, CAP_PROHIBIT, $restrictedRoleId, $systemcontext, true);
            }
        }
    }

    /**
     * @throws dml_exception
     * @throws coding_exception
     */
    public function create_webservice_role(): int {
        $systemcontext = context_system::instance();
        $id = create_role('Webservice User', 'webserviceuser', 'This role is used for the webservice user');
        set_role_contextlevels($id, [CONTEXT_SYSTEM]);
        $caps = [
            'moodle/restore:createuser',
            'contenttype/h5p:upload',
            'contenttype/h5p:useeditor',
            'mod/h5pactivity:addinstance',
            'mod/scorm:addinstance',
            'moodle/course:ignorefilesizelimits',
            'moodle/restore:configure',
            'moodle/restore:restoreactivity',
            'moodle/restore:restorecourse',
            'moodle/restore:restoresection',
            'moodle/restore:restoretargetimport',
            'moodle/restore:rolldates',
            'moodle/restore:uploadfile',
            'moodle/restore:userinfo',
            'moodle/restore:viewautomatedfilearea',
            'webservice/rest:use',
            'atto/h5p:addembed',
            'mod/h5pactivity:reviewattempts',
            'mod/h5pactivity:submit',
            'mod/h5pactivity:view',
            'moodle/h5p:deploy',
            'moodle/h5p:setdisplayoptions',
            'moodle/h5p:updatelibraries',
            'tiny/h5p:addembed',
            'moodle/webservice:createtoken'
        ];
        foreach ($caps as $cap) {
            assign_capability($cap, CAP_ALLOW, $id, $systemcontext, true);
        }
        return $id;
    }

    /**
     * @throws Exception
     */
    public function create_webservice_user(int $roleId): void {
        if (empty(getenv('EDUSHARING_RENDER_DOCKER_DEPLOYMENT'))) {
            return;
        }
        if (empty(getenv('EDUSHARING_WEBSERVICE_USER')) || empty(getenv('EDUSHARING_WEBSERVICE_PASSWORD'))) {
            throw new Exception('Edu-Sharing web service user can not be created, credentials not set');
        }
        $userArray = [
            'createpassword' => false,
            'username' => (getenv('EDUSHARING_WEBSERVICE_USER')),
            'password' => (getenv('EDUSHARING_WEBSERVICE_PASSWORD')),
            'firstname' => 'Rudi',
            'lastname' => 'Renderer',
            'email' => 'integrations@edu-sharing.net',
            'confirmed' => 1,
            'mnethostid' => 1
        ];
        $userId = user_create_user($userArray);
        role_assign($roleId, $userId, context_system::instance()->id);
    }
}
