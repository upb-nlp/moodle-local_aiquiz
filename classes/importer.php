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
 * Imports the questions the generation service delivers into a job's quiz.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

defined('MOODLE_INTERNAL') || die();

/**
 * Turns delivered questions into quiz questions.
 *
 * The questions are written out as Moodle XML and fed through core's own XML
 * importer, so they are saved exactly as a teacher's manual import would save
 * them, then added to the job's quiz in the order they arrived.
 *
 * Two shapes are accepted for a question, because the service has delivered
 * both: its own, {stem, options: [{text, is_correct}], correct_answer}, and
 * the one the HRIA import API was built around, {text, answers: [{text,
 * fraction, feedback}]}. A question that fits neither, or that core would
 * refuse, is left out with a reason rather than failing the whole job.
 */
class importer {

    /** @var string[] The question types the service generates. */
    const TYPES = ['multichoice', 'truefalse', 'shortanswer'];

    /**
     * @var float[] The positive answer fractions core accepts, as percentages.
     *
     * The list behind question_bank::fraction_options_full(), which also
     * accepts each one negated. Copied rather than called so normalise() runs
     * without the question engine loaded; importer_test locks the two together.
     */
    const FRACTIONS = [100, 90, 83.33333, 80, 75, 70, 66.66667, 60, 50, 40,
        33.33333, 30, 25, 20, 16.66667, 14.28571, 12.5, 11.11111, 10, 5, 0];

    /** @var int How many characters of the question text go into its name. */
    const NAME_LENGTH = 60;

    /** @var string Tag on every imported question, kept from the HRIA import API. */
    const TAG = 'generat-automat';

    /** @var string[] Answer texts read as "true" in a true/false question. */
    const TRUE_WORDS = ['true', 'adevarat', 'adevărat'];

    /** @var string[] Answer texts read as "false" in a true/false question. */
    const FALSE_WORDS = ['false', 'fals'];

    /**
     * The question list a delivery carries.
     *
     * The service nests it under result; the HRIA shape has it at the top.
     *
     * @param array $payload The decoded callback body.
     * @return array|null The questions as delivered, or null if there is no list.
     */
    public static function extract_questions(array $payload): ?array {
        $questions = $payload['result']['questions'] ?? $payload['questions'] ?? null;
        return is_array($questions) ? array_values($questions) : null;
    }

    /**
     * Import the delivered questions into the job's quiz.
     *
     * All or nothing: either every usable question is saved and placed in the
     * quiz, or nothing is left behind and an exception says why. Unusable
     * questions are left out and reported, and fail the import only when none
     * is left.
     *
     * @param \stdClass $job The job record.
     * @param array $delivered The questions as delivered.
     * @return array{imported: int, skipped: array} skipped holds an {index, reason}
     *               entry per question left out, index counting from 0.
     * @throws \moodle_exception When the quiz is gone, nothing is usable or core refuses the import.
     */
    public static function import(\stdClass $job, array $delivered): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        require_once($CFG->dirroot . '/question/format.php');
        require_once($CFG->dirroot . '/question/format/xml/format.php');

        $cm = $job->quizid ? get_coursemodule_from_instance('quiz', $job->quizid, $job->courseid) : false;
        if (!$cm) {
            throw new \moodle_exception('errorquizmissing', 'local_aiquiz');
        }
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
        $quiz->cmid = $cm->id;

        if (!$delivered) {
            throw new \moodle_exception('errornoquestionsdelivered', 'local_aiquiz');
        }

        $questions = [];
        $skipped = [];
        foreach (array_values($delivered) as $index => $raw) {
            $question = self::normalise($raw, $reason);
            if ($question === null) {
                $skipped[] = ['index' => $index, 'reason' => $reason];
            } else {
                $questions[] = $question;
            }
        }
        if (!$questions) {
            throw new \moodle_exception('errornovalidquestions', 'local_aiquiz', '', count($delivered));
        }

