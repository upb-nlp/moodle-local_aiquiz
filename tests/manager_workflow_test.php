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
 * Tests for the job lifecycle: create, publish, unpublish, delete.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

/**
 * Covers what a submitted form actually leaves behind in the database.
 *
 * The draft is meant to be invisible to students until a teacher publishes it,
 * so the visibility assertions here are the ones that matter most.
 *
 * @covers \local_aiquiz\manager
 */
final class manager_workflow_test extends \advanced_testcase {
    /**
     * Form data for a job asking for five single answer multiple choice.
     *
     * @param array $overrides Values to replace in the baseline.
     * @return \stdClass
     */
    protected static function form_data(array $overrides = []): \stdClass {
        return (object) array_merge([
            'quizname' => 'Chapter 1 quiz',
            'section' => 0,
            'language' => 'ro',
            'learningobjectives' => 'Understand chapter 1',
            'fileids' => [],
            'enablemcsingle' => 1,
            'countmcsingle' => 5,
            'answersmcsingle' => 4,
            'difficultymcsingle' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
            'enablemcmulti' => 0,
            'enabletruefalse' => 0,
            'enableshortanswer' => 0,
        ], $overrides);
    }

    /**
     * The course module for a job's quiz.
     *
     * @param \stdClass $job
     * @return \stdClass
     */
    protected static function quiz_cm(\stdClass $job): \stdClass {
        return get_coursemodule_from_instance(
            'quiz',
            $job->quizid,
            $job->courseid,
            false,
            MUST_EXIST
        );
    }

    /**
     * Submitting the form leaves a hidden quiz, a pending job and a queued task.
     */
    public function test_create_job_creates_a_hidden_draft(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $job = manager::create_job(self::form_data(), $course);

        $stored = $DB->get_record('local_aiquiz_jobs', ['id' => $job->id], '*', MUST_EXIST);
        $this->assertSame(manager::STATUS_PENDING, $stored->status);
        $this->assertEquals(0, $stored->published);
        $this->assertEquals($course->id, $stored->courseid);
        $this->assertNotEmpty($stored->quizid);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $stored->requestid,
            'requestid should be a UUID.'
        );

