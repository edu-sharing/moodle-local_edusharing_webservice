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
use core\exception\moodle_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use dml_exception;
use local_edusharing_webservice\ScormRestorationService;

class Scorm extends external_api {
    /**
     * Function execute_parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'nodeId' => new external_value(
                type: PARAM_TEXT,
                desc:'Identifier of the node',
                required: VALUE_REQUIRED
            ),
            'title' => new external_value(
                type: PARAM_TEXT,
                desc: 'Title of the content',
                required: VALUE_REQUIRED
            ),
            'category' => new external_value(
                type: PARAM_INT,
                desc: 'Category id',
                required: VALUE_DEFAULT,
                default: 1
            ),
        ]);
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'courseId' => new external_value(
                type: PARAM_INT,
                desc: 'Course ID',
                required: VALUE_REQUIRED,
            ),
        ]);
    }

    /**
     * @throws dml_exception
     * @throws invalid_parameter_exception
     * @throws moodle_exception
     */
    public static function execute(string $nodeId, string $title, int $category): array {
        self::validate_parameters(self::execute_parameters(), [
            'nodeId' => $nodeId,
            'title' => $title,
            'category' => $category
        ]);

        $service = new ScormRestorationService(
            categoryid: $category,
            title: $title,
            nodeid: $nodeId
        );

        $result = $service->scorm();
        return [
            'courseId' => $result,
        ];
    }

}
