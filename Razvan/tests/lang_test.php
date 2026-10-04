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
 * Tests that the strings built at runtime actually exist.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

use local_aiquiz\form\generate_form;

/**
 * Catches the missing strings a search of the source cannot find.
 *
 * Most string keys appear literally in a get_string() call, so an editor finds
 * them. These are assembled at runtime - 'state' . $state, "type{$key}", an
 * error code handed to moodle_exception - so nothing but a run notices when
 * one of them is missing and the page renders [[stateready]].
 */
final class lang_test extends \advanced_testcase {

    /**
     * Assert a string is defined and not a missing-string placeholder.
     *
     * @param string $identifier
     */
    protected function assert_string_exists(string $identifier): void {
        $this->assertTrue(
            get_string_manager()->string_exists($identifier, 'local_aiquiz'),
            "Missing language string: {$identifier}");
        $this->assertStringNotContainsString('[[', get_string($identifier, 'local_aiquiz'));
    }

    /**
     * Every dashboard state has a badge label.
     */
    public function test_every_state_has_a_label(): void {
        $states = [
            manager::STATE_GENERATING,
            manager::STATE_READY,
            manager::STATE_PUBLISHED,
            manager::STATE_FAILED,
        ];

        foreach ($states as $state) {
            $this->assert_string_exists('state' . $state);
        }
    }

    /**
     * Every question type the form offers has a label.
     */
    public function test_every_question_type_has_a_label(): void {
        foreach (array_keys(generate_form::get_types()) as $key) {
            $this->assert_string_exists('type' . $key);
        }
    }

    /**
     * Every error thrown by the plugin has a message.
     *
     * An exception whose string is missing shows the teacher the error code
     * instead of an explanation, which is exactly when they need one.
     */
    public function test_every_thrown_error_has_a_message(): void {
        $errorcodes = [
            'errorjobnotdone',
            'errornoendpoint',
            'errorsendfailed',
            'errorservicestatus',
            'errornofiles',
            'errornofilesincourse',
            'errornoquestions',
            'errornotypes',
            'erroranswersperquestion',
            'errordifficultysum',
            'errortoomanyquestions',
        ];

        foreach ($errorcodes as $errorcode) {
            $this->assert_string_exists($errorcode);
        }
    }

    /**
     * Every help button on the form has help text behind it.
     */
    public function test_every_help_button_has_text(): void {
        foreach (['sourcefiles', 'answersperquestion', 'difficulty', 'learningobjectives'] as $key) {
            $this->assert_string_exists($key);
            $this->assert_string_exists($key . '_help');
        }
    }

    /**
     * The plugin and its capability are named.
     */
    public function test_the_plugin_and_capability_are_named(): void {
        $this->assert_string_exists('pluginname');
        $this->assert_string_exists('aiquiz:generate');
        $this->assert_string_exists('taskgenerate');
    }
}
