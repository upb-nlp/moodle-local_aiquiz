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
 * Unit tests for importing delivered questions into a job's quiz.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

/**
 * Locks how delivered questions become quiz questions.
 *
 * The question shapes here are the ones the service has actually sent: its
 * own (stem, options, correct_answer) and the HRIA one (text, answers with
 * fractions). Anything that slips past normalise() and is refused by core
 * fails the whole delivery, so the refusals are tested as carefully as the
 * happy path.
 *
 * @covers \local_aiquiz\importer
 */
final class importer_test extends \advanced_testcase {
    /**
     * A single answer multiple choice question in the service's own shape.
     *
     * @param array $overrides Values to replace in the baseline.
     * @return array
     */
    protected static function service_multichoice(array $overrides = []): array {
        return array_merge([
            'question_id' => 'q_0001',
            'type' => 'multichoice',
            'stem' => 'What is a dummy PDF file for?',
            'options' => [
                ['label' => 'A', 'text' => 'Encrypting data', 'is_correct' => false],
                ['label' => 'B', 'text' => 'Testing file handling', 'is_correct' => true],
                ['label' => 'C', 'text' => 'Compressing datasets', 'is_correct' => false],
                ['label' => 'D', 'text' => 'Client review', 'is_correct' => false],
            ],
            'single_answer' => true,
            'difficulty' => 'medium',
            'explanation' => 'Dummy files stand in for real ones in tests.',
        ], $overrides);
    }

    /**
     * Form data for a job asking for five single answer multiple choice.
     *
     * @return \stdClass
     */
    protected static function form_data(): \stdClass {
        return (object) [
            'quizname' => 'Chapter 1 quiz',
            'section' => 0,
            'language' => 'en',
            'learningobjectives' => '',
            'fileids' => [],
            'enablemcsingle' => 1,
            'countmcsingle' => 5,
            'answersmcsingle' => 4,
            'difficultymcsingle' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
            'enablemcmulti' => 0,
            'enabletruefalse' => 0,
            'enableshortanswer' => 0,
        ];
    }

    /**
     * The quiz structure of a job: slot number => slot with its question.
     *
     * @param \stdClass $job
     * @return array
     */
    protected static function quiz_structure(\stdClass $job): array {
        $cm = get_coursemodule_from_instance('quiz', $job->quizid, $job->courseid, false, MUST_EXIST);
        return \mod_quiz\question\bank\qbank_helper::get_question_structure(
            $job->quizid,
            \context_module::instance($cm->id)
        );
    }

    /**
     * The copied fraction list must be exactly the one core accepts.
     *
     * A fraction core does not know is snapped by its importer to a different
     * value, which can push a multiple answer question off 100% and make core
     * refuse the whole delivery.
     */
    public function test_fractions_match_core(): void {
        global $CFG;
        require_once($CFG->libdir . '/questionlib.php');

        $core = array_map(
            fn($fraction) => round((float) $fraction * 100, 5),
            array_keys(\question_bank::fraction_options_full())
        );
        $ours = [];
        foreach (importer::FRACTIONS as $fraction) {
            $ours[] = (float) $fraction;
            if ($fraction != 0) {
                $ours[] = (float) -$fraction;
            }
        }
        sort($core);
        sort($ours);

        $this->assertEqualsWithDelta($core, $ours, 0.00001);
    }

    /**
     * Questions are found in the service's envelope and at the top level.
     */
    public function test_extract_questions_reads_both_shapes(): void {
        $question = self::service_multichoice();

        $this->assertSame(
            [$question],
            importer::extract_questions(['status' => 'completed', 'result' => ['questions' => [$question]]])
        );
        $this->assertSame([$question], importer::extract_questions(['requestid' => 'r', 'questions' => [$question]]));
        $this->assertSame([$question], importer::extract_questions(['result' => null, 'questions' => [$question]]));
        $this->assertNull(importer::extract_questions(['status' => 'failed', 'result' => null]));
        $this->assertNull(importer::extract_questions(['questions' => 'not a list']));
    }

