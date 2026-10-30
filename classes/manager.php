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
 * The generation job workflow: create, publish, unpublish, delete.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

use local_aiquiz\form\generate_form;

/**
 * Creates generated quizzes as hidden drafts and manages their lifecycle.
 *
 * A quiz stays hidden (visible only to teachers) until the requesting
 * teacher reviews the generated questions and publishes it, which simply
 * flips the course module's visibility.
 */
class manager {
    /** @var string Waiting for the generation service. */
    const STATUS_PENDING = 'pending';
    /** @var string Questions were generated successfully. */
    const STATUS_DONE = 'done';
    /** @var string The generation service reported an error. */
    const STATUS_FAILED = 'failed';

    /** @var string Dashboard state: questions are being generated. */
    const STATE_GENERATING = 'generating';
    /** @var string Dashboard state: generated, waiting for teacher review. */
    const STATE_READY = 'ready';
    /** @var string Dashboard state: visible to students. */
    const STATE_PUBLISHED = 'published';
    /** @var string Dashboard state: generation failed. */
    const STATE_FAILED = 'failed';

    /**
     * Create the hidden quiz and the job row for a submitted generation form.
     *
     * @param \stdClass $formdata Data returned by generate_form::get_data().
     * @param \stdClass $course The course the quiz belongs to.
     * @return \stdClass The new job record.
     */
    public static function create_job(\stdClass $formdata, \stdClass $course): \stdClass {
        global $DB, $USER;

        $quizid = self::create_hidden_quiz($formdata, $course);

        $job = new \stdClass();
        $job->requestid = \core\uuid::generate();
        $job->courseid = $course->id;
        $job->quizid = $quizid;
        $job->userid = $USER->id;
        $job->status = self::STATUS_PENDING;
        $job->published = 0;
        $job->params = json_encode(self::build_params($formdata));
        $job->timecreated = time();
        $job->timemodified = $job->timecreated;
        $job->id = $DB->insert_record('local_aiquiz_jobs', $job);

        // Hand the transfer to cron. The adhoc task builds the request and
        // POSTs it to the generation service out of the teacher's web request,
        // so a slow request or many large files never times out the page. The
        // quiz stays a hidden empty draft (status pending) until the service
        // pulls the files and calls back with the questions.
        $task = new \local_aiquiz\task\generate_task();
        $task->set_custom_data(['jobid' => $job->id]);
        $task->set_userid($USER->id);
        \core\task\manager::queue_adhoc_task($task);

        return $job;
    }

    /**
     * Build the request payload sent to the generation service.
     *
     * The body is small: it never carries the file bytes. Each file is
     * described by a tokenpluginfile.php URL that lets the service pull the
     * content itself.
     *
     * Mind what that token is: get_user_key('core_files', $userid), created
     * with no validuntil and no iprestriction, and reused across jobs. It
     * authenticates the teacher rather than the file, so it reaches anything
     * they can read through tokenpluginfile.php, it does not expire, and the
     * same value leaves the site in every request. Minting a short-lived key
     * per job would need the URL built by hand, since make_pluginfile_url()
     * does not expose get_user_key()'s $validuntil.
     *
     * @param \stdClass $job The job record.
     * @return array The JSON-ready request body.
     */
    public static function build_request(\stdClass $job): array {
        $params = json_decode($job->params ?? '', true) ?: [];
        $files = file_lister::get_files_by_ids($params['fileids'] ?? [], $job->courseid);

        $sourcefiles = [];
        foreach ($files as $file) {
            // Passing includetoken = userid mints a tokenpluginfile.php URL scoped to
            // the teacher, so the pull is authorised as them.
            $url = \moodle_url::make_pluginfile_url(
                $file->get_contextid(),
                $file->get_component(),
                $file->get_filearea(),
                $file->get_itemid(),
                $file->get_filepath(),
                $file->get_filename(),
                true,
                $job->userid
            );
            $sourcefiles[] = [
                'file_id' => (string) $file->get_id(),
                'filename' => $file->get_filename(),
                'mime_type' => $file->get_mimetype(),
                'url' => $url->out(false),
                // The service treats this as optional. Moodle's contenthash is
                // SHA-1, so it cannot be reused, and hashing every file just to
                // fill the field would mean reading them all for nothing.
                'checksum_sha256' => null,
            ];
        }

        $types = self::build_question_types($params['questions'] ?? []);

        return [
            'request_id' => $job->requestid,
            'callback_url' => self::callback_url($job->requestid),
            'generation' => [
                'total_questions' => array_sum(array_column($types, 'count')),
                'language' => $params['language'] ?? '',
                'learning_objectives' => $params['learning_objectives'] ?? '',
                'question_types' => $types,
            ],
            'source_files' => $sourcefiles,
        ];
    }

