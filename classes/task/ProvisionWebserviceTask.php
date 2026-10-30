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
 * Custom data {deleteusers: false} skips deleting the site's accounts first; see
 * InstallUpgradeHelper::queue_provisioning_if_user_missing().
 *
 * Everything is traced with mtrace(). Moodle keeps the output of every failed
 * run in the task log (Site administration > Server > Tasks > Task logs), also
 * when cron is triggered over the web and nobody reads the response.
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
        $deleteusers = $this->get_custom_data()->deleteusers ?? true;
        mtrace(sprintf(
            'edu-sharing web service provisioning: task id %s, %d attempt(s) available, deleteusers %s; %s',
            $this->get_id() ?? 'none',
            $this->get_attempts_available(),
            $deleteusers ? 'yes' : 'no',
            $this->describe_environment()
        ));
        $step = 'starting';
        try {
            if ($deleteusers) {
                $step = 'deleting the site user accounts';
                mtrace("... $step");
                $helper->delete_users();
            }
            $step = 'creating the web service role';
            mtrace("... $step");
            $roleId = $helper->create_webservice_role();
            $step = "creating the web service user (role id $roleId)";
            mtrace("... $step");
            $helper->create_webservice_user($roleId);
        } catch (Throwable $exception) {
            // Core follows this with the exception's message, debug info and
            // backtrace. Add what it leaves out: the step that failed, whether
            // Moodle will try again, and the exceptions this one wraps. Rethrow
            // so the task is retried rather than marked complete.
            mtrace(sprintf(
                "edu-sharing web service provisioning failed while %s: %s: %s\nThrown at %s:%d\n%s%s",
                $step,
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $this->describe_retry(),
                $this->describe_causes($exception)
            ));
            throw $exception;
        }
        mtrace('edu-sharing web service provisioning complete');
    }

    /**
     * The settings provisioning depends on. The password is reported as set or
     * not, never its value.
     */
    private function describe_environment(): string {
        $username = (string)getenv('EDUSHARING_WEBSERVICE_USER');
        return sprintf(
            'EDUSHARING_RENDER_DOCKER_DEPLOYMENT %s, EDUSHARING_WEBSERVICE_USER %s, EDUSHARING_WEBSERVICE_PASSWORD %s',
            empty(getenv('EDUSHARING_RENDER_DOCKER_DEPLOYMENT')) ? 'not set' : 'set',
            $username === '' ? 'not set' : "'$username'",
            empty(getenv('EDUSHARING_WEBSERVICE_PASSWORD')) ? 'not set' : 'set'
        );
    }

    /**
     * What happens after this failure. adhoc_task_failed() takes one attempt
     * off after execute() throws, and a task with none left is not run again.
     */
    private function describe_retry(): string {
        $remaining = $this->get_attempts_available() - 1;
        $rerun = $this->get_id() === null
            ? ''
            : sprintf(' Run it by hand once fixed: php admin/cli/adhoc_task.php --id=%d', $this->get_id());
        if ($remaining > 0) {
            return "Moodle retries it with backoff, $remaining attempt(s) left.$rerun";
        }
        return 'No attempts left, Moodle will not retry it. The next plugin upgrade queues provisioning again'
            . " if the web service user is still missing.$rerun";
    }

    /**
     * The exceptions wrapped by $exception, innermost last. Core only reports
     * the outermost one, but a wrapper's message rarely names the actual cause.
     */
    private function describe_causes(Throwable $exception): string {
        $lines = [];
        for ($cause = $exception->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $lines[] = sprintf("\nCaused by %s: %s", get_class($cause), $cause->getMessage());
            if (!empty($cause->debuginfo)) {
                $lines[] = "Debug info: $cause->debuginfo";
            }
            $lines[] = sprintf('Thrown at %s:%d', $cause->getFile(), $cause->getLine());
            // format_backtrace() leaves out call arguments, so the web service
            // password cannot end up in the log through a cause's trace.
            $lines[] = format_backtrace($cause->getTrace(), true);
        }
        return implode("\n", $lines);
    }
}
