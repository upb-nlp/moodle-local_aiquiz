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
 * Unit tests for the generation form's server side validation.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

use local_aiquiz\form\generate_form;

/**
 * Covers every rule in generate_form::validation().
 *
 * The client side rules can be bypassed, so these server side checks are the
 * real guard on what reaches the generation service.
 *
 * @covers \local_aiquiz\form\generate_form
 */
final class generate_form_test extends \advanced_testcase {

    /**
     * A form instance whose constructor never ran.
     *
     * validation() reads only its arguments and self::get_types(), never the
     * QuickForm object, so building the whole form (which would need a page,
     * a course and the file list) buys nothing and adds ways to fail.
     *
     * @return generate_form
     */
    protected static function form(): generate_form {
        return (new \ReflectionClass(generate_form::class))->newInstanceWithoutConstructor();
    }

    /**
     * Submitted data that passes every rule, as the baseline to break.
     *
     * @param array $overrides Values to replace in the baseline.
     * @return array
     */
    protected static function valid_data(array $overrides = []): array {
        return array_merge([
            'quizname' => 'Chapter 1 quiz',
            'section' => 0,
            'language' => 'ro',
            'learningobjectives' => '',
            'fileids' => [12],
            'enablemcsingle' => 1,
            'countmcsingle' => 5,
            'answersmcsingle' => 4,
            'difficultymcsingle' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
            'enablemcmulti' => 0,
            'countmcmulti' => 5,
            'answersmcmulti' => 4,
            'difficultymcmulti' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
            'enabletruefalse' => 0,
            'counttruefalse' => 5,
            'difficultytruefalse' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
            'enableshortanswer' => 0,
            'countshortanswer' => 5,
            'difficultyshortanswer' => ['easy' => 30, 'medium' => 50, 'hard' => 20],
        ], $overrides);
    }

    /**
     * The baseline must pass, or every other test here is meaningless.
     */
    public function test_valid_submission_has_no_errors(): void {
        $this->assertSame([], self::form()->validation(self::valid_data(), []));
    }

    /**
     * Generation with no source material is refused.
     */
    public function test_source_materials_are_required(): void {
        $errors = self::form()->validation(self::valid_data(['fileids' => []]), []);

        $this->assertArrayHasKey('fileids', $errors);
    }

    /**
     * At least one question type must be ticked.
     */
    public function test_at_least_one_question_type_is_required(): void {
        $errors = self::form()->validation(self::valid_data(['enablemcsingle' => 0]), []);

        $this->assertArrayHasKey('enablemcsingle', $errors);
        $this->assertSame(get_string('errornotypes', 'local_aiquiz'), $errors['enablemcsingle']);
    }

    /**
     * An enabled type asking for no questions is refused.
     */
    public function test_enabled_type_needs_at_least_one_question(): void {
        $errors = self::form()->validation(self::valid_data(['countmcsingle' => 0]), []);

        $this->assertArrayHasKey('countmcsingle', $errors);
    }

    /**
     * A multiple choice question needs at least two options to choose from.
     */
    public function test_multichoice_needs_at_least_two_answers(): void {
        $errors = self::form()->validation(self::valid_data(['answersmcsingle' => 1]), []);

        $this->assertArrayHasKey('answersmcsingle', $errors);
    }

    /**
     * Types without answer options are not held to the answers rule.
     */
    public function test_truefalse_is_not_checked_for_answers(): void {
        $errors = self::form()->validation(self::valid_data([
            'enablemcsingle' => 0,
            'enabletruefalse' => 1,
        ]), []);

        $this->assertSame([], $errors);
    }

    /**
     * The difficulty split is a percentage split, so it must total 100.
     */
    public function test_difficulty_must_sum_to_100(): void {
        $errors = self::form()->validation(self::valid_data([
            'difficultymcsingle' => ['easy' => 30, 'medium' => 50, 'hard' => 10],
        ]), []);

        $this->assertArrayHasKey('difficultymcsingle', $errors);
        $this->assertStringContainsString('90', $errors['difficultymcsingle'],
            'The message should tell the teacher what the total currently is.');
    }

    /**
     * Each enabled type is checked separately.
     */
    public function test_difficulty_is_validated_per_type(): void {
        $errors = self::form()->validation(self::valid_data([
            'enabletruefalse' => 1,
            'difficultytruefalse' => ['easy' => 0, 'medium' => 0, 'hard' => 0],
        ]), []);

        $this->assertArrayHasKey('difficultytruefalse', $errors);
        $this->assertArrayNotHasKey('difficultymcsingle', $errors);
    }

    /**
     * The total across all types is capped.
     */
    public function test_total_questions_are_capped(): void {
        $errors = self::form()->validation(self::valid_data([
            'countmcsingle' => generate_form::MAX_QUESTIONS,
            'enabletruefalse' => 1,
            'counttruefalse' => 1,
        ]), []);

        $this->assertArrayHasKey('enablemcsingle', $errors);
        $this->assertSame(
            get_string('errortoomanyquestions', 'local_aiquiz', generate_form::MAX_QUESTIONS),
            $errors['enablemcsingle']);
    }

    /**
     * Exactly the maximum is allowed; the cap is not off by one.
     */
    public function test_the_cap_itself_is_allowed(): void {
        $errors = self::form()->validation(self::valid_data([
            'countmcsingle' => generate_form::MAX_QUESTIONS,
        ]), []);

        $this->assertSame([], $errors);
    }

    /**
     * A disabled type's fields are ignored, however broken they are.
     */
    public function test_disabled_types_are_not_validated(): void {
        $errors = self::form()->validation(self::valid_data([
            'countmcmulti' => 0,
            'answersmcmulti' => 0,
            'difficultymcmulti' => ['easy' => 1, 'medium' => 1, 'hard' => 1],
        ]), []);

        $this->assertSame([], $errors);
    }

    /**
     * Every type the form offers maps onto a type the service accepts.
     *
     * The service knows exactly these three; anything else is a 422.
     */
    public function test_every_form_type_maps_to_a_service_type(): void {
        $servicetypes = ['multichoice', 'truefalse', 'shortanswer'];

        foreach (generate_form::get_types() as $key => $meta) {
            $this->assertContains($meta['type'], $servicetypes,
                "Form type {$key} maps to an unknown service type.");
            $this->assertArrayHasKey('single', $meta);
            $this->assertArrayHasKey('answers', $meta);
        }
    }
}