    /**
     * A single answer question gives the right option full marks, the rest nothing.
     */
    public function test_normalise_service_single_answer(): void {
        $question = importer::normalise(self::service_multichoice(), $reason);

        $this->assertNull($reason);
        $this->assertSame('multichoice', $question['type']);
        $this->assertSame('What is a dummy PDF file for?', $question['text']);
        $this->assertTrue($question['single']);
        $this->assertSame(
            ['Encrypting data', 'Testing file handling', 'Compressing datasets', 'Client review'],
            array_column($question['answers'], 'text')
        );
        $this->assertEquals([0, 100, 0, 0], array_column($question['answers'], 'fraction'));
        $this->assertSame('medium', $question['difficulty']);
        $this->assertSame('Dummy files stand in for real ones in tests.', $question['explanation']);
    }

    /**
     * Several right options split the marks, and wrong ones take them away.
     */
    public function test_normalise_service_multiple_answers(): void {
        $question = importer::normalise(self::service_multichoice([
            'single_answer' => false,
            'options' => [
                ['text' => 'One', 'is_correct' => true],
                ['text' => 'Two', 'is_correct' => false],
                ['text' => 'Three', 'is_correct' => true],
                ['text' => 'Four', 'is_correct' => true],
            ],
        ]));

        $this->assertFalse($question['single']);
        $this->assertEquals([33.33333, -100, 33.33333, 33.33333], array_column($question['answers'], 'fraction'));
    }

    /**
     * Without a single_answer flag, the number of right options decides.
     */
    public function test_normalise_works_out_single_when_the_flag_is_missing(): void {
        $one = self::service_multichoice();
        unset($one['single_answer']);
        $this->assertTrue(importer::normalise($one)['single']);

        $two = $one;
        $two['options'][0]['is_correct'] = true;
        $question = importer::normalise($two);
        $this->assertFalse($question['single']);
        $this->assertEquals([50, 50, -50, -50], array_column($question['answers'], 'fraction'));
    }

    /**
     * The service's true/false carries only the right value.
     */
    public function test_normalise_service_truefalse(): void {
        $question = importer::normalise([
            'type' => 'truefalse',
            'stem' => 'The PDF holds real course content.',
            'correct_answer' => false,
            'difficulty' => 'medium',
        ]);

        $this->assertSame(['true', 'false'], array_column($question['answers'], 'text'));
        $this->assertEquals([0, 100], array_column($question['answers'], 'fraction'));
    }

    /**
     * The HRIA shape keeps its fractions and feedback, snapped to core's list.
     */
    public function test_normalise_hria_shape(): void {
        $question = importer::normalise([
            'type' => 'multichoice',
            'single_answer' => false,
            'text' => 'Pick the even numbers.',
            'answers' => [
                ['text' => '2', 'fraction' => 33, 'feedback' => 'Even.'],
                ['text' => '3', 'fraction' => -100, 'feedback' => 'Odd.'],
                ['text' => '4', 'fraction' => 33.333, 'feedback' => 'Even.'],
                ['text' => '0', 'fraction' => '33.33333', 'feedback' => 'Even.'],
            ],
            'difficulty' => 'easy',
        ], $reason);

        $this->assertNull($reason);
        $this->assertSame('Pick the even numbers.', $question['text']);
        $this->assertSame(['2', '3', '4', '0'], array_column($question['answers'], 'text'));
        $this->assertEquals([33.33333, -100, 33.33333, 33.33333], array_column($question['answers'], 'fraction'));
        $this->assertSame(['Even.', 'Odd.', 'Even.', 'Even.'], array_column($question['answers'], 'feedback'));
    }

    /**
     * An HRIA true/false is read from the text of the answer marked right.
     */
    public function test_normalise_hria_truefalse_in_romanian(): void {
        $question = importer::normalise([
            'type' => 'truefalse',
            'text' => 'Pamantul este rotund.',
            'answers' => [
                ['text' => 'Fals', 'fraction' => 0],
                ['text' => 'Adevărat', 'fraction' => 100],
            ],
        ]);

        $this->assertEquals([100, 0], array_column($question['answers'], 'fraction'));
    }

    /**
     * A short answer keeps only the accepted spellings, at full marks.
     */
    public function test_normalise_shortanswer(): void {
        $fromoptions = importer::normalise([
            'type' => 'shortanswer',
            'stem' => 'Capital of France?',
            'options' => [
                ['text' => 'Paris', 'is_correct' => true],
                ['text' => 'Lyon', 'is_correct' => false],
            ],
        ]);
        $this->assertSame(['Paris'], array_column($fromoptions['answers'], 'text'));
        $this->assertEquals([100], array_column($fromoptions['answers'], 'fraction'));

        $fromlist = importer::normalise([
            'type' => 'shortanswer',
            'stem' => 'Capital of France?',
            'correct_answer' => ['Paris', 'paris, france'],
        ]);
        $this->assertSame(['Paris', 'paris, france'], array_column($fromlist['answers'], 'text'));
    }

