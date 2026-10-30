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
 * Quiz generation form page.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

$courseid = required_param('courseid', PARAM_INT);

$course = get_course($courseid);
$context = context_course::instance($course->id);

require_login($course);
require_capability('local/aiquiz:generate', $context);

$PAGE->set_url(new moodle_url('/local/aiquiz/create.php', ['courseid' => $course->id]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_title(get_string('pluginname', 'local_aiquiz'));
$PAGE->set_heading($course->fullname);

$dashboardurl = new moodle_url('/local/aiquiz/index.php', ['courseid' => $course->id]);

// Section names to offer as the destination for the generated quiz.
$sections = [];
$modinfo = get_fast_modinfo($course);
foreach ($modinfo->get_section_info_all() as $sectioninfo) {
    $sections[$sectioninfo->section] = get_section_name($course, $sectioninfo);
}

$form = new \local_aiquiz\form\generate_form(null, [
    'course' => $course,
    'files' => \local_aiquiz\file_lister::list_course_files($course->id),
    'sections' => $sections,
]);

if ($form->is_cancelled()) {
    redirect($dashboardurl);
} else if ($data = $form->get_data()) {
    // Create the hidden draft quiz and its job record, then land the
    // teacher on the dashboard where the job's progress is shown.
    \local_aiquiz\manager::create_job($data, $course);
    redirect(
        $dashboardurl,
        get_string('generationstarted', 'local_aiquiz'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'local_aiquiz'));
$form->display();
echo $OUTPUT->footer();
