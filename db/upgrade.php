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

/**
 * Upgrade steps for the local_edusharing_webservice plugin.
 *
 * @package   local_edusharing_webservice
 * @copyright metaventis 2025 <integrations@edu-sharing.net>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

function xmldb_local_edusharing_webservice_upgrade($oldversion) {
    global $DB;
    if ($oldversion < 2025080800) {
        set_config('allowframembedding', 1);
        set_config('format_singleactivity', 'scorm', 'activitytype');

        $systemcontext = context_system::instance();
        try {
            $scormrecords = $DB->get_records('scorm');
            foreach ($scormrecords as $record) {
                $record->skipview ='2';
                $record->hidetoc = '1';
                $DB->update_record('scorm', $record);
            }
            $role = $DB->get_record('role', ['shortname' => 'user'], '*', MUST_EXIST);
            $rolecaps = role_context_capabilities($role->id, $systemcontext);
            $standardallowedcaps = array_keys(array_filter($rolecaps, fn($permission) => $permission === CAP_ALLOW));
            $whitelist = [
                'moodle/course:view',
            ];
            $restrictedRoleId = create_role(
                'Restricted Rendering-User',
                'restrictedrenderinguser',
                'A restricted edu-sharing rendering user with minimal access'
            );
            set_role_contextlevels($restrictedRoleId, [CONTEXT_SYSTEM, CONTEXT_COURSE]);
            foreach ($standardallowedcaps as $cap) {
                if (!in_array($cap, $whitelist)) {
                    assign_capability($cap, CAP_PROHIBIT, $restrictedRoleId, $systemcontext, true);
                }
            }
            $users = $DB->get_records('user', ['deleted' => 0]);

            foreach ($users as $user) {
                if (is_siteadmin($user) || isguestuser($user)) {
                    continue;
                }
                // Get all system context role assignments for this user
                $systemroles = $DB->get_records('role_assignments', [
                    'userid' => $user->id,
                    'contextid' => $systemcontext->id
                ]);

                if (count($systemroles) === 0) {
                    role_assign($restrictedRoleId, $user->id, $systemcontext);
                }
            }
        } catch (exception $e) {
            error_log($e->getMessage());
        }

        upgrade_plugin_savepoint(true, 2025080800, 'local', 'edusharing_webservice');
    }

    return true;
}
