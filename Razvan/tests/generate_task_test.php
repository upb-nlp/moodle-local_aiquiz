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
 * Tests for the adhoc task that sends the generation request.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

use local_aiquiz\task\generate_task;

/**
 * Covers the guards around the send, without ever reaching the network.
 *
 * The failure cases are reached by leaving the service unconfigured or
 * pointing it at an address Moodle's SSRF blocklist refuses, so these run
 * offline and deterministically.
 *
 * @covers \local_aiquiz\task\generate_task
 */
final class generate_task_test extends \advanced_testcase {

    /**
     * Insert a job row directly, without going through the form.
     *
     * @param array $overrides Values to replace in the baseline.
     * @return \stdClass The stored job.
     */
    protected function make_job(array $overrides = []): \stdClass {
        global $DB, $USER;

        $course = $this->getDataGenerator()->create_course();

        $job = (object) array_merge([
            'requestid' => \core\uuid::generate(),
            'courseid' => $course->id,
            'quizid' => null,
            'userid' => $USER->id,
            'status' => manager::STATUS_PENDING,
            'published' => 0,
            'params' => json_encode([
                'quizname' => 'Chapter 1 quiz',
                'language' => 'ro',
                'learning_objectives' => '',
                'fileids' => [],
                'questions' => [
                    ['type' => 'truefalse', 'single' => null, 'count' => 3,
                        'answers_per_question' => null],
                ],
            ]),
            'timecreated' => time(),
            'timemodified' => time(),
        ], $overrides);

        $job->id = $DB->insert_record('local_aiquiz_jobs', $job);

        return $job;
    }

    /**
     * Run the task for a job id, capturing its mtrace output.
     *
     * @param int $jobid
     * @return string What the task logged.
     */
    protected function run_task(int $jobid): string {
        $task = new generate_task();
        $task->set_custom_data(['jobid' => $jobid]);

        // The task reports through mtrace(), which writes straight out. Buffer
        // it so a passing run stays quiet and the messages can be asserted on.
        ob_start();
        try {
            $task->execute();
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    /**
     * A job deleted before cron ran is skipped, not fatal.
     */
    public function test_a_missing_job_is_skipped(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $output = $this->run_task(999999);

        $this->assertStringContainsString('no longer exists', $output);
    }

    /**
     * A job the callback already completed is never re-sent.
     *
     * The callback can land while the task is still queued. Sending again
     * would ask the service to generate the same quiz twice.
     */
    public function test_a_completed_job_is_not_sent_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $job = $this->make_job(['status' => manager::STATUS_DONE]);

        $output = $this->run_task($job->id);

        $this->assertStringContainsString('not pending', $output);
        $this->assertSame(manager::STATUS_DONE,
            $DB->get_field('local_aiquiz_jobs', 'status', ['id' => $job->id]));
    }

    /**
     * A job that already failed is not retried by a second task run.
     */
    public function test_a_failed_job_is_not_sent_again(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $job = $this->make_job([
            'status' => manager::STATUS_FAILED,
            'errormessage' => 'Original failure.',
        ]);

        $this->run_task($job->id);

        $this->assertSame('Original failure.',
            $DB->get_field('local_aiquiz_jobs', 'errormessage', ['id' => $job->id]));
    }

    /**
     * An unconfigured service marks the job failed instead of throwing.
     *
     * Rethrowing would make cron retry and eventually give up, leaving the
     * dashboard stuck on "Generating" with nothing to explain why.
     */
    public function test_an_unconfigured_service_fails_the_job(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('apiendpoint', '', 'local_aiquiz');

        $job = $this->make_job();

        $output = $this->run_task($job->id);

        $stored = $DB->get_record('local_aiquiz_jobs', ['id' => $job->id]);
        $this->assertSame(manager::STATUS_FAILED, $stored->status);
        $this->assertNotEmpty($stored->errormessage);
        $this->assertStringContainsString('failed', $output);
        $this->assertSame(manager::STATE_FAILED, manager::get_state($stored));
    }

    /**
     * The failure message reaches the dashboard, not just the cron log.
     */
    public function test_the_failure_reason_is_stored_on_the_job(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('apiendpoint', '', 'local_aiquiz');

        $job = $this->make_job();
        $this->run_task($job->id);

        $this->assertStringContainsString(
            get_string('errornoendpoint', 'local_aiquiz'),
            $DB->get_field('local_aiquiz_jobs', 'errormessage', ['id' => $job->id]));
    }

    /**
     * A private address is refused while the local API override is off.
     *
     * This is Moodle's SSRF blocklist doing its job: with allowlocalapi
     * disabled the request never leaves the server, and the job fails cleanly.
     */
    public function test_a_local_address_is_refused_in_production_mode(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('apiendpoint', 'http://127.0.0.1:9', 'local_aiquiz');
        set_config('allowlocalapi', 0, 'local_aiquiz');

        $job = $this->make_job();

        $this->run_task($job->id);

        $this->assertSame(manager::STATUS_FAILED,
            $DB->get_field('local_aiquiz_jobs', 'status', ['id' => $job->id]));
    }

    /**
     * The task never throws, so cron does not requeue a job it cannot send.
     */
    public function test_the_task_never_rethrows(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('apiendpoint', '', 'local_aiquiz');

        $job = $this->make_job();

        $this->run_task($job->id);

        // Reaching here without an exception is the assertion; make it explicit.
        $this->assertTrue(true);
    }

    /**
     * The task is named for the admin screens.
     */
    public function test_the_task_has_a_readable_name(): void {
        $name = (new generate_task())->get_name();

        $this->assertSame(get_string('taskgenerate', 'local_aiquiz'), $name);
        $this->assertStringNotContainsString('[[', $name);
    }
}
