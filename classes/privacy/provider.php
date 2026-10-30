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
 * Privacy API implementation for the AI quiz generator plugin.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\metadata\provider as metadata_provider;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\core_userlist_provider;
use core_privacy\local\request\plugin\provider as request_provider;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Describes and handles the personal data the plugin keeps and sends out.
 *
 * The only personal data stored is the generation job row, which records the
 * teacher who asked for it. Each job lives in a course, so the course context
 * is where it is found, exported and deleted.
 *
 * Deleting a job removes the row only. The quiz it produced is ordinary
 * course content and stays in the course, the same as a quiz the teacher
 * built by hand.
 */
class provider implements core_userlist_provider, metadata_provider, request_provider {
    /**
     * Describe the data stored locally and sent to the generation service.
     *
     * @param collection $collection The collection to add to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('local_aiquiz_jobs', [
            'userid' => 'privacy:metadata:local_aiquiz_jobs:userid',
            'courseid' => 'privacy:metadata:local_aiquiz_jobs:courseid',
            'quizid' => 'privacy:metadata:local_aiquiz_jobs:quizid',
            'status' => 'privacy:metadata:local_aiquiz_jobs:status',
            'published' => 'privacy:metadata:local_aiquiz_jobs:published',
            'params' => 'privacy:metadata:local_aiquiz_jobs:params',
            'errormessage' => 'privacy:metadata:local_aiquiz_jobs:errormessage',
            'timecreated' => 'privacy:metadata:local_aiquiz_jobs:timecreated',
            'timemodified' => 'privacy:metadata:local_aiquiz_jobs:timemodified',
        ], 'privacy:metadata:local_aiquiz_jobs');

        // What manager::build_request() puts in the body. The file URLs carry
        // the teacher's core_files token, which is what makes this personal.
        $collection->add_external_location_link('generationservice', [
            'request_id' => 'privacy:metadata:generationservice:request_id',
            'language' => 'privacy:metadata:generationservice:language',
            'learning_objectives' => 'privacy:metadata:generationservice:learning_objectives',
            'source_files' => 'privacy:metadata:generationservice:source_files',
        ], 'privacy:metadata:generationservice');

        $collection->add_subsystem_link('core_userkey', [], 'privacy:metadata:core_userkey');

        return $collection;
    }

    /**
     * Course contexts where the user requested a generation.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {local_aiquiz_jobs} j ON j.courseid = ctx.instanceid AND ctx.contextlevel = :contextlevel
                 WHERE j.userid = :userid";

        $contextlist = new contextlist();
        $contextlist->add_from_sql($sql, ['contextlevel' => CONTEXT_COURSE, 'userid' => $userid]);
        return $contextlist;
    }

    /**
     * Users who requested a generation in the given course context.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist): void {
        $context = $userlist->get_context();
        if (!$context instanceof \context_course) {
            return;
        }

        $sql = "SELECT userid FROM {local_aiquiz_jobs} WHERE courseid = :courseid";
        $userlist->add_from_sql('userid', $sql, ['courseid' => $context->instanceid]);
    }

    /**
     * Export the user's generation jobs, one file per course.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }

            $jobs = $DB->get_records(
                'local_aiquiz_jobs',
                ['courseid' => $context->instanceid, 'userid' => $userid],
                'timecreated ASC'
            );
            if (!$jobs) {
                continue;
            }

            $data = [];
            foreach ($jobs as $job) {
                $params = json_decode($job->params ?? '', true) ?: [];
                $data[] = (object) [
                    'quizname' => $params['quizname'] ?? '',
                    'language' => $params['language'] ?? '',
                    'learning_objectives' => $params['learning_objectives'] ?? '',
                    'status' => $job->status,
                    'published' => transform::yesno($job->published),
                    'timepublished' => $job->timepublished ? transform::datetime($job->timepublished) : null,
                    'errormessage' => $job->errormessage,
                    'timecreated' => transform::datetime($job->timecreated),
                    'timemodified' => transform::datetime($job->timemodified),
                ];
            }

            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_aiquiz')],
                (object) ['jobs' => $data]
            );
        }
    }

    /**
     * Delete every generation job in a course.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;

        if (!$context instanceof \context_course) {
            return;
        }
        $DB->delete_records('local_aiquiz_jobs', ['courseid' => $context->instanceid]);
    }

    /**
     * Delete the user's generation jobs in the approved course contexts.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        $userid = $contextlist->get_user()->id;
        foreach ($contextlist->get_contexts() as $context) {
            if (!$context instanceof \context_course) {
                continue;
            }
            $DB->delete_records('local_aiquiz_jobs', ['courseid' => $context->instanceid, 'userid' => $userid]);
        }
    }

    /**
     * Delete the generation jobs of several users in one course context.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        $context = $userlist->get_context();
        if (!$context instanceof \context_course || !$userlist->get_userids()) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
        $params['courseid'] = $context->instanceid;
        $DB->delete_records_select('local_aiquiz_jobs', "courseid = :courseid AND userid $insql", $params);
    }
}