    /**
     * Questions core would refuse, or could not grade.
     *
     * @return array
     */
    public static function unusable_question_provider(): array {
        $options = [['text' => 'Yes', 'is_correct' => true], ['text' => 'No', 'is_correct' => false]];
        return [
            'not an object' => ['just a string', 'not a JSON object'],
            'unknown type' => [['type' => 'essay', 'stem' => 'Discuss.', 'options' => $options], 'unknown type'],
            'no text' => [['type' => 'multichoice', 'options' => $options], 'no question text'],
            'blank text' => [['type' => 'multichoice', 'stem' => "  \n ", 'options' => $options], 'no question text'],
            'no answers at all' => [['type' => 'multichoice', 'stem' => 'Q?'], 'no answers'],
            'a single option' => [
                ['type' => 'multichoice', 'stem' => 'Q?', 'options' => [['text' => 'Yes', 'is_correct' => true]]],
                'at least 2 answers',
            ],
            'nothing marked correct' => [
                ['type' => 'multichoice', 'stem' => 'Q?',
                    'options' => [['text' => 'Yes', 'is_correct' => false], ['text' => 'No', 'is_correct' => false]]],
                'no option is marked correct',
            ],
            'option without text' => [
                ['type' => 'multichoice', 'stem' => 'Q?',
                    'options' => [['text' => 'Yes', 'is_correct' => true], ['text' => '', 'is_correct' => false]]],
                'option 1 has no text',
            ],
            'single answer without full marks' => [
                ['type' => 'multichoice', 'single_answer' => true, 'text' => 'Q?',
                    'answers' => [['text' => 'A', 'fraction' => 50], ['text' => 'B', 'fraction' => 0]]],
                'no answer is worth 100%',
            ],
            'multiple answers not adding up' => [
                ['type' => 'multichoice', 'single_answer' => false, 'text' => 'Q?',
                    'answers' => [['text' => 'A', 'fraction' => 50], ['text' => 'B', 'fraction' => 25],
                        ['text' => 'C', 'fraction' => 0]]],
                'add up to 75%',
            ],
            'fraction out of range' => [
                ['type' => 'multichoice', 'text' => 'Q?',
                    'answers' => [['text' => 'A', 'fraction' => 150], ['text' => 'B', 'fraction' => 0]]],
                'answer 0 has fraction 150',
            ],
            'answer without text' => [
                ['type' => 'multichoice', 'text' => 'Q?',
                    'answers' => [['fraction' => 100], ['text' => 'B', 'fraction' => 0]]],
                'answer 0 has no text',
            ],
            'short answer without full marks' => [
                ['type' => 'shortanswer', 'text' => 'Q?', 'answers' => [['text' => 'A', 'fraction' => 50]]],
                'short answer needs an answer worth 100%',
            ],
            'true/false without a readable answer' => [
                ['type' => 'truefalse', 'stem' => 'Q?', 'correct_answer' => 'maybe'],
                'true/false question without a readable correct answer',
            ],
        ];
    }

    /**
     * Each unusable question is refused with a reason that says why.
     *
     * @dataProvider unusable_question_provider
     * @param mixed $question The delivered question.
     * @param string $expected A fragment the reason must contain.
     */
    public function test_normalise_refuses_unusable_questions($question, string $expected): void {
        $this->assertNull(importer::normalise($question, $reason));
        $this->assertStringContainsString($expected, $reason);
    }

