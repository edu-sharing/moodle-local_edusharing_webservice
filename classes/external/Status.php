<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace local_edusharing_webservice\external;

use core\exception\invalid_parameter_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use dml_exception;
use local_edusharing_webservice\CourseRestorationService;

class Status extends external_api {
    /**
     * Function execute_parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'restoreId' => new external_value(
                type: PARAM_INT,
                desc:'Identifier of the restore entry (refers to the edu_restore table)',
                required: VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Function execute_returns
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(
                type: PARAM_TEXT,
                desc: 'Current restore job status',
            ),
            'userMessage' => new external_value(
                type: PARAM_TEXT,
                desc: 'User-friendly public message related to the restore status',
                required: VALUE_OPTIONAL,
                allownull: true
            ),
            'internalMessage' => new external_value(
                type: PARAM_TEXT,
                desc: 'Technical message for developers and administrators',
                required: VALUE_OPTIONAL,
                allownull: true
            ),
            'courseId' => new external_value(
                type: PARAM_INT,
                desc: 'Course ID associated with the restore job',
                required: VALUE_OPTIONAL,
                allownull: true
            )
        ]);
    }

    /**
     * @throws dml_exception
     * @throws invalid_parameter_exception
     */
    public static function execute(int $restoreId): array {
        $service = new CourseRestorationService();
        self::validate_parameters(self::execute_parameters(), [
            'restoreId' => $restoreId,
        ]);
        $status = $service->getStatus($restoreId);
        return [
            'status' => $status->status,
            'userMessage' => $status->userMessage,
            'internalMessage' => $status->internalMessage,
            'courseId' => $status->courseId,
        ];

    }
}
