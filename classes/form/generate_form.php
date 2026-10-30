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
 * The quiz generation form.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Collects everything needed to request a generated quiz.
 */
class generate_form extends \moodleform {
    /** @var int Upper bound on questions per request. */
    const MAX_QUESTIONS = 100;

    /**
     * The question types offered, and how each maps onto the API contract.
     *
     * Key = form field suffix. 'type' and 'single' are what the API expects;
     * 'answers' says whether the type takes an answers_per_question value.
     *
     * @return array
     */
    public static function get_types(): array {
        return [
            'mcsingle' => ['type' => 'multichoice', 'single' => true, 'answers' => true],
            'mcmulti' => ['type' => 'multichoice', 'single' => false, 'answers' => true],
            'truefalse' => ['type' => 'truefalse', 'single' => null, 'answers' => false],
            'shortanswer' => ['type' => 'shortanswer', 'single' => null, 'answers' => false],
        ];
    }

    /**
     * Build the form.
     */
    protected function definition() {
        $mform = $this->_form;
        $course = $this->_customdata['course'];
        $files = $this->_customdata['files'];
        $sections = $this->_customdata['sections'];

        $mform->addElement('hidden', 'courseid', $course->id);
        $mform->setType('courseid', PARAM_INT);

        // General.
        $mform->addElement('header', 'generalheader', get_string('generalheader', 'local_aiquiz'));
        $mform->setExpanded('generalheader');

        $mform->addElement(
            'text',
            'quizname',
            get_string('quizname', 'local_aiquiz'),
            ['size' => 50]
        );
        $mform->setType('quizname', PARAM_TEXT);
        $mform->addRule('quizname', get_string('required'), 'required', null, 'client');
        $mform->addRule('quizname', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $mform->addElement('select', 'section', get_string('section', 'local_aiquiz'), $sections);
        $mform->setType('section', PARAM_INT);

        // Source materials.
        $mform->addElement('header', 'materialsheader', get_string('materialsheader', 'local_aiquiz'));
        $mform->setExpanded('materialsheader');

        if (empty($files)) {
            $mform->addElement(
                'static',
                'nofiles',
                '',
                get_string('errornofilesincourse', 'local_aiquiz')
            );
        } else {
            $mform->addElement(
                'autocomplete',
                'fileids',
                get_string('sourcefiles', 'local_aiquiz'),
                $files,
                [
                    'multiple' => true,
                    'noselectionstring' => get_string('nomaterialsselected', 'local_aiquiz'),
                ]
            );
            $mform->setType('fileids', PARAM_INT);
            $mform->addHelpButton('fileids', 'sourcefiles', 'local_aiquiz');
        }

        // Questions: one block per question type, shown only when ticked.
        $mform->addElement('header', 'questionsheader', get_string('questionsheader', 'local_aiquiz'));
        $mform->setExpanded('questionsheader');

        foreach (self::get_types() as $key => $meta) {
            $mform->addElement(
                'advcheckbox',
                "enable{$key}",
                get_string("type{$key}", 'local_aiquiz')
            );
            $mform->setType("enable{$key}", PARAM_BOOL);

            $mform->addElement(
                'text',
                "count{$key}",
                get_string('count', 'local_aiquiz'),
                ['size' => 4]
            );
            $mform->setType("count{$key}", PARAM_INT);
            $mform->setDefault("count{$key}", 5);
            $mform->hideIf("count{$key}", "enable{$key}", 'notchecked');

            if ($meta['answers']) {
                $mform->addElement(
                    'text',
                    "answers{$key}",
                    get_string('answersperquestion', 'local_aiquiz'),
                    ['size' => 4]
                );
                $mform->setType("answers{$key}", PARAM_INT);
                $mform->setDefault("answers{$key}", 4);
                $mform->addHelpButton("answers{$key}", 'answersperquestion', 'local_aiquiz');
                $mform->hideIf("answers{$key}", "enable{$key}", 'notchecked');
            }

            $mform->addGroup([
                $mform->createElement('text', 'easy', '', ['size' => 3]),
                $mform->createElement('text', 'medium', '', ['size' => 3]),
                $mform->createElement('text', 'hard', '', ['size' => 3]),
            ], "difficulty{$key}", get_string('difficulty', 'local_aiquiz'), ' ', true);
            $mform->setType("difficulty{$key}[easy]", PARAM_INT);
            $mform->setType("difficulty{$key}[medium]", PARAM_INT);
            $mform->setType("difficulty{$key}[hard]", PARAM_INT);
            $mform->setDefault("difficulty{$key}[easy]", 30);
            $mform->setDefault("difficulty{$key}[medium]", 50);
            $mform->setDefault("difficulty{$key}[hard]", 20);
            $mform->addHelpButton("difficulty{$key}", 'difficulty', 'local_aiquiz');
            $mform->hideIf("difficulty{$key}", "enable{$key}", 'notchecked');
        }
        $mform->setDefault('enablemcsingle', 1);

        // Generation options.
        $mform->addElement('header', 'optionsheader', get_string('optionsheader', 'local_aiquiz'));

        $mform->addElement('select', 'language', get_string('language', 'local_aiquiz'), [
            'ro' => get_string('languagero', 'local_aiquiz'),
            'en' => get_string('languageen', 'local_aiquiz'),
        ]);
        $mform->setType('language', PARAM_ALPHA);
        $mform->setDefault('language', 'ro');

        $mform->addElement(
            'textarea',
            'learningobjectives',
            get_string('learningobjectives', 'local_aiquiz'),
            ['rows' => 3, 'cols' => 50]
        );
        $mform->setType('learningobjectives', PARAM_TEXT);
        $mform->addHelpButton('learningobjectives', 'learningobjectives', 'local_aiquiz');

        $this->add_action_buttons(true, get_string('generatequiz', 'local_aiquiz'));
    }

    /**
     * Server side validation.
     *
     * @param array $data submitted values
     * @param array $files uploaded files
     * @return array field name => error message
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (empty($data['fileids'])) {
            $errors['fileids'] = get_string('errornofiles', 'local_aiquiz');
        }

        $enabledtypes = 0;
        $total = 0;

        foreach (self::get_types() as $key => $meta) {
            if (empty($data["enable{$key}"])) {
                continue;
            }
            $enabledtypes++;

            $count = (int) ($data["count{$key}"] ?? 0);
            if ($count < 1) {
                $errors["count{$key}"] = get_string('errornoquestions', 'local_aiquiz');
            }
            $total += $count;

            if ($meta['answers'] && (int) ($data["answers{$key}"] ?? 0) < 2) {
                $errors["answers{$key}"] = get_string('erroranswersperquestion', 'local_aiquiz');
            }

            $sum = (int) $data["difficulty{$key}"]['easy']
                 + (int) $data["difficulty{$key}"]['medium']
                 + (int) $data["difficulty{$key}"]['hard'];
            if ($sum !== 100) {
                $errors["difficulty{$key}"] = get_string('errordifficultysum', 'local_aiquiz', $sum);
            }
        }

        if ($enabledtypes === 0) {
            $errors['enablemcsingle'] = get_string('errornotypes', 'local_aiquiz');
        } else if ($total > self::MAX_QUESTIONS) {
            $errors['enablemcsingle'] = get_string(
                'errortoomanyquestions',
                'local_aiquiz',
                self::MAX_QUESTIONS
            );
        }

        return $errors;
    }
}
