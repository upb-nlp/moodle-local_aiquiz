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
 * Teacher dashboard: every generated quiz in the course and its state.
 *
 * Quizzes are created hidden; this page is where the teacher reviews a
 * draft and publishes it (or unpublishes / deletes it again).
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_aiquiz\manager;

$courseid = required_param('courseid', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$jobid = optional_param('jobid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('local/aiquiz:generate', $context);

$baseurl = new moodle_url('/local/aiquiz/index.php', ['courseid' => $course->id]);

$PAGE->set_url($baseurl);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_aiquiz'));
$PAGE->set_heading($course->fullname);

// Row actions. Publish and unpublish run straight away; delete asks first.
if ($action && $jobid) {
    $job = $DB->get_record('local_aiquiz_jobs',
        ['id' => $jobid, 'courseid' => $course->id], '*', MUST_EXIST);

    if ($action === 'publish') {
        require_sesskey();
        manager::publish($job);
        redirect($baseurl, get_string('quizpublished', 'local_aiquiz'), null,
            \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'unpublish') {
        require_sesskey();
        manager::unpublish($job);
        redirect($baseurl, get_string('quizunpublished', 'local_aiquiz'), null,
            \core\output\notification::NOTIFY_SUCCESS);

    } else if ($action === 'delete') {
        if ($confirm) {
            require_sesskey();
            manager::delete($job);
            redirect($baseurl, get_string('quizdeleted', 'local_aiquiz'), null,
                \core\output\notification::NOTIFY_SUCCESS);
        }

        $quizname = $DB->get_field('quiz', 'name', ['id' => $job->quizid]);
        if ($quizname === false) {
            $params = json_decode($job->params ?? '', true);
            $quizname = $params['quizname'] ?? get_string('pluginname', 'local_aiquiz');
        }
        $confirmurl = new moodle_url($baseurl, [
            'action' => 'delete', 'jobid' => $job->id, 'confirm' => 1, 'sesskey' => sesskey(),
        ]);

        echo $OUTPUT->header();
        echo $OUTPUT->heading(get_string('pluginname', 'local_aiquiz'));
        echo $OUTPUT->confirm(get_string('confirmdeletequiz', 'local_aiquiz', $quizname),
            $confirmurl, $baseurl);
        echo $OUTPUT->footer();
        die();
    }
}

$jobs = manager::get_course_jobs($course->id);

// While something is generating, refresh so the state flips without a reload.
foreach ($jobs as $job) {
    if ($job->status === manager::STATUS_PENDING) {
        $PAGE->set_periodic_refresh_delay(20);
        break;
    }
}

$statebadges = [
    manager::STATE_GENERATING => 'badge-warning',
    manager::STATE_READY => 'badge-info',
    manager::STATE_PUBLISHED => 'badge-success',
    manager::STATE_FAILED => 'badge-danger',
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_aiquiz'));

$createurl = new moodle_url('/local/aiquiz/create.php', ['courseid' => $course->id]);
echo html_writer::div(
    $OUTPUT->single_button($createurl, get_string('generatenewquiz', 'local_aiquiz'), 'get',
        ['type' => single_button::BUTTON_PRIMARY]),
    'mb-3');

if (empty($jobs)) {
    echo $OUTPUT->notification(get_string('noquizzesyet', 'local_aiquiz'), 'info', false);
    echo $OUTPUT->footer();
    die();
}

// Summary line: how many jobs are in each state.
$counts = array_count_values(array_map([manager::class, 'get_state'], $jobs));
$parts = [];
foreach ($statebadges as $state => $unused) {
    if (!empty($counts[$state])) {
        $parts[] = get_string('state' . $state, 'local_aiquiz') . ': ' . $counts[$state];
    }
}
echo html_writer::div(implode(' · ', $parts), 'mb-3 text-muted');

$table = new html_table();
$table->head = [
    get_string('quizname', 'local_aiquiz'),
    get_string('questions', 'local_aiquiz'),
    get_string('createdby', 'local_aiquiz'),
    get_string('status'),
    get_string('actions'),
];
$table->attributes['class'] = 'generaltable';

foreach ($jobs as $job) {
    $state = manager::get_state($job);

    // Name cell: link into the quiz while it exists, plus the error if any.
    if ($job->cmid) {
        $quizurl = $state === manager::STATE_PUBLISHED
            ? new moodle_url('/mod/quiz/view.php', ['id' => $job->cmid])
            : new moodle_url('/mod/quiz/edit.php', ['cmid' => $job->cmid]);
        $namecell = html_writer::link($quizurl, format_string($job->quizname));
    } else {
        $params = json_decode($job->params ?? '', true);
        $namecell = format_string($params['quizname'] ?? '');
    }
    if ($state === manager::STATE_FAILED && !empty($job->errormessage)) {
        $namecell .= html_writer::div(s($job->errormessage), 'text-danger small');
    }

    $createdcell = fullname($job)
        . html_writer::div(
            userdate($job->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'text-muted small');

    $statecell = html_writer::span(get_string('state' . $state, 'local_aiquiz'),
        'badge ' . $statebadges[$state]);

    // Actions depend on the state.
    $actions = [];
    $actionurl = function (string $action) use ($baseurl, $job): moodle_url {
        return new moodle_url($baseurl, [
            'action' => $action, 'jobid' => $job->id, 'sesskey' => sesskey(),
        ]);
    };
    if ($job->cmid && ($state === manager::STATE_READY || $state === manager::STATE_PUBLISHED)) {
        $actions[] = html_writer::link(
            new moodle_url('/mod/quiz/edit.php', ['cmid' => $job->cmid]),
            get_string('review', 'local_aiquiz'), ['class' => 'btn btn-secondary btn-sm']);
    }
    if ($state === manager::STATE_READY) {
        $actions[] = html_writer::link($actionurl('publish'),
            get_string('publish', 'local_aiquiz'), ['class' => 'btn btn-primary btn-sm']);
    }
    if ($state === manager::STATE_PUBLISHED) {
        $actions[] = html_writer::link($actionurl('unpublish'),
            get_string('unpublish', 'local_aiquiz'), ['class' => 'btn btn-secondary btn-sm']);
    }
    if ($state !== manager::STATE_PUBLISHED) {
        $deleteurl = new moodle_url($baseurl, ['action' => 'delete', 'jobid' => $job->id]);
        $actions[] = html_writer::link($deleteurl, get_string('delete'),
            ['class' => 'btn btn-outline-danger btn-sm']);
    }

    $table->data[] = [
        $namecell,
        manager::count_questions($job),
        $createdcell,
        $statecell,
        implode(' ', $actions),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
