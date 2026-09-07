<?php

namespace local_edusharing_webservice\task;

use core\task\adhoc_task;
use local_edusharing_webservice\InstallUpgradeHelper;
use Throwable;

/**
 * Provisions the edu-sharing web service role and user.
 *
 * This work used to run inline in db/upgrade.php and db/install.php, where it
 * cannot succeed: creating roles and users reaches core code guarded by
 * upgrade_ensure_not_running(), which throws 'Cannot be executed during
 * upgrade' while $CFG->upgraderunning is set.
 *
 * Deferring it to an adhoc task means it runs on the next cron, with the guard
 * no longer active, and Moodle retries it with backoff if it fails instead of
 * the failure being swallowed and recorded as success.
 *
 * @package   local_edusharing_webservice
 * @copyright metaventis 2026 <integrations@edu-sharing.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ProvisionWebserviceTask extends adhoc_task {
    public function get_component(): string {
        return 'local_edusharing_webservice';
    }

    public function execute(): void {
        $helper = new InstallUpgradeHelper();
        try {
            $helper->delete_users();
            $roleId = $helper->create_webservice_role();
            $helper->create_webservice_user($roleId);
        } catch (Throwable $exception) {
            // Trace to the cron log, then rethrow so the task is retried rather
            // than marked complete.
            mtrace(sprintf(
                "edu-sharing web service provisioning failed: %s: %s\nFile: %s:%d\n\nStack trace:\n%s",
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            ));
            throw $exception;
        }
    }
}