    /**
     * Where the generation service delivers a finished job.
     *
     * The plugin's own callback.php, which imports the questions into the
     * job's quiz. The per-job key it carries is what authenticates the
     * delivery: the configured token proves us to the service, not the
     * service to us.
     *
     * @param string $requestid The job's requestid.
     * @return string
     */
    public static function callback_url(string $requestid): string {
        $url = new \moodle_url('/local/aiquiz/callback.php', ['key' => self::callback_key($requestid)]);
        return $url->out(false);
    }

    /**
     * The secret that proves a callback belongs to a job we created.
     *
     * @param string $requestid The job's requestid.
     * @return string
     */
    public static function callback_key(string $requestid): string {
        global $CFG;
        return hash_hmac('sha256', 'local_aiquiz_callback|' . $requestid, $CFG->siteidentifier);
    }

    /**
     * Collapse the form's question types onto the ones the service accepts.
     *
     * The service knows three types (multichoice, truefalse, shortanswer) and
     * has no notion of single versus multiple correct answers: it decides that
     * itself and reports it back per question. The form offers single- and
     * multi-answer multiple choice separately, so both fold into one
     * multichoice entry here and their counts add up. It also has no field for
     * the difficulty split, so that part of the form does not travel at all.
     *
     * @param array $questions The 'questions' entries from the job params.
     * @return array question_types entries, one per distinct service type.
     */
    protected static function build_question_types(array $questions): array {
        $bytype = [];

        foreach ($questions as $q) {
            $type = $q['type'] ?? '';
            if ($type === '') {
                continue;
            }

            if (!isset($bytype[$type])) {
                $bytype[$type] = ['type' => $type, 'count' => 0];
            }
            $bytype[$type]['count'] += (int) ($q['count'] ?? 0);

            // Only some types take this, and the two multiple choice rows can
            // disagree once merged; the larger value is the safe one, since a
            // question can always use fewer options than requested.
            $answers = (int) ($q['answers_per_question'] ?? 0);
            if ($answers > 0) {
                $bytype[$type]['answers_per_question'] =
                    max($answers, $bytype[$type]['answers_per_question'] ?? 0);
            }
        }

        return array_values($bytype);
    }

    /**
     * Make the quiz visible to students and record the approval.
     *
     * @param \stdClass $job The job record.
     */
    public static function publish(\stdClass $job): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        if ($job->status !== self::STATUS_DONE) {
            throw new \moodle_exception('errorjobnotdone', 'local_aiquiz');
        }

        $cm = get_coursemodule_from_instance('quiz', $job->quizid, $job->courseid, false, MUST_EXIST);
        set_coursemodule_visible($cm->id, 1);
        \core\event\course_module_updated::create_from_cm($cm)->trigger();

