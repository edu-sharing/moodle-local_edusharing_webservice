<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once $CFG->dirroot."/user/lib.php";
require_once $CFG->dirroot."/admin/lib.php";
require_once $CFG->dirroot."/lib/moodlelib.php";

function xmldb_local_edusharing_webservice_install(){
    global $DB, $CFG;
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

    if (! empty(getenv('EDUSHARING_RENDER_DOCKER_DEPLOYMENT'))) {

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
    }

    return true;
}
