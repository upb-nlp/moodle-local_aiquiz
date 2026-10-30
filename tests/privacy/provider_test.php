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
 * Tests for the privacy provider.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use core_privacy\tests\provider_testcase;

/**
 * Checks that a teacher's generation jobs are found, exported and deleted.
 *
 * @covers \local_aiquiz\privacy\provider
 */
final class provider_test extends provider_testcase {
    /**
     * Insert a job row directly, bypassing the quiz creation in manager.
     *
     * @param int $courseid
     * @param int $userid
     * @return \stdClass
     */
    protected function make_job(int $courseid, int $userid): \stdClass {
        global $DB;

        $job = (object) [
            'requestid' => \core\uuid::generate(),
            'courseid' => $courseid,
            'quizid' => null,
            'userid' => $userid,
            'status' => 'done',
            'published' => 0,
            'params' => json_encode([
                'quizname' => 'Chapter 1 quiz',
                'language' => 'en',
                'learning_objectives' => 'Understand chapter 1',
                'fileids' => [],
                'questions' => [],
            ]),
            'timecreated' => time(),
            'timemodified' => time(),
        ];
        $job->id = $DB->insert_record('local_aiquiz_jobs', $job);
        return $job;
    }

    /**
     * The metadata names the table, the external service and the user key.
     */
    public function test_get_metadata(): void {
        $items = provider::get_metadata(new collection('local_aiquiz'))->get_collection();

        $names = array_map(fn($item) => $item->get_name(), $items);
        $this->assertContains('local_aiquiz_jobs', $names);
        $this->assertContains('generationservice', $names);
        $this->assertContains('core_userkey', $names);
    }

    /**
     * Only the courses the user generated a quiz in are reported.
     */
    public function test_get_contexts_and_users(): void {
        $this->resetAfterTest();

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->make_job($course1->id, $teacher->id);
        $this->make_job($course2->id, $other->id);

        $contextids = provider::get_contexts_for_userid($teacher->id)->get_contextids();
        $this->assertEquals([\context_course::instance($course1->id)->id], array_values($contextids));

        $userlist = new userlist(\context_course::instance($course1->id), 'local_aiquiz');
        provider::get_users_in_context($userlist);
        $this->assertEquals([$teacher->id], array_values($userlist->get_userids()));
    }

    /**
     * The export carries the job's settings under the plugin name.
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->make_job($course->id, $teacher->id);
        $context = \context_course::instance($course->id);

        provider::export_user_data(new approved_contextlist($teacher, 'local_aiquiz', [$context->id]));

        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());
        $data = $writer->get_data([get_string('pluginname', 'local_aiquiz')]);
        $this->assertCount(1, $data->jobs);
        $this->assertEquals('Chapter 1 quiz', $data->jobs[0]->quizname);
        $this->assertEquals('Understand chapter 1', $data->jobs[0]->learning_objectives);
    }

    /**
     * Deleting for one user leaves other users' jobs alone.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->make_job($course->id, $teacher->id);
        $this->make_job($course->id, $other->id);
        $context = \context_course::instance($course->id);

        provider::delete_data_for_user(new approved_contextlist($teacher, 'local_aiquiz', [$context->id]));

        $this->assertFalse($DB->record_exists('local_aiquiz_jobs', ['userid' => $teacher->id]));
        $this->assertTrue($DB->record_exists('local_aiquiz_jobs', ['userid' => $other->id]));
    }

    /**
     * Deleting a user list removes only the listed users in that course.
     */
    public function test_delete_data_for_users(): void {
        global $DB;
        $this->resetAfterTest();

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->make_job($course1->id, $teacher->id);
        $this->make_job($course1->id, $other->id);
        $this->make_job($course2->id, $teacher->id);
        $context = \context_course::instance($course1->id);

        provider::delete_data_for_users(new approved_userlist($context, 'local_aiquiz', [$teacher->id]));

        $this->assertFalse($DB->record_exists('local_aiquiz_jobs', ['courseid' => $course1->id, 'userid' => $teacher->id]));
        $this->assertTrue($DB->record_exists('local_aiquiz_jobs', ['courseid' => $course1->id, 'userid' => $other->id]));
        $this->assertTrue($DB->record_exists('local_aiquiz_jobs', ['courseid' => $course2->id, 'userid' => $teacher->id]));
    }

    /**
     * Deleting a whole course context removes every job in that course only.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->make_job($course1->id, $teacher->id);
        $this->make_job($course2->id, $teacher->id);

        provider::delete_data_for_all_users_in_context(\context_course::instance($course1->id));

        $this->assertFalse($DB->record_exists('local_aiquiz_jobs', ['courseid' => $course1->id]));
        $this->assertTrue($DB->record_exists('local_aiquiz_jobs', ['courseid' => $course2->id]));
    }
}