        $job->published = 1;
        $job->timepublished = time();
        $job->timemodified = time();
        $DB->update_record('local_aiquiz_jobs', $job);
    }

    /**
     * Hide a published quiz from students again.
     *
     * @param \stdClass $job The job record.
     */
    public static function unpublish(\stdClass $job): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        $cm = get_coursemodule_from_instance('quiz', $job->quizid, $job->courseid, false, MUST_EXIST);
        set_coursemodule_visible($cm->id, 0);
        \core\event\course_module_updated::create_from_cm($cm)->trigger();

        $job->published = 0;
        $job->timemodified = time();
        $DB->update_record('local_aiquiz_jobs', $job);
    }

    /**
     * Delete the quiz activity (if it still exists) and the job row.
     *
     * @param \stdClass $job The job record.
     */
    public static function delete(\stdClass $job): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/lib.php');

        if ($job->quizid && ($cm = get_coursemodule_from_instance('quiz', $job->quizid, $job->courseid))) {
            course_delete_module($cm->id);
        }
        $DB->delete_records('local_aiquiz_jobs', ['id' => $job->id]);
    }

    /**
     * All generation jobs in a course, newest first.
     *
     * Each record carries the quiz name, the course module id and the
     * creator's name fields (so fullname() works on it). quizname and cmid
     * are null when the quiz activity was deleted outside the plugin.
     *
     * @param int $courseid
     * @return \stdClass[]
     */
    public static function get_course_jobs(int $courseid): array {
        global $DB;

        $namefields = \core_user\fields::for_name()->get_sql('u')->selects;

        $sql = "SELECT j.*, q.name AS quizname, cm.id AS cmid $namefields
                  FROM {local_aiquiz_jobs} j
             LEFT JOIN {quiz} q ON q.id = j.quizid
             LEFT JOIN {modules} m ON m.name = 'quiz'
             LEFT JOIN {course_modules} cm ON cm.instance = j.quizid
                       AND cm.module = m.id AND cm.course = j.courseid
             LEFT JOIN {user} u ON u.id = j.userid
                 WHERE j.courseid = :courseid
              ORDER BY j.timecreated DESC";

        return $DB->get_records_sql($sql, ['courseid' => $courseid]);
    }

    /**
     * Map a job onto the state shown on the dashboard.
     *
     * @param \stdClass $job The job record.
     * @return string One of the STATE_* constants.
     */
    public static function get_state(\stdClass $job): string {
        if ($job->status === self::STATUS_FAILED) {
            return self::STATE_FAILED;
        }
        if ($job->status === self::STATUS_DONE) {
            return $job->published ? self::STATE_PUBLISHED : self::STATE_READY;
        }
        return self::STATE_GENERATING;
    }

    /**
     * Total number of questions requested for a job.
     *
     * @param \stdClass $job The job record.
     * @return int
     */
    public static function count_questions(\stdClass $job): int {
        $params = json_decode($job->params ?? '', true);
        if (empty($params['questions'])) {
            return 0;
        }
        return array_sum(array_column($params['questions'], 'count'));
    }

    /**
     * Turn the form submission into the request payload stored on the job.
     *
     * @param \stdClass $formdata Data returned by generate_form::get_data().
     * @return array
     */
    protected static function build_params(\stdClass $formdata): array {
        $questions = [];
        foreach (generate_form::get_types() as $key => $meta) {
            if (empty($formdata->{"enable{$key}"})) {
                continue;
            }
            $questions[] = [
                'type' => $meta['type'],
                'single' => $meta['single'],
                'count' => (int) $formdata->{"count{$key}"},
                'answers_per_question' => $meta['answers']
                    ? (int) $formdata->{"answers{$key}"} : null,
                'difficulty' => [
                    'easy' => (int) $formdata->{"difficulty{$key}"}['easy'],
                    'medium' => (int) $formdata->{"difficulty{$key}"}['medium'],
                    'hard' => (int) $formdata->{"difficulty{$key}"}['hard'],
                ],
            ];
        }

        return [
            'quizname' => $formdata->quizname,
            'language' => $formdata->language,
            'learning_objectives' => trim($formdata->learningobjectives ?? ''),
            'fileids' => array_map('intval', $formdata->fileids ?? []),
            'questions' => $questions,
        ];
    }

    /**
     * Create the empty quiz activity, hidden from students.
     *
     * @param \stdClass $formdata Data returned by generate_form::get_data().
     * @param \stdClass $course The course the quiz belongs to.
     * @return int The new quiz instance id.
     */
    protected static function create_hidden_quiz(\stdClass $formdata, \stdClass $course): int {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/course/modlib.php');

        $config = get_config('quiz');

        $moduleinfo = new \stdClass();
        $moduleinfo->modulename = 'quiz';
        $moduleinfo->module = $DB->get_field('modules', 'id', ['name' => 'quiz'], MUST_EXIST);
        $moduleinfo->course = $course->id;
        $moduleinfo->section = $formdata->section;
        $moduleinfo->name = $formdata->quizname;
        $moduleinfo->intro = '';
        $moduleinfo->introformat = FORMAT_HTML;

        // The draft state: hidden means only users who can view hidden
        // activities (teachers) see the quiz until it is published.
        $moduleinfo->visible = 0;
        $moduleinfo->visibleoncoursepage = 1;

        $moduleinfo->cmidnumber = '';
        $moduleinfo->groupmode = NOGROUPS;
        $moduleinfo->groupingid = 0;
        $moduleinfo->completion = COMPLETION_TRACKING_NONE;
        $moduleinfo->completionview = 0;
        $moduleinfo->completionexpected = 0;
        $moduleinfo->completiongradeitemnumber = null;

        // Quiz settings from the module's site defaults.
        $moduleinfo->timeopen = 0;
        $moduleinfo->timeclose = 0;
        $moduleinfo->timelimit = $config->timelimit ?? 0;
        $moduleinfo->overduehandling = $config->overduehandling ?? 'autosubmit';
        $moduleinfo->graceperiod = $config->graceperiod ?? 86400;
        $moduleinfo->preferredbehaviour = $config->preferredbehaviour ?? 'deferredfeedback';
        $moduleinfo->canredoquestions = $config->canredoquestions ?? 0;
        $moduleinfo->attempts = $config->attempts ?? 0;
        $moduleinfo->attemptonlast = $config->attemptonlast ?? 0;
        $moduleinfo->grademethod = $config->grademethod ?? 1;
        $moduleinfo->grade = $config->maximumgrade ?? 10;
        $moduleinfo->decimalpoints = $config->decimalpoints ?? 2;
        $moduleinfo->questiondecimalpoints = $config->questiondecimalpoints ?? -1;
        $moduleinfo->questionsperpage = $config->questionsperpage ?? 1;
        $moduleinfo->navmethod = $config->navmethod ?? 'free';
        $moduleinfo->shuffleanswers = $config->shuffleanswers ?? 1;
        $moduleinfo->browsersecurity = $config->browsersecurity ?? '-';
        $moduleinfo->delay1 = $config->delay1 ?? 0;
        $moduleinfo->delay2 = $config->delay2 ?? 0;
        $moduleinfo->showuserpicture = $config->showuserpicture ?? 0;
        $moduleinfo->showblocks = $config->showblocks ?? 0;
        $moduleinfo->quizpassword = '';
        $moduleinfo->subnet = '';
        $moduleinfo->seb_requiresafeexambrowser = 0;

        // Review options: translate the site default bitmasks into the
        // per-phase checkbox fields quiz_process_options() expects.
        $phases = [
            'during' => \mod_quiz\question\display_options::DURING,
            'immediately' => \mod_quiz\question\display_options::IMMEDIATELY_AFTER,
            'open' => \mod_quiz\question\display_options::LATER_WHILE_OPEN,
            'closed' => \mod_quiz\question\display_options::AFTER_CLOSE,
        ];
        $reviewfields = ['attempt', 'correctness', 'marks', 'maxmarks',
            'specificfeedback', 'generalfeedback', 'rightanswer', 'overallfeedback'];
        foreach ($reviewfields as $field) {
            $mask = (int) ($config->{'review' . $field} ?? 0);
            foreach ($phases as $suffix => $bit) {
                $moduleinfo->{$field . $suffix} = ($mask & $bit) ? 1 : 0;
            }
        }

        // Overall feedback: one empty entry, as the quiz form submits. The
        // quiz saves its draft area on itemid, so it has to be present; 0 means
        // there are no draft files to move.
        $moduleinfo->feedbacktext = [['text' => '', 'format' => FORMAT_HTML, 'itemid' => 0]];
        $moduleinfo->feedbackboundaries = [];

        $moduleinfo = add_moduleinfo($moduleinfo, $course);

        return (int) $moduleinfo->instance;
    }
}
