<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once $CFG->dirroot."/user/lib.php";
require_once $CFG->dirroot."/admin/lib.php";
require_once $CFG->dirroot."/lib/moodlelib.php";

function xmldb_local_edusharing_webservice_install(){
    global $DB;
    $dbFamily = $DB->get_dbfamily();
    if($dbFamily === 'mysql') {
        $query = 'ALTER TABLE ' . $DB->get_prefix() . 'scorm MODIFY COLUMN reference VARCHAR(1000)';
        $DB->execute($query);
        $query = 'ALTER TABLE ' . $DB->get_prefix() . 'files MODIFY COLUMN filename VARCHAR(1000)';
        $DB->execute($query);
    } else if($dbFamily === 'postgres') {
        $query = 'ALTER TABLE ' . $DB->get_prefix() . 'scorm ALTER COLUMN reference TYPE varchar(1000)';
        $DB->execute($query);
        $query = 'ALTER TABLE ' . $DB->get_prefix() . 'files ALTER COLUMN filename TYPE  varchar(1000)';
        $DB->execute($query);
    }

    set_config('enablewebservices', 1);
    set_config('webserviceprotocols', 'rest');
    set_config('allowframembedding', 1);
    set_config('format_singleactivity', 'scorm', 'activitytype');

    $systemcontext = context_system::instance();
    try {
        $scormrecords = $DB->get_records('scorm');
        foreach ($scormrecords as $record) {
            $record->skipview ='2';
            $record->hidetoc = '1';
            $DB->update_record('scorm', $record);
        }
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
        $users = $DB->get_records('user', ['deleted' => 0]);

        foreach ($users as $user) {
            if (is_siteadmin($user) || isguestuser($user)) {
                continue;
            }
            // Get all system context role assignments for this user
            $systemroles = $DB->get_records('role_assignments', [
                'userid' => $user->id,
                'contextid' => $systemcontext->id
            ]);

            if (count($systemroles) === 0) {
                role_assign($restrictedRoleId, $user->id, $systemcontext);
            }
        }
    } catch (exception $e) {
        error_log($e->getMessage());
    }

    if (! empty(getenv('EDUSHARING_RENDER_DOCKER_DEPLOYMENT'))) {
        if (empty(getenv('EDUSHARING_WEBSERVICE_USER')) || empty(getenv('EDUSHARING_WEBSERVICE_PASSWORD'))) {
            mtrace('Edu-Sharing web service docker deployment failed: No webservice user and/or password provided');
            return true;
        }

        // Create user
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
        try {
            $userId = user_create_user($userArray);

            // Get manager role
            $managerrole = $DB->get_record('role', ['shortname' => 'manager'], '*', MUST_EXIST);
            $systemcontext = context_system::instance();

            // Assign missing capability to manager role
            assign_capability('webservice/rest:use', CAP_ALLOW, $managerrole->id, $systemcontext->id, true);

            // Assign manager role to created user
            role_assign($managerrole->id, $userId, $systemcontext);
            // Add css
            set_config('additionalhtmlhead', '<link rel="stylesheet" href="/local/edusharing_webservice/styles.css">');
        } catch (Exception $exception) {
            mtrace('Web service user creation failed.');
            mtrace_exception($exception);
        }
    }
    return true;
}
