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

use core\exception\coding_exception;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_edusharing_webservice\UserDataDTO;
use local_edusharing_webservice\UserService;

class User extends external_api {

    /**
     * Function execute_parameters
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'userName' => new external_value(
                type: PARAM_TEXT,
                desc:'User name',
                required: VALUE_REQUIRED
            ),
            'firstName' => new external_value(
                type: PARAM_TEXT,
                desc: 'User first name',
                required: VALUE_REQUIRED
            ),
            'lastName' => new external_value(
                type: PARAM_TEXT,
                desc: 'User last name',
                required: VALUE_REQUIRED,
            ),
            'email' => new external_value(
                type: PARAM_EMAIL,
                desc: 'User email',
                required: VALUE_REQUIRED,
            ),
            'courseId' => new external_value(
                type: PARAM_INT,
                desc: 'Course id',
                required: VALUE_REQUIRED,
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
            'token' => new external_value(
                type: PARAM_TEXT,
                desc: 'User token',
            ),
        ]);
    }

    /**
     * @param string $userName
     * @param string $firstName
     * @param string $lastName
     * @param string $email
     * @param int $courseId
     * @return array
     * @throws coding_exception
     * @throws \dml_exception
     * @throws \invalid_parameter_exception
     * @throws \moodle_exception
     */
    public static function execute(
        string $userName,
        string $firstName,
        string $lastName,
        string $email,
        int $courseId
    ): array {
        self::validate_parameters(self::execute_parameters(), [
            'userName' => $userName,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'email' => $email,
            'courseId' => $courseId,
        ]);
        $service = new UserService();
        $token = $service->get_token(
            user: new UserDataDTO($userName, $email, $firstName, $lastName),
            courseid: $courseId
        );
        return [
            'token' => $token,
        ];
    }
}