        $this->assertTrue($DB->record_exists('quiz', ['id' => $stored->quizid]));
        $this->assertEquals(
            0,
            self::quiz_cm($stored)->visible,
            'The generated quiz must start hidden from students.'
        );
    }

    /**
     * The job is recorded against the teacher who asked for it.
     */
    public function test_create_job_records_the_requesting_teacher(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $job = manager::create_job(self::form_data(), $course);

        $this->assertEquals(
            $teacher->id,
            $DB->get_field('local_aiquiz_jobs', 'userid', ['id' => $job->id])
        );
    }

    /**
     * The quiz is created in the section the teacher chose.
     */
    public function test_create_job_uses_the_requested_section(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course(
            ['numsections' => 3],
            ['createsections' => true]
        );

        $job = manager::create_job(self::form_data(['section' => 2]), $course);

        $cm = self::quiz_cm($job);
        $this->assertEquals(2, $DB->get_field('course_sections', 'section', ['id' => $cm->section]));
    }

    /**
     * The transfer is handed to cron rather than run in the teacher's request.
     */
    public function test_create_job_queues_the_adhoc_task(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $job = manager::create_job(self::form_data(), $course);

        $tasks = $DB->get_records('task_adhoc');
        $this->assertCount(1, $tasks);

        $task = reset($tasks);
        $this->assertStringContainsString('local_aiquiz', $task->classname);
        $this->assertStringContainsString('generate_task', $task->classname);
        $this->assertEquals($job->id, json_decode($task->customdata)->jobid);
    }

    /**
     * Two submissions do not collide on the unique requestid index.
     */
    public function test_two_jobs_get_different_request_ids(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();

        $one = manager::create_job(self::form_data(), $course);
        $two = manager::create_job(self::form_data(['quizname' => 'Chapter 2 quiz']), $course);

        $this->assertNotSame($one->requestid, $two->requestid);
    }

    /**
     * Publishing makes the quiz visible and records the approval.
     */
    public function test_publish_makes_the_quiz_visible(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);

        // The service would normally do this through its callback.
        $job->status = manager::STATUS_DONE;
        $DB->update_record('local_aiquiz_jobs', $job);

        manager::publish($job);

        $this->assertEquals(1, self::quiz_cm($job)->visible);
        $stored = $DB->get_record('local_aiquiz_jobs', ['id' => $job->id]);
        $this->assertEquals(1, $stored->published);
        $this->assertNotEmpty($stored->timepublished);
        $this->assertSame(manager::STATE_PUBLISHED, manager::get_state($stored));
    }

    /**
     * A quiz with no generated questions cannot be published by mistake.
     */
    public function test_publish_refuses_a_job_that_is_not_done(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errorjobnotdone', 'local_aiquiz'));

        manager::publish($job);
    }

    /**
     * Unpublishing hides the quiz from students again.
     */
    public function test_unpublish_hides_the_quiz(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);
        $job->status = manager::STATUS_DONE;
        $DB->update_record('local_aiquiz_jobs', $job);
        manager::publish($job);

        manager::unpublish($job);

        $this->assertEquals(0, self::quiz_cm($job)->visible);
        $stored = $DB->get_record('local_aiquiz_jobs', ['id' => $job->id]);
        $this->assertEquals(0, $stored->published);
        $this->assertSame(manager::STATE_READY, manager::get_state($stored));
    }

    /**
     * Deleting a job removes the quiz activity as well as the record.
     */
    public function test_delete_removes_the_quiz_and_the_job(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Keep the deletion synchronous and cheap in tests.
        set_config('coursebinenable', 0, 'tool_recyclebin');

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);
        $quizid = $job->quizid;

        manager::delete($job);

        $this->assertFalse($DB->record_exists('local_aiquiz_jobs', ['id' => $job->id]));
        $this->assertFalse($DB->record_exists('quiz', ['id' => $quizid]));
    }

    /**
     * A job whose quiz was already removed by hand still deletes cleanly.
     */
    public function test_delete_survives_a_missing_quiz(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('coursebinenable', 0, 'tool_recyclebin');

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);
        course_delete_module(self::quiz_cm($job)->id);

        manager::delete($job);

        $this->assertFalse($DB->record_exists('local_aiquiz_jobs', ['id' => $job->id]));
    }

    /**
     * The dashboard still lists a job whose quiz was deleted outside the plugin.
     */
    public function test_get_course_jobs_tolerates_a_deleted_quiz(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('coursebinenable', 0, 'tool_recyclebin');

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);
        course_delete_module(self::quiz_cm($job)->id);

        $jobs = manager::get_course_jobs($course->id);

        $this->assertCount(1, $jobs);
        $listed = reset($jobs);
        $this->assertNull($listed->quizname);
        $this->assertNull($listed->cmid);
        // The index.php page falls back to the name stored on the job.
        $this->assertSame('Chapter 1 quiz', json_decode($listed->params, true)['quizname']);
    }

    /**
     * Only this course's jobs are listed, newest first.
     */
    public function test_get_course_jobs_is_scoped_and_ordered(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();

        $older = manager::create_job(self::form_data(['quizname' => 'Older']), $coursea);
        $newer = manager::create_job(self::form_data(['quizname' => 'Newer']), $coursea);
        manager::create_job(self::form_data(['quizname' => 'Elsewhere']), $courseb);

        // Both were created in the same second, so force a gap to test the order.
        $DB->set_field('local_aiquiz_jobs', 'timecreated', time() - 3600, ['id' => $older->id]);

        $jobs = manager::get_course_jobs($coursea->id);

        $this->assertCount(2, $jobs);
        // Ids read back from the database are strings on some drivers.
        $this->assertSame([(int) $newer->id, (int) $older->id], array_map('intval', array_column($jobs, 'id')));
    }

    /**
     * A job id from another course must not resolve under this course.
     *
     * index.php looks a job up by id *and* courseid before acting on it, which
     * is what stops a teacher in one course publishing another course's quiz
     * by editing the URL.
     */
    public function test_a_job_is_not_reachable_from_another_course(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $courseb);

        $this->assertFalse($DB->record_exists(
            'local_aiquiz_jobs',
            ['id' => $job->id, 'courseid' => $coursea->id]
        ));
    }

    /**
     * Source files travel as token URLs, never as bytes.
     *
     * The service pulls each file itself, so the request must carry a
     * tokenpluginfile.php URL that authorises that pull.
     */
    public function test_build_request_mints_token_urls_for_real_files(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()
            ->get_plugin_generator('mod_resource')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance(
            'resource',
            $instance->id,
            $course->id,
            false,
            MUST_EXIST
        );
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'chapter1.txt',
        ], 'Pretend this is a lecture handout.');

        $job = manager::create_job(self::form_data(['fileids' => [$file->get_id()]]), $course);
        $request = manager::build_request($job);

        $this->assertCount(1, $request['source_files']);
        $source = $request['source_files'][0];
        $this->assertSame('chapter1.txt', $source['filename']);
        $this->assertSame((string) $file->get_id(), $source['file_id']);
        $this->assertStringContainsString('tokenpluginfile.php', $source['url']);
        $this->assertNull($source['checksum_sha256']);
    }

    /**
     * The URLs carry a key of the job's own that expires and is revoked.
     *
     * Not the teacher's permanent core_files key, which would keep working
     * for as long as it exists.
     */
    public function test_file_urls_use_a_temporary_job_key(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()->get_plugin_generator('mod_resource')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('resource', $instance->id, $course->id, false, MUST_EXIST);
        $file = get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => 'mod_resource',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/',
            'filename' => 'chapter 1.txt',
        ], 'Handout.');

        $job = manager::create_job(self::form_data(['fileids' => [$file->get_id()]]), $course);
        $url = manager::build_request($job)['source_files'][0]['url'];

        $key = $DB->get_record('user_private_key', ['id' => $job->filekeyid], '*', MUST_EXIST);
        $this->assertEquals('core_files', $key->script);
        $this->assertEquals($job->userid, $key->userid);
        $this->assertGreaterThan(time(), (int) $key->validuntil);
        $this->assertLessThanOrEqual(time() + manager::FILE_KEY_LIFETIME, (int) $key->validuntil);
        $this->assertStringContainsString('/tokenpluginfile.php/' . $key->value . '/', $url);
        $this->assertStringContainsString('chapter%201.txt', $url);
        $this->assertNotEquals(get_user_key('core_files', $job->userid), $key->value);

        // Building the request again (a retried task) replaces the key.
        $oldkeyid = $job->filekeyid;
        manager::build_request($job);
        $this->assertFalse($DB->record_exists('user_private_key', ['id' => $oldkeyid]));
        $this->assertEquals(1, $DB->count_records('user_private_key', ['id' => $job->filekeyid]));

        manager::revoke_file_key($job);
        $this->assertFalse($DB->record_exists('user_private_key', ['id' => $key->id]));
        $this->assertNull($DB->get_field('local_aiquiz_jobs', 'filekeyid', ['id' => $job->id]));
    }

    /**
     * Deleting a job also deletes its download key.
     */
    public function test_delete_revokes_the_file_key(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);
        manager::issue_file_key($job);
        $keyid = $job->filekeyid;

        manager::delete($job);
        $this->assertFalse($DB->record_exists('user_private_key', ['id' => $keyid]));
    }
}