        $transaction = $DB->start_delegated_transaction();
        try {
            $questionids = self::save_questions($questions, $job->requestid, $cm);
            foreach ($questionids as $questionid) {
                quiz_add_quiz_question($questionid, $quiz);
            }
            \mod_quiz\quiz_settings::create($quiz->id)->get_grade_calculator()->recompute_quiz_sumgrades();
            $transaction->allow_commit();
        } catch (\Throwable $e) {
            // The core importer rolls back by itself when a question type
            // refuses to save, which disposes of this transaction as well, and
            // then there is nothing left to undo here.
            if (!$transaction->is_disposed()) {
                $transaction->rollback($e);
            }
            throw $e;
        }

        return ['imported' => count($questionids), 'skipped' => $skipped];
    }

    /**
     * Bring one delivered question onto the shape build_xml() takes.
     *
     * @param mixed $question One entry of the delivered list.
     * @param string|null $reason Set to why the question cannot be used, when it cannot.
     * @return array|null {type, text, single, answers: [{text, fraction, feedback}],
     *                    difficulty, explanation}, fractions as percentages core
     *                    accepts, or null when the question cannot be used.
     */
    public static function normalise($question, ?string &$reason = null): ?array {
        $reason = null;
        try {
            return self::parse($question);
        } catch (\InvalidArgumentException $e) {
            $reason = $e->getMessage();
            return null;
        }
    }

    /**
     * Write normalised questions out as a Moodle XML file.
     *
     * There is no category entry: the importer is pointed at the quiz's own
     * default category instead.
     *
     * @param array $questions Questions as normalise() returns them.
     * @param string $requestid The job's requestid, tagged on every question.
     * @return string
     */
    public static function build_xml(array $questions, string $requestid): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n<quiz>\n";
        foreach (array_values($questions) as $index => $question) {
            $xml .= self::question_xml($question, $index + 1, $requestid);
        }
        return $xml . "</quiz>\n";
    }

    /**
     * Save the questions into the quiz's default category with core's XML importer.
     *
     * @param array $questions Questions as normalise() returns them.
     * @param string $requestid The job's requestid.
     * @param \stdClass $cm The quiz's course module.
     * @return int[] The new question ids, in delivery order.
     * @throws \moodle_exception When the importer refuses the file.
     */
    protected static function save_questions(array $questions, string $requestid, \stdClass $cm): array {
        $context = \context_module::instance($cm->id);
        $category = question_get_default_category($context->id, true);

        $file = make_request_directory() . '/questions.xml';
        file_put_contents($file, self::build_xml($questions, $requestid));

        $format = new \qformat_xml();
        $format->setCategory($category);
        $format->setContexts([$context]);
        $format->setCourse(get_course($cm->course));
        $format->setFilename($file);
        $format->setRealfilename('questions.xml');
        $format->setMatchgrades('nearest');
        $format->setCatfromfile(false);
        $format->setContextfromfile(false);
        $format->setStoponerror(true);
        $format->set_display_progress(false);

        // The importer reports problems by echoing them rather than returning
        // them, so capture what it prints to explain a failure.
        ob_start();
        try {
            $ok = $format->importpreprocess() && $format->importprocess() && $format->importpostprocess();
        } finally {
            $output = trim(preg_replace('/\s+/', ' ', strip_tags(ob_get_clean())));
        }

        // A question type's notice also ends the import early, after saving
        // only some of the questions, and still reports success.
        if (!$ok || count($format->questionids) !== count($questions)) {
            throw new \moodle_exception('errorimportfailed', 'local_aiquiz', '', $output ?: '-');
        }
        return $format->questionids;
    }

    /**
     * Validate one delivered question and bring it onto the normalised shape.
     *
     * @param mixed $question One entry of the delivered list.
     * @return array See normalise().
     * @throws \InvalidArgumentException With the reason the question cannot be used.
     */
    protected static function parse($question): array {
        if (!is_array($question)) {
            throw new \InvalidArgumentException('not a JSON object');
        }

        $type = $question['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('unknown type ' . json_encode($type)
                . ', expected one of ' . implode(', ', self::TYPES));
        }

        $text = self::clean($question['stem'] ?? $question['text'] ?? null);
        if ($text === '') {
            throw new \InvalidArgumentException('no question text');
        }

        // Absent means the service left it to us, so it is worked out from
        // the answers below.
        $single = isset($question['single_answer']) ? !empty($question['single_answer']) : null;

        if ($type === 'truefalse') {
            $answers = self::truefalse_answers($question);
        } else if (!empty($question['answers']) && is_array($question['answers'])) {
            $answers = self::fraction_answers($question['answers']);
            $single = $single ?? max(array_column($answers, 'fraction')) == 100;
        } else if (!empty($question['options']) && is_array($question['options'])) {
            $answers = self::option_answers($question['options'], $type, $single);
            $single = $single ?? count(array_filter(array_column($answers, 'fraction'), fn($f) => $f > 0)) <= 1;
        } else if ($type === 'shortanswer' && isset($question['correct_answer'])) {
            $answers = self::shortanswer_answers($question['correct_answer']);
        } else {
            throw new \InvalidArgumentException('no answers');
        }

        self::check_grading($type, (bool) $single, $answers);

        return [
            'type' => $type,
            'text' => $text,
            'single' => (bool) $single,
            'answers' => $answers,
            'difficulty' => self::clean($question['difficulty'] ?? ''),
            'explanation' => self::clean($question['explanation'] ?? ''),
        ];
    }

    /**
     * Refuse what core would refuse, or save as a question nobody can get right.
     *
     * Core treats some of these as fatal to the whole import, not just to the
     * one question, so they have to be caught before it sees them.
     *
     * @param string $type The question type.
     * @param bool $single Whether a multiple choice question takes one answer.
     * @param array $answers Normalised answers.
     * @throws \InvalidArgumentException With the reason the grading does not work.
     */
    protected static function check_grading(string $type, bool $single, array $answers): void {
        $fractions = array_column($answers, 'fraction');

        if ($type === 'multichoice') {
            if (count($answers) < 2) {
                throw new \InvalidArgumentException('multiple choice needs at least 2 answers, got '
                    . count($answers));
            }
            if ($single && max($fractions) != 100) {
                throw new \InvalidArgumentException('no answer is worth 100%');
            }
            $total = array_sum(array_filter($fractions, fn($f) => $f > 0));
            if (!$single && round($total / 100, 2) != 1) {
                throw new \InvalidArgumentException("the correct answers add up to {$total}%, not 100%");
            }
        }

        if ($type === 'shortanswer' && max($fractions) != 100) {
            throw new \InvalidArgumentException('short answer needs an answer worth 100%');
        }
    }

    /**
     * The two answers of a true/false question.
     *
     * The service sends the right value as correct_answer. The HRIA shape
     * has an answers list instead, read by the text of the entry marked right.
     *
     * @param array $question The delivered question.
     * @return array Normalised answers, "true" first.
     * @throws \InvalidArgumentException When the right value cannot be read.
     */
    protected static function truefalse_answers(array $question): array {
        $correct = null;
        if (array_key_exists('correct_answer', $question)) {
            $correct = self::read_bool($question['correct_answer']);
        } else {
            $entries = $question['answers'] ?? $question['options'] ?? [];
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $right = isset($entry['fraction'])
                    ? is_numeric($entry['fraction']) && $entry['fraction'] > 0
                    : !empty($entry['is_correct']);
                if ($right) {
                    $correct = self::read_bool($entry['text'] ?? null);
                    break;
                }
            }
        }

        if ($correct === null) {
            throw new \InvalidArgumentException('true/false question without a readable correct answer');
        }

        return [
            ['text' => 'true', 'fraction' => $correct ? 100 : 0, 'feedback' => ''],
            ['text' => 'false', 'fraction' => $correct ? 0 : 100, 'feedback' => ''],
        ];
    }

    /**
     * Answers that carry their own fractions, as the HRIA shape sends them.
     *
     * @param array $entries The delivered answers.
     * @return array Normalised answers.
     * @throws \InvalidArgumentException When an answer has no text or an impossible fraction.
     */
    protected static function fraction_answers(array $entries): array {
        $answers = [];
        foreach (array_values($entries) as $index => $entry) {
            $text = is_array($entry) ? self::clean($entry['text'] ?? null) : '';
            if ($text === '') {
                throw new \InvalidArgumentException("answer {$index} has no text");
            }
            $fraction = $entry['fraction'] ?? 0;
            if (!is_numeric($fraction) || $fraction < -100 || $fraction > 100) {
                throw new \InvalidArgumentException("answer {$index} has fraction "
                    . json_encode($fraction) . ', expected a percentage between -100 and 100');
            }
            $answers[] = [
                'text' => $text,
                'fraction' => self::snap((float) $fraction),
                'feedback' => self::clean($entry['feedback'] ?? ''),
            ];
        }
        return $answers;
    }

    /**
     * Answers flagged right or wrong, as the service sends them, given fractions.
     *
     * A single answer question gives the right answer full marks and the wrong
     * ones nothing. With several right answers the marks are split between
     * them, and each wrong one takes an equal share away, so ticking every box
     * scores nothing rather than full marks.
     *
     * @param array $options The delivered options.
     * @param string $type The question type.
     * @param bool|null $single The service's single_answer flag, if it sent one.
     * @return array Normalised answers.
     * @throws \InvalidArgumentException When an option has no text or none is right.
     */
    protected static function option_answers(array $options, string $type, ?bool $single): array {
        $texts = [];
        $flags = [];
        foreach (array_values($options) as $index => $option) {
            $text = is_array($option) ? self::clean($option['text'] ?? null) : '';
            if ($text === '') {
                throw new \InvalidArgumentException("option {$index} has no text");
            }
            $texts[] = $text;
            $flags[] = !empty($option['is_correct']);
        }

        $right = count(array_filter($flags));
        if ($right === 0) {
            throw new \InvalidArgumentException('no option is marked correct');
        }
        $wrong = count($flags) - $right;
        $single = $single ?? $right === 1;

        $answers = [];
        foreach ($texts as $index => $text) {
            if ($type === 'shortanswer') {
                // Every accepted spelling is worth full marks; a wrong option
                // means nothing for an answer the student types.
                if ($flags[$index]) {
                    $answers[] = ['text' => $text, 'fraction' => 100, 'feedback' => ''];
                }
                continue;
            }
            if ($single) {
                $fraction = $flags[$index] ? 100 : 0;
            } else {
                $fraction = $flags[$index] ? 100 / $right : -100 / $wrong;
            }
            $answers[] = ['text' => $text, 'fraction' => self::snap($fraction), 'feedback' => ''];
        }
        return $answers;
    }

    /**
     * Accepted answers to a short answer question given as a value or a list.
     *
     * @param mixed $value One accepted answer, or a list of them.
     * @return array Normalised answers.
     * @throws \InvalidArgumentException When no accepted answer has text.
     */
    protected static function shortanswer_answers($value): array {
        $answers = [];
        foreach (is_array($value) ? $value : [$value] as $accepted) {
            $text = self::clean($accepted);
            if ($text !== '') {
                $answers[] = ['text' => $text, 'fraction' => 100, 'feedback' => ''];
            }
        }
        if (!$answers) {
            throw new \InvalidArgumentException('short answer without a correct answer');
        }
        return $answers;
    }

    /**
     * The XML for one normalised question.
     *
     * @param array $question A question as normalise() returns it.
     * @param int $number Its position in the delivery, from 1.
     * @param string $requestid The job's requestid.
     * @return string
     */
    protected static function question_xml(array $question, int $number, string $requestid): string {
        $name = sprintf('%03d %s', $number, self::shorten($question['text']));

        $xml = "  <question type=\"{$question['type']}\">\n"
            . '    <name><text>' . self::cdata($name) . "</text></name>\n"
            . '    <questiontext format="html"><text>' . self::cdata(self::html($question['text']))
            . "</text></questiontext>\n"
            . '    <generalfeedback format="html"><text>' . self::cdata(self::html($question['explanation']))
            . "</text></generalfeedback>\n"
            . "    <defaultgrade>1</defaultgrade>\n";

        if ($question['type'] === 'multichoice') {
            $xml .= '    <single>' . ($question['single'] ? 'true' : 'false') . "</single>\n"
                . "    <shuffleanswers>true</shuffleanswers>\n";
        }
        if ($question['type'] === 'shortanswer') {
            $xml .= "    <usecase>0</usecase>\n";
        }

        foreach ($question['answers'] as $answer) {
            // A short answer is compared with what the student types, so it
            // stays exactly as delivered rather than becoming HTML.
            $text = $question['type'] === 'shortanswer' ? $answer['text'] : self::html($answer['text']);
            $xml .= '    <answer fraction="' . round($answer['fraction'], 5) . '" format="html">'
                . '<text>' . self::cdata($text) . '</text>'
                . '<feedback format="html"><text>' . self::cdata(self::html($answer['feedback'])) . '</text></feedback>'
                . "</answer>\n";
        }

        $xml .= "    <tags>\n";
        foreach (array_filter([$question['difficulty'], self::TAG, 'quiz-' . $requestid]) as $tag) {
            $xml .= '      <tag><text>' . self::cdata($tag) . "</text></tag>\n";
        }
        return $xml . "    </tags>\n  </question>\n";
    }

    /**
     * Snap a percentage to the closest fraction core accepts.
     *
     * @param float $percent
     * @return float
     */
    protected static function snap(float $percent): float {
        $best = 0.0;
        foreach (self::FRACTIONS as $allowed) {
            foreach ([$allowed, -$allowed] as $candidate) {
                if (abs($percent - $candidate) < abs($percent - $best)) {
                    $best = (float) $candidate;
                }
            }
        }
        return $best;
    }

    /**
     * Read a true/false value the way the service or a teacher might write it.
     *
     * @param mixed $value
     * @return bool|null Null when the value is neither.
     */
    protected static function read_bool($value): ?bool {
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === 0) {
            return (bool) $value;
        }
        if (is_string($value)) {
            $word = mb_strtolower(trim($value));
            if (in_array($word, self::TRUE_WORDS, true)) {
                return true;
            }
            if (in_array($word, self::FALSE_WORDS, true)) {
                return false;
            }
        }
        return null;
    }

    /**
     * A delivered text value as a trimmed string, '' for anything that is not text.
     *
     * Control characters are dropped: XML does not allow them, and one would
     * make the whole file unreadable to the importer, not just its question.
     *
     * @param mixed $value
     * @return string
     */
    protected static function clean($value): string {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return '';
        }
        return trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value));
    }

    /**
     * Plain text as HTML.
     *
     * The service writes plain text, so it is escaped: a stem such as
     * "is 3 < 5?" would otherwise be read as the start of a broken tag.
     *
     * @param string $text
     * @return string
     */
    protected static function html(string $text): string {
        return nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
    }

    /**
     * The start of a question's text, on one line, for its name.
     *
     * @param string $text
     * @return string
     */
    protected static function shorten(string $text): string {
        $text = preg_replace('/\s+/u', ' ', $text);
        if (mb_strlen($text) <= self::NAME_LENGTH) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, self::NAME_LENGTH - 1)) . '…';
    }

    /**
     * Wrap text in a CDATA section, splitting any "]]>" it contains.
     *
     * @param string $text
     * @return string
     */
    protected static function cdata(string $text): string {
        return '<![CDATA[' . str_replace(']]>', ']]]]><![CDATA[>', $text) . ']]>';
    }
}
