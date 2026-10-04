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
 * Unit tests for the request building logic, which needs no database.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

/**
 * Locks the contract the plugin has with the generation service.
 *
 * The shape of the request body, and the way the form's question types are
 * folded onto it, are what the service validates with Pydantic - so a silent
 * change here is a 422 in production. These tests make such a change loud.
 *
 * @covers \local_aiquiz\manager
 */
final class manager_test extends \advanced_testcase {

    /**
     * Invoke manager::build_question_types(), which is protected.
     *
     * @param array $questions The 'questions' entries from a job's params.
     * @return array
     */
    protected static function build_types(array $questions): array {
        $method = new \ReflectionMethod(manager::class, 'build_question_types');
        $method->setAccessible(true);
        return $method->invoke(null, $questions);
    }

    /**
     * Invoke manager::build_params(), which is protected.
     *
     * @param \stdClass $formdata Data as generate_form::get_data() returns it.
     * @return array
     */
    protected static function build_params(\stdClass $formdata): array {
        $method = new \ReflectionMethod(manager::class, 'build_params');
        $method->setAccessible(true);
        return $method->invoke(null, $formdata);
    }

    /**
     * A job record with no source files, so nothing here touches the database.
     *
     * @param array $questions The 'questions' entries to store in params.
     * @return \stdClass
     */
    protected static function make_job(array $questions): \stdClass {
        return (object) [
            'id' => 1,
            'requestid' => '11111111-2222-3333-4444-555555555555',
            'courseid' => 42,
            'userid' => 7,
            'params' => json_encode([
                'quizname' => 'Chapter 1 quiz',
                'language' => 'ro',
                'learning_objectives' => 'Understand chapter 1',
                'fileids' => [],
                'questions' => $questions,
            ]),
        ];
    }

