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

use local_edusharing_webservice\InstallUpgradeHelper;

/**
 * Upgrade steps for the local_edusharing_webservice plugin.
 *
 * @package   local_edusharing_webservice
 * @copyright metaventis 2025 <integrations@edu-sharing.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_local_edusharing_webservice_upgrade($oldversion) {
    global $DB;
    $helper = new InstallUpgradeHelper();
    if ($oldversion < 2025080800) {
        set_config('allowframembedding', 1);
        set_config('format_singleactivity', 'scorm', 'activitytype');

        try {
            $helper->update_scorm_packages();
            $helper->create_restricted_role();
        } catch (exception $e) {
            error_log($e->getMessage());
        }

        try {
            $helper->delete_users();
            $webserviceroleid = $helper->create_webservice_role();
            $helper->create_webservice_user($webserviceroleid);
        } catch (exception $e) {
            error_log($e->getMessage());
        }

        upgrade_plugin_savepoint(true, 2025080800, 'local', 'edusharing_webservice');
    }

    return true;
}
