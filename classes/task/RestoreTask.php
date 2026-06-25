<?php

namespace local_edusharing_webservice\task;

use core\task\asynchronous_restore_task;
use Exception;
use local_edusharing_webservice\CourseRestorationService;
use local_edusharing_webservice\RestoreStatus;
use local_edusharing_webservice\UserException;

class RestoreTask extends asynchronous_restore_task {
    private CourseRestorationService $renderMoodleService;

    public function __construct() {
        $this->renderMoodleService = new CourseRestorationService();
    }

    public function get_component(): string {
        return 'local_edusharing_webservice';
    }

    public function execute(): void {
        global $CFG, $DB;
        require_once($CFG->libdir . '/adminlib.php');
        require_once($CFG->libdir . '/setuplib.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        raise_memory_limit(MEMORY_EXTRA);

        try {
            $customData = $this->get_custom_data();
            if (empty($customData->restoreid)) {
                // Without a restoreid there is no edu_restore record to update, so the
                // failure cannot be surfaced to the caller; just abort.
                throw new Exception('Invalid restore custom data provided to restore task: missing restoreid');
            }
            $restore = $DB->get_record(
                table: 'edu_restore',
                conditions: ['id' => $customData->restoreid],
                strictness: MUST_EXIST
            );
        } catch (Exception $exception) {
            mtrace('Invalid restore task state: ' . $exception->getMessage());
            return;
        }

        try {
            // Now that the restore record is loaded, validate the remaining custom data
            // here so any problem is recorded as a failure on the record instead of
            // silently leaving it stuck in the "queued" state (the task would otherwise
            // complete and be removed from the queue, never resolving the status).
            if (empty($customData->title) || empty($customData->category)) {
                throw new UserException(
                    internalMessage: sprintf(
                        'Invalid restore custom data for restoreid %s: title and category are required (title=%s, category=%s)',
                        $customData->restoreid,
                        var_export($customData->title ?? null, true),
                        var_export($customData->category ?? null, true)
                    ),
                    externalMessage: get_string('error_invalid_restore_data', 'local_edusharing_webservice'),
                );
            }
            $restore->status = RestoreStatus::RUNNING;
            $restore->lastmodified = time();
            $DB->update_record('edu_restore', $restore);
            $controller = $this->renderMoodleService->prepareRestore(
                nodeId: $restore->nodeid,
                category: $customData->category,
            );
            // Overwrite custom data for further use in parent class
            $this->set_custom_data(['backupid' => $controller->get_restoreid()]);
            parent::execute();
            $this->renderMoodleService->finalizeCourse(
                courseId: $controller->get_courseid(),
                nodeId: $restore->nodeid,
                title: $customData->title
            );
            $restore->status = RestoreStatus::SUCCESS;
            $restore->lastmodified = time();
            $restore->courseid = $this->renderMoodleService->getCourseId();
            $DB->update_record('edu_restore', $restore);
            $this->renderMoodleService->cleanup();
        } catch (Exception $exception) {
            $restore->status = RestoreStatus::FAILURE;
            $restore->lastmodified = time();
            $restore->message = sprintf(
                "%s: %s\nFile: %s:%d\n\nStack trace:\n%s",
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
                $exception->getTraceAsString()
            );
            if ($exception instanceof UserException) {
                $restore->usermessage = $exception->getExternalMessage();
            }
            try {
                $DB->update_record('edu_restore', $restore);
            } catch (Exception $e) {
                mtrace('Failed to update restore status due to DB error: ' . $e->getMessage());
            }
            $this->renderMoodleService->rollback();
        }
    }
}
