<?php

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once $CFG->dirroot."/user/lib.php";
require_once $CFG->dirroot."/admin/lib.php";
require_once $CFG->dirroot."/lib/moodlelib.php";

use local_edusharing_webservice\InstallUpgradeHelper;
use local_edusharing_webservice\task\ProvisionWebserviceTask;

function xmldb_local_edusharing_webservice_install(){
    global $DB;
    $dbFamily = $DB->get_dbfamily();
    $helper = new InstallUpgradeHelper();
    if($dbFamily === 'mysql') {
        $query = 'ALTER TABLE ' . $DB->get_prefix() . 'scorm MODIFY COLUMN reference VARCHAR(1000)';
        $DB->execute($query);
        $query = 'ALTER TABLE ' . $DB->get_prefix() . 'files MODIFY COLUMN filename VARCHAR(256)';
        $DB->execute($query);
    } else if($dbFamily === 'postgres') {
        $query = 'ALTER TABLE ' . $DB->get_prefix() . 'scorm ALTER COLUMN reference TYPE varchar(1000)';
        $DB->execute($query);
        $query = 'ALTER TABLE ' . $DB->get_prefix() . 'files ALTER COLUMN filename TYPE  varchar(256)';
        $DB->execute($query);
    }

    set_config('enablewebservices', 1);
    set_config('webserviceprotocols', 'rest');
    set_config('allowframembedding', 1);
    set_config('format_singleactivity', 'scorm', 'activitytype');

    try {
        $helper->update_scorm_packages();
        $helper->create_restricted_role();
    } catch (Throwable $e) {
        error_log(sprintf(
            "local_edusharing_webservice install failed: %s: %s\nFile: %s:%d\n\nStack trace:\n%s",
            get_class($e), $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()
        ));
        throw $e;
    }

    // Role and user provisioning cannot run inside install/upgrade; see
    // ProvisionWebserviceTask. Queue it for the next cron run instead.
    \core\task\manager::queue_adhoc_task(new ProvisionWebserviceTask(), true);

    return true;
}
