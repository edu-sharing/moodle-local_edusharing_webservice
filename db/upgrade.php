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
    $dbman  = $DB->get_manager();
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

    if ($oldversion < 2026071700) {
        // Define table edu_restore to be created (added to install.xml only,
        // so existing installations never received it).
        $table = new xmldb_table('edu_restore');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('nodeid', XMLDB_TYPE_CHAR, '80', null, XMLDB_NOTNULL, null, null);
        $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'queued');
        $table->add_field('message', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('usermessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
        $table->add_field('lastmodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_plugin_savepoint(true, 2026071700, 'local', 'edusharing_webservice');
    }

    return true;
}
