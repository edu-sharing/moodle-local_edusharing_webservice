<?php

namespace local_edusharing_webservice\task;

use Exception;
use local_edusharing_webservice\RenderMoodleService;
use local_edusharing_webservice\RestoreStatus;

class RestoreTask extends \core\task\asynchronous_restore_task
{
    private RenderMoodleService $renderMoodleService;

    public function __construct() {
        $this->renderMoodleService = new RenderMoodleService();
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
            if (empty($customData['restoreid']) || empty($customData['category']) || empty($customData['title'])) {
                throw new Exception('Invalid restore custom data provided to restore task');
            }
            $restore = $DB->get_record(
                table: 'edu_restore',
                conditions: ['id' => $customData['restoreid']],
                strictness: MUST_EXIST
            );
        } catch (Exception $exception) {
            mtrace('Invalid restore task state: ' . $exception->getMessage());
            return;
        }

        try {
            $restore->status = RestoreStatus::RUNNING;
            $DB->update_record('edu_restore', $restore);
            $controller = $this->renderMoodleService->prepareRestore(
                nodeId: $restore->nodeid,
                category: $restore->category,
            );
            // Overwrite custom data for further use in parent class
            $this->set_custom_data(['backupid' => $controller->get_restoreid()]);
            parent::execute();
            $this->renderMoodleService->finalizeCourse(
                courseId: $controller->get_courseid(),
                nodeId: $restore->nodeid,
                title: $customData['title']
            );
            $restore->status = RestoreStatus::SUCCESS;
            $DB->update_record('edu_restore', $restore);
        } catch (Exception $exception) {
            $restore->status = RestoreStatus::FAILURE;
            $restore->message = $exception->getMessage();
            try {
                $DB->update_record('edu_restore', $restore);
            } catch (Exception $e) {
                mtrace('Failed to update restore status due to DB error: ' . $e->getMessage());
            }
        }
    }
}