    /**
     * The two multiple choice form rows must arrive as one multichoice entry.
     *
     * The service has no notion of single versus multiple correct answers, so
     * the counts add up and the larger answers_per_question wins.
     */
    public function test_build_question_types_merges_both_multichoice_rows(): void {
        $result = self::build_types([
            ['type' => 'multichoice', 'single' => true, 'count' => 5, 'answers_per_question' => 4],
            ['type' => 'multichoice', 'single' => false, 'count' => 3, 'answers_per_question' => 6],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('multichoice', $result[0]['type']);
        $this->assertSame(8, $result[0]['count']);
        $this->assertSame(6, $result[0]['answers_per_question']);
    }

    /**
     * A type that takes no answer options must not grow the field.
     */
    public function test_build_question_types_omits_answers_for_types_without_them(): void {
        $result = self::build_types([
            ['type' => 'truefalse', 'single' => null, 'count' => 10, 'answers_per_question' => null],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame(10, $result[0]['count']);
        $this->assertArrayNotHasKey('answers_per_question', $result[0]);
    }

    /**
     * Four form rows collapse onto the three types the service knows.
     */
    public function test_build_question_types_collapses_four_rows_onto_three_types(): void {
        $result = self::build_types([
            ['type' => 'multichoice', 'single' => true, 'count' => 2, 'answers_per_question' => 4],
            ['type' => 'multichoice', 'single' => false, 'count' => 2, 'answers_per_question' => 4],
            ['type' => 'truefalse', 'single' => null, 'count' => 2, 'answers_per_question' => null],
            ['type' => 'shortanswer', 'single' => null, 'count' => 2, 'answers_per_question' => null],
        ]);

        $this->assertCount(3, $result);
        $this->assertSame(['multichoice', 'truefalse', 'shortanswer'],
            array_column($result, 'type'));
    }

    /**
     * The fields the service does not accept must never leak into the body.
     *
     * 'single' and 'difficulty' are collected by the form but deliberately
     * dropped: the service decides single/multi itself and has no difficulty
     * field at all.
     */
    public function test_build_question_types_drops_single_and_difficulty(): void {
        $result = self::build_types([
            [
                'type' => 'multichoice',
                'single' => true,
                'count' => 5,
                'answers_per_question' => 4,
                'difficulty' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
            ],
        ]);

        $this->assertSame(['type', 'count', 'answers_per_question'], array_keys($result[0]));
    }

    /**
     * A row with no type is skipped rather than producing a nameless entry.
     */
    public function test_build_question_types_skips_rows_without_a_type(): void {
        $result = self::build_types([
            ['type' => '', 'count' => 5],
            ['type' => 'truefalse', 'count' => 2],
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('truefalse', $result[0]['type']);
    }

    /**
     * No enabled types means no entries, not a malformed one.
     */
    public function test_build_question_types_handles_no_questions(): void {
        $this->assertSame([], self::build_types([]));
    }

    /**
     * The request body must carry exactly the keys the service validates.
     */
    public function test_build_request_has_the_documented_shape(): void {
        $job = self::make_job([
            ['type' => 'multichoice', 'single' => true, 'count' => 5, 'answers_per_question' => 4],
        ]);

        $request = manager::build_request($job);

        $this->assertSame(['request_id', 'callback_url', 'generation', 'source_files'],
            array_keys($request));
        $this->assertSame(['total_questions', 'language', 'learning_objectives', 'question_types'],
            array_keys($request['generation']));
        $this->assertSame($job->requestid, $request['request_id']);
        $this->assertSame(manager::callback_url($job->requestid), $request['callback_url']);
        $this->assertSame('ro', $request['generation']['language']);
        $this->assertSame('Understand chapter 1', $request['generation']['learning_objectives']);
        $this->assertSame([], $request['source_files']);
    }

    /**
     * total_questions and the dashboard's question count must agree.
     *
     * They are computed by two different code paths - one over the merged
     * service types, one over the raw form rows - so they can drift apart
     * without anything else noticing.
     */
    public function test_total_questions_matches_the_dashboard_count(): void {
        $job = self::make_job([
            ['type' => 'multichoice', 'single' => true, 'count' => 5, 'answers_per_question' => 4],
            ['type' => 'multichoice', 'single' => false, 'count' => 3, 'answers_per_question' => 6],
            ['type' => 'truefalse', 'single' => null, 'count' => 2, 'answers_per_question' => null],
        ]);

        $request = manager::build_request($job);

        $this->assertSame(10, $request['generation']['total_questions']);
        $this->assertSame(10, manager::count_questions($job));
    }

    /**
     * A job with unreadable params must not fatal on the dashboard.
     */
    public function test_count_questions_survives_empty_params(): void {
        $this->assertSame(0, manager::count_questions((object) ['params' => null]));
        $this->assertSame(0, manager::count_questions((object) ['params' => '']));
        $this->assertSame(0, manager::count_questions((object) ['params' => '{"questions":[]}']));
    }

    /**
     * Every status and published combination maps to one dashboard state.
     *
     * @dataProvider state_provider
     * @param string $status The job's status column.
     * @param int $published The job's published flag.
     * @param string $expected The expected STATE_* constant.
     */
    public function test_get_state(string $status, int $published, string $expected): void {
        $job = (object) ['status' => $status, 'published' => $published];
        $this->assertSame($expected, manager::get_state($job));
    }

    /**
     * Cases for test_get_state.
     *
     * @return array
     */
    public static function state_provider(): array {
        return [
            'pending' => [manager::STATUS_PENDING, 0, manager::STATE_GENERATING],
            'pending but flagged published' =>
                [manager::STATUS_PENDING, 1, manager::STATE_GENERATING],
            'done, awaiting review' => [manager::STATUS_DONE, 0, manager::STATE_READY],
            'done and published' => [manager::STATUS_DONE, 1, manager::STATE_PUBLISHED],
            'failed' => [manager::STATUS_FAILED, 0, manager::STATE_FAILED],
            'failed after publishing' => [manager::STATUS_FAILED, 1, manager::STATE_FAILED],
        ];
    }

    /**
     * The callback key must be stable, per job, and safe for PARAM_ALPHANUM.
     *
     * callback.php reads the key with PARAM_ALPHANUM, so a key format that was
     * not purely alphanumeric would be silently stripped and every callback
     * would come back 401.
     */
    public function test_callback_key(): void {
        $one = manager::callback_key('request-one');
        $two = manager::callback_key('request-two');

        $this->assertSame($one, manager::callback_key('request-one'));
        $this->assertNotSame($one, $two);
        $this->assertSame(64, strlen($one));
        $this->assertTrue(ctype_alnum($one), 'The key must survive PARAM_ALPHANUM.');
    }

    /**
     * Results are delivered to the plugin's callback, carrying the job's key.
     *
     * The key in the URL is the only thing that authenticates a delivery, so
     * a callback URL without it would have every delivery refused with 401.
     */
    public function test_callback_url_points_at_the_plugin_with_the_job_key(): void {
        $url = new \moodle_url(manager::callback_url('any-request-id'));

        $this->assertSame('/local/aiquiz/callback.php', $url->get_path(false));
        $this->assertSame(manager::callback_key('any-request-id'), $url->get_param('key'));
    }

    /**
     * The form submission is stored in the shape build_request expects.
     */
    public function test_build_params_stores_only_the_enabled_types(): void {
        $formdata = (object) [
            'quizname' => 'Chapter 1 quiz',
            'section' => 0,
            'language' => 'en',
            'learningobjectives' => '  Understand chapter 1  ',
            'fileids' => ['12', '34'],
            'enablemcsingle' => 1,
            'countmcsingle' => 5,
            'answersmcsingle' => 4,
            'difficultymcsingle' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
            'enablemcmulti' => 0,
            'enabletruefalse' => 1,
            'counttruefalse' => 3,
            'difficultytruefalse' => ['easy' => 20, 'medium' => 60, 'hard' => 20],
            'enableshortanswer' => 0,
        ];

        $params = self::build_params($formdata);

        $this->assertSame('Understand chapter 1', $params['learning_objectives']);
        $this->assertSame([12, 34], $params['fileids'], 'File ids must be cast to int.');
        $this->assertCount(2, $params['questions']);
        $this->assertSame(['multichoice', 'truefalse'], array_column($params['questions'], 'type'));
        $this->assertSame(4, $params['questions'][0]['answers_per_question']);
        $this->assertNull($params['questions'][1]['answers_per_question']);
        $this->assertSame(['easy' => 20, 'medium' => 60, 'hard' => 20],
            $params['questions'][1]['difficulty']);
    }
}
