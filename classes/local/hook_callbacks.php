<?php
// This file is part of Moodle - http://moodle.org/
//
// This program is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

declare(strict_types=1);

namespace local_edusharing_webservice\local;

use core\hook\output\before_http_headers;

/**
 * Class hook_callbacks
 *
 * @package    local_edusharing_webservice
 * @copyright  metaVentis GmbH — http://metaventis.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Function before_http_headers
     *
     * Marks every page with the body class the rules in styles.css are scoped to, if the
     * hidenavigation setting is enabled. The rules are always part of the theme CSS, so toggling
     * the setting needs no theme cache purge.
     *
     * Skipped during the initial install, whose pages are printed before the config table exists.
     *
     * @param before_http_headers $hook
     * @return void
     */
    public static function before_http_headers(before_http_headers $hook): void {
        if (during_initial_install()) {
            return;
        }
        if (get_config('local_edusharing_webservice', 'hidenavigation')) {
            $hook->renderer->get_page()->add_body_class('local-edusharing-webservice-hidenavigation');
        }
    }
}