    /**
     * Delivered text is escaped and cannot break out of the XML.
     */
    public function test_build_xml_escapes_delivered_text(): void {
        $questions = [
            importer::normalise(self::service_multichoice([
                'stem' => "Is 3 < 5 && 5 > 3?\nPick ]]> one.",
                'difficulty' => 'hard</text></tag><tag><text>injected',
            ])),
            importer::normalise([
                'type' => 'shortanswer',
                'stem' => 'Type the token.',
                'correct_answer' => 'a]]>b <c>',
            ]),
        ];

        $xml = simplexml_load_string(importer::build_xml($questions, 'req-1'));

        $this->assertNotFalse($xml);
        $this->assertCount(2, $xml->question);
        $this->assertSame(
            "Is 3 &lt; 5 &amp;&amp; 5 &gt; 3?<br>\nPick ]]&gt; one.",
            (string) $xml->question[0]->questiontext->text
        );
        $this->assertSame(
            ['hard</text></tag><tag><text>injected', 'generat-automat', 'quiz-req-1'],
            array_map(fn($tag) => (string) $tag->text, iterator_to_array($xml->question[0]->tags->tag, false))
        );
        // A short answer is matched against what the student types, so it is
        // kept exactly as delivered.
        $this->assertSame('a]]>b <c>', (string) $xml->question[1]->answer->text);
    }

    /**
     * A delivery fills the quiz with every usable question, in order.
     */
    public function test_import_fills_the_quiz(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);

        $result = importer::import($job, [
            self::service_multichoice(),
            ['type' => 'truefalse', 'stem' => 'Dummy files are real.', 'correct_answer' => false],
            ['type' => 'essay', 'stem' => 'Discuss.'],
            ['type' => 'shortanswer', 'stem' => 'Capital of France?', 'correct_answer' => 'Paris'],
            self::service_multichoice([
                'single_answer' => false,
                'options' => [
                    ['text' => 'One', 'is_correct' => true],
                    ['text' => 'Two', 'is_correct' => false],
                    ['text' => 'Three', 'is_correct' => true],
                ],
            ]),
        ]);

        $this->assertSame(4, $result['imported']);
        $this->assertCount(1, $result['skipped']);
        $this->assertSame(2, $result['skipped'][0]['index']);

        $structure = self::quiz_structure($job);
        $this->assertSame(
            ['multichoice', 'truefalse', 'shortanswer', 'multichoice'],
            array_values(array_column($structure, 'qtype'))
        );

        // Saved in the quiz's own question bank, not somewhere shared.
        $context = \context_module::instance(
            get_coursemodule_from_instance('quiz', $job->quizid, $job->courseid)->id
        );
        $category = question_get_default_category($context->id);
        $this->assertEquals(4, $DB->count_records(
            'question_bank_entries',
            ['questioncategoryid' => $category->id]
        ));

        // The quiz's total follows the questions, one mark each.
        $this->assertEquals(4, $DB->get_field('quiz', 'sumgrades', ['id' => $job->quizid]));

        // Core saved the split marks of the multiple answer question as sent.
        $multi = \question_bank::load_question(end($structure)->questionid);
        $this->assertInstanceOf(\qtype_multichoice_multi_question::class, $multi);
        $this->assertEqualsWithDelta([0.5, -1, 0.5], array_values(array_map(
            fn($answer) => (float) $answer->fraction,
            $multi->answers
        )), 0.0000001);
    }

    /**
     * Nothing is created when no delivered question is usable.
     */
    public function test_import_with_nothing_usable_leaves_the_quiz_empty(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);
        $before = $DB->count_records('question');

        try {
            importer::import($job, [['type' => 'essay', 'stem' => 'Discuss.'], 'not a question']);
            $this->fail('An import with nothing usable must throw.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errornovalidquestions', $e->errorcode);
        }

        $this->assertSame($before, $DB->count_records('question'));
        $this->assertSame([], self::quiz_structure($job));
    }

    /**
     * An empty delivery is an error, not a quiz with no questions.
     */
    public function test_import_of_an_empty_delivery_throws(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('errornoquestionsdelivered', 'local_aiquiz'));
        importer::import($job, []);
    }

    /**
     * A quiz deleted while the questions were being generated is reported.
     */
    public function test_import_into_a_deleted_quiz_throws(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $job = manager::create_job(self::form_data(), $course);
        course_delete_module(get_coursemodule_from_instance('quiz', $job->quizid, $job->courseid)->id);
        $before = $DB->count_records('question');

        try {
            importer::import($job, [self::service_multichoice()]);
            $this->fail('An import into a deleted quiz must throw.');
        } catch (\moodle_exception $e) {
            $this->assertSame('errorquizmissing', $e->errorcode);
        }
        $this->assertSame($before, $DB->count_records('question'));
    }
}
