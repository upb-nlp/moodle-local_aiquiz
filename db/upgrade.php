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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Upgrade steps for the AI quiz generator plugin.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin database schema.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_aiquiz_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026071900) {
        $table = new xmldb_table('local_aiquiz_jobs');

        if (!$dbman->table_exists($table)) {
            // Sites installed before the jobs table existed in install.xml
            // never got it (install.xml only runs on a fresh install), so
            // create it here with the current schema.
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('requestid', XMLDB_TYPE_CHAR, '36', null, XMLDB_NOTNULL, null, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('quizid', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'pending');
            $table->add_field('published', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timepublished', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
            $table->add_field('errormessage', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('params', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
            $table->add_key('userid', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

            $table->add_index('requestid', XMLDB_INDEX_UNIQUE, ['requestid']);
            $table->add_index('status', XMLDB_INDEX_NOTUNIQUE, ['status']);

            $dbman->create_table($table);
        } else {
            // The table exists with the original fields; add the new ones.

            // Whether the teacher has approved the quiz and made it visible.
            $field = new xmldb_field(
                'published',
                XMLDB_TYPE_INTEGER,
                '1',
                null,
                XMLDB_NOTNULL,
                null,
                '0',
                'status'
            );
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }

            $field = new xmldb_field(
                'timepublished',
                XMLDB_TYPE_INTEGER,
                '10',
                null,
                null,
                null,
                null,
                'published'
            );
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }

            // The generation settings submitted on the form, as JSON.
            $field = new xmldb_field(
                'params',
                XMLDB_TYPE_TEXT,
                null,
                null,
                null,
                null,
                null,
                'errormessage'
            );
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
        }

        upgrade_plugin_savepoint(true, 2026071900, 'local', 'aiquiz');
    }

    if ($oldversion < 2026080600) {
        // The generation service assigns its own job id, returned when the job
        // is created. Keeping it lets a Moodle job be traced in the service's
        // logs; the callback is still matched on our own requestid.
        $table = new xmldb_table('local_aiquiz_jobs');
        $field = new xmldb_field(
            'remotejobid',
            XMLDB_TYPE_CHAR,
            '64',
            null,
            null,
            null,
            null,
            'requestid'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026080600, 'local', 'aiquiz');
    }

    return true;
}
