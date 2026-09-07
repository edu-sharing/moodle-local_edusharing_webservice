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
     * Deletes the site's user accounts.
     *
     * Gated on EDUSHARING_RENDER_DOCKER_DEPLOYMENT exactly like
     * create_webservice_user(): wiping every account is only ever meaningful in
     * a throwaway rendering container, where users are re-provisioned at
     * runtime from the repository. Without the guard this runs on any site that
     * upgrades the plugin.
     *
     * Accounts holding a web service token are skipped. Core's delete_user()
     * deletes the user's external_tokens rows as a side effect, and this plugin
     * never recreates them (tokens are issued out of band), so deleting a token
     * holder silently severs the repository's connection to this Moodle.
     *
     * @throws coding_exception
     * @throws dml_exception
     */
    public function delete_users(): void {
        global $DB;
        if (empty(getenv('EDUSHARING_RENDER_DOCKER_DEPLOYMENT'))) {
            return;
        }
        $protectedids = array_map(
            'intval',
            $DB->get_fieldset_sql('SELECT DISTINCT userid FROM {external_tokens}')
        );
        $serviceusername = (string)getenv('EDUSHARING_WEBSERVICE_USER');
        $users = $DB->get_records('user', ['deleted' => 0]);

        foreach ($users as $user) {
            if (is_siteadmin($user) || isguestuser($user)) {
                continue;
            }
            if (in_array((int)$user->id, $protectedids, true)) {
                continue;
            }
            if ($serviceusername !== '' && $user->username === $serviceusername) {
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
     * Creates the restricted rendering role and (re-)applies its capabilities.
     *
     * Always runs the capability pass, also when the role already exists: the
     * whitelist changes between plugin versions, so an upgrade has to prohibit
     * newly added standard capabilities and lift prohibits on capabilities that
     * have since been whitelisted.
     *
     * @throws coding_exception
     * @throws dml_exception
     */
    public function create_restricted_role(): void {
        global $DB;
        $systemcontext = context_system::instance();
        $existing = $DB->get_record('role', ['shortname' => 'restrictedrenderinguser']);
        if ($existing !== false) {
            $restrictedRoleId = (int)$existing->id;
        } else {
            $restrictedRoleId = create_role(
                'Restricted Rendering-User',
                'restrictedrenderinguser',
                'A restricted edu-sharing rendering user with minimal access'
            );
            set_role_contextlevels($restrictedRoleId, [CONTEXT_SYSTEM, CONTEXT_COURSE]);
        }
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
            if (!in_array($cap, $whitelist, true)) {
                assign_capability($cap, CAP_PROHIBIT, $restrictedRoleId, $systemcontext, true);
            }
        }
        // Drop prohibits left over from a previous version of the whitelist, so
        // the standard user role's permission applies to them again.
        $staleprohibits = $DB->get_fieldset_select(
            'role_capabilities',
            'capability',
            'roleid = :roleid AND contextid = :contextid AND permission = :permission',
            ['roleid' => $restrictedRoleId, 'contextid' => $systemcontext->id, 'permission' => CAP_PROHIBIT]
        );
        foreach (array_intersect($staleprohibits, $whitelist) as $cap) {
            if (get_capability_info($cap, false) !== null) {
                unassign_capability($cap, $restrictedRoleId, $systemcontext->id, false);
            }
        }
    }

    /**
     * @throws dml_exception
     * @throws coding_exception
     */
    public function create_webservice_role(): int {
        global $DB;
        $systemcontext = context_system::instance();
        $existing = $DB->get_record('role', ['shortname' => 'webserviceuser']);
        if ($existing !== false) {
            return (int)$existing->id;
        }
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
        global $CFG, $DB;
        if (empty(getenv('EDUSHARING_RENDER_DOCKER_DEPLOYMENT'))) {
            return;
        }
        if (empty(getenv('EDUSHARING_WEBSERVICE_USER')) || empty(getenv('EDUSHARING_WEBSERVICE_PASSWORD'))) {
            throw new Exception('Edu-Sharing web service user can not be created, credentials not set');
        }
        // Idempotent: this runs from an adhoc task, which Moodle retries on
        // failure, so a second pass must not create a duplicate account.
        $username = (string)getenv('EDUSHARING_WEBSERVICE_USER');
        $existing = $DB->get_record('user', ['username' => $username, 'mnethostid' => 1, 'deleted' => 0]);
        if ($existing !== false) {
            $userId = (int)$existing->id;
        } else {
            $userArray = [
                'createpassword' => false,
                'username' => $username,
                'password' => (getenv('EDUSHARING_WEBSERVICE_PASSWORD')),
                'firstname' => 'Rudi',
                'lastname' => 'Renderer',
                'email' => 'integrations@edu-sharing.net',
                'confirmed' => 1,
                'mnethostid' => 1
            ];
            $userId = user_create_user($userArray);
        }
        $systemcontextid = context_system::instance()->id;
        if (!$DB->record_exists('role_assignments', [
            'roleid' => $roleId, 'userid' => $userId, 'contextid' => $systemcontextid,
        ])) {
            role_assign($roleId, $userId, $systemcontextid);
        }

        // The rendering service triggers Moodle cron over the web
        // (/admin/cron.php?password=...). Set the remote cron password to the web
        // service password and lift the CLI-only restriction if it is enabled, so
        // those requests are accepted.
        set_config('cronremotepassword', getenv('EDUSHARING_WEBSERVICE_PASSWORD'));
        if (!empty($CFG->cronclionly)) {
            set_config('cronclionly', 0);
        }
    }
}
