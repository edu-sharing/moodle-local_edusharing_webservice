<?php

declare(strict_types=1);

namespace local_edusharing_webservice;

global $CFG;

use coding_exception;
use context_system;
use dml_exception;
use Exception;
use local_edusharing_webservice\task\ProvisionWebserviceTask;

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
     * Only ProvisionWebserviceTask calls this, so it traces to the cron log.
     *
     * @throws coding_exception
     * @throws dml_exception
     */
    public function delete_users(): void {
        global $DB;
        if (empty(getenv('EDUSHARING_RENDER_DOCKER_DEPLOYMENT'))) {
            mtrace('... not a rendering deployment, no user accounts deleted');
            return;
        }
        $protectedids = array_map(
            'intval',
            $DB->get_fieldset_sql('SELECT DISTINCT userid FROM {external_tokens}')
        );
        $serviceusername = (string)getenv('EDUSHARING_WEBSERVICE_USER');
        $users = $DB->get_records('user', ['deleted' => 0]);
        $deleted = 0;

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
            try {
                if (delete_user($user)) {
                    $deleted++;
                }
            } catch (\Throwable $e) {
                // Name the account: delete_user() reaches deep into other
                // components, and their exceptions do not say whose data it was.
                throw new Exception("Deleting user id $user->id failed: " . $e->getMessage(), 0, $e);
            }
        }
        mtrace(sprintf('... deleted %d of %d user accounts', $deleted, count($users)));
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
            'moodle/blog:view'
        ];
        // Capabilities the rendering user needs but the standard user role does not
        // grant, so they have to be allowed explicitly rather than merely left
        // un-prohibited. mod/scorm:skipview lets mod/scorm/view.php send the user
        // straight into the player instead of rendering its "Enter" page: without it
        // a rendered SCORM costs the learner an extra click.
        // Quiz and H5P grant their view capability to the student archetype only.
        // Course pages hide an activity entirely from a user lacking mod/<type>:view,
        // so without these a rendered course shows the section but not its quiz or H5P.
        $allowlist = [
            'mod/scorm:skipview',
            'mod/quiz:view',
            'mod/quiz:attempt',
            'mod/quiz:reviewmyattempts',
            'mod/h5pactivity:view',
            'mod/h5pactivity:submit',
            'mod/hvp:view',
        ];
        foreach ($standardallowedcaps as $cap) {
            if (!in_array($cap, $whitelist, true) && !in_array($cap, $allowlist, true)) {
                assign_capability($cap, CAP_PROHIBIT, $restrictedRoleId, $systemcontext, true);
            }
        }
        foreach ($allowlist as $cap) {
            if (get_capability_info($cap, false) !== null) {
                assign_capability($cap, CAP_ALLOW, $restrictedRoleId, $systemcontext, true);
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
     * Point existing singleactivity courses at the SCORM package they contain.
     *
     * Courses generated while the format's activity type still fell back to its
     * default have no main activity, so /course/view.php stops at the course page
     * instead of redirecting into the SCORM and the learner has to click through.
     *
     * Only courses whose configured activity type is absent from the course are
     * touched, so a course that is deliberately built around some other activity is
     * never repointed.
     *
     * @return int number of courses repaired
     * @throws dml_exception
     */
    public function repair_singleactivity_scorm_courses(): int {
        global $CFG, $DB;
        require_once $CFG->dirroot . '/course/lib.php';
        $sql = "SELECT cfo.id, cfo.courseid, cfo.value
                  FROM {course_format_options} cfo
                  JOIN {course} c ON c.id = cfo.courseid
                 WHERE c.format = 'singleactivity'
                   AND cfo.format = 'singleactivity'
                   AND cfo.sectionid = 0
                   AND cfo.name = 'activitytype'
                   AND cfo.value <> 'scorm'";
        $repaired = 0;
        foreach ($DB->get_records_sql($sql) as $option) {
            $modnames = $this->get_course_modnames((int)$option->courseid);
            if (!in_array('scorm', $modnames, true) || in_array($option->value, $modnames, true)) {
                continue;
            }
            $DB->set_field('course_format_options', 'value', 'scorm', ['id' => $option->id]);
            rebuild_course_cache((int)$option->courseid, true);
            $repaired++;
        }
        return $repaired;
    }

    /**
     * Names of the module types present in a course.
     *
     * @throws dml_exception
     */
    private function get_course_modnames(int $courseid): array {
        global $DB;
        return $DB->get_fieldset_sql(
            "SELECT DISTINCT m.name
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.course = :courseid AND cm.deletioninprogress = 0",
            ['courseid' => $courseid]
        );
    }

    /**
     * Only ProvisionWebserviceTask calls this, so it traces to the cron log.
     *
     * @throws dml_exception
     * @throws coding_exception
     */
    public function create_webservice_role(): int {
        global $DB;
        $systemcontext = context_system::instance();
        $existing = $DB->get_record('role', ['shortname' => 'webserviceuser']);
        if ($existing !== false) {
            // Do not return early: an earlier run that died part way through the loop below
            // leaves the role in place but missing every capability after the failure, and
            // returning here would make that state permanent. Re-assigning is idempotent.
            $id = (int)$existing->id;
            mtrace("... web service role exists, id $id");
        } else {
            $id = create_role('Webservice User', 'webserviceuser', 'This role is used for the webservice user');
            set_role_contextlevels($id, [CONTEXT_SYSTEM]);
            mtrace("... web service role created, id $id");
        }
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
            // Capabilities disappear when core drops a plugin (Atto in Moodle 5.0, for
            // instance). assign_capability() throws a coding_exception on an unknown one,
            // which would abort provisioning and leave the role half built, so skip it.
            // Not with debugging(): a missing capability later surfaces as a
            // permission error in the repository, so it has to show without
            // developer debugging too.
            if (get_capability_info($cap, false) === null) {
                mtrace("... skipping unknown capability '$cap' for the web service role");
                continue;
            }
            assign_capability($cap, CAP_ALLOW, $id, $systemcontext, true);
        }
        return $id;
    }

    /**
     * Only ProvisionWebserviceTask calls this, so it traces to the cron log.
     *
     * @throws Exception
     */
    public function create_webservice_user(int $roleId): void {
        global $DB;
        if (empty(getenv('EDUSHARING_RENDER_DOCKER_DEPLOYMENT'))) {
            mtrace('... not a rendering deployment, no web service user created');
            return;
        }
        $missing = array_filter(
            ['EDUSHARING_WEBSERVICE_USER', 'EDUSHARING_WEBSERVICE_PASSWORD'],
            fn($name) => empty(getenv($name))
        );
        if ($missing !== []) {
            throw new Exception('Edu-Sharing web service user can not be created, credentials not set: '
                . implode(', ', $missing) . ' empty or missing in the environment');
        }
        // Idempotent: this runs from an adhoc task, which Moodle retries on
        // failure, so a second pass must not create a duplicate account.
        $username = (string)getenv('EDUSHARING_WEBSERVICE_USER');
        $existing = $DB->get_record('user', ['username' => $username, 'mnethostid' => 1, 'deleted' => 0]);
        if ($existing !== false) {
            $userId = (int)$existing->id;
            mtrace("... web service user '$username' exists, id $userId");
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
            mtrace("... web service user '$username' created, id $userId");
        }
        $systemcontextid = context_system::instance()->id;
        if (!$DB->record_exists('role_assignments', [
            'roleid' => $roleId, 'userid' => $userId, 'contextid' => $systemcontextid,
        ])) {
            role_assign($roleId, $userId, $systemcontextid);
            mtrace("... web service role assigned to user id $userId");
        }

        $this->enable_web_cron();
    }

    /**
     * Lets the rendering service run cron over the web.
     *
     * The rendering service triggers Moodle cron over the web
     * (/admin/cron.php?password=...). Set the remote cron password to the web
     * service password and lift the CLI-only restriction if it is enabled, so
     * those requests are accepted.
     *
     * Install and upgrade call this directly instead of leaving it to
     * ProvisionWebserviceTask: that task only runs on cron, and without a CLI
     * cron runner the web cron calls it would allow are the only cron there is.
     *
     * @throws dml_exception
     */
    public function enable_web_cron(): void {
        global $CFG;
        if (empty(getenv('EDUSHARING_RENDER_DOCKER_DEPLOYMENT')) || empty(getenv('EDUSHARING_WEBSERVICE_PASSWORD'))) {
            return;
        }
        set_config('cronremotepassword', getenv('EDUSHARING_WEBSERVICE_PASSWORD'));
        if (!empty($CFG->cronclionly)) {
            set_config('cronclionly', 0);
        }
    }

    /**
     * Queues provisioning again if the web service user is missing.
     *
     * Called on every upgrade. The provisioning queued on install gives up once
     * Moodle's adhoc task attempts are used up (e.g. while the password fails the
     * password policy), and nothing else would create the user afterwards.
     *
     * The queued task leaves the site's accounts alone: unlike on install, an
     * upgrade must not wipe them. Skipped while a provisioning task can still run.
     * queue_adhoc_task()'s duplicate check cannot do that, it also matches tasks
     * that have run out of attempts, which Moodle keeps for a while.
     *
     * @throws dml_exception
     */
    public function queue_provisioning_if_user_missing(): void {
        global $DB;
        if (empty(getenv('EDUSHARING_RENDER_DOCKER_DEPLOYMENT')) || empty(getenv('EDUSHARING_WEBSERVICE_USER'))) {
            return;
        }
        $username = (string)getenv('EDUSHARING_WEBSERVICE_USER');
        if ($DB->record_exists('user', ['username' => $username, 'mnethostid' => 1, 'deleted' => 0])) {
            return;
        }
        if ($DB->record_exists_select(
            'task_adhoc',
            'classname = ? AND (attemptsavailable > 0 OR attemptsavailable IS NULL)',
            ['\\' . ProvisionWebserviceTask::class]
        )) {
            return;
        }
        $task = new ProvisionWebserviceTask();
        $task->set_custom_data(['deleteusers' => false]);
        \core\task\manager::queue_adhoc_task($task);
    }
}
