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
 * Tests for the course material listing and its course scoping.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

/**
 * Covers which files are offered for generation, and which are refused.
 *
 * The refusals matter most: the file ids arrive from a submitted form, so
 * get_files_by_ids() is the only thing standing between a tampered request
 * and another course's material.
 *
 * @covers \local_aiquiz\file_lister
 */
final class file_lister_test extends \advanced_testcase {

    /**
     * Attach a file to a module's content area, the way a resource stores one.
     *
     * @param \stdClass $cm A course module record.
     * @param string $filename
     * @param string $component Owning component, e.g. mod_resource.
     * @param string $filearea Owning file area, e.g. content.
     * @return \stored_file
     */
    protected function add_file(\stdClass $cm, string $filename,
            string $component = 'mod_resource', string $filearea = 'content'): \stored_file {
        return get_file_storage()->create_file_from_string([
            'contextid' => \context_module::instance($cm->id)->id,
            'component' => $component,
            'filearea' => $filearea,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $filename,
        ], 'Pretend this is a lecture handout.');
    }

    /**
     * Create a File resource in a course and return its course module.
     *
     * @param \stdClass $course
     * @return \stdClass
     */
    protected function add_resource(\stdClass $course): \stdClass {
        $instance = $this->getDataGenerator()
            ->get_plugin_generator('mod_resource')
            ->create_instance(['course' => $course->id]);
        return get_coursemodule_from_instance('resource', $instance->id, $course->id,
            false, MUST_EXIST);
    }

    /**
     * A file published as a File resource is offered.
     */
    public function test_resource_files_are_listed(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $file = $this->add_file($this->add_resource($course), 'chapter1.pdf');

        $listed = file_lister::list_course_files($course->id);

        $this->assertArrayHasKey($file->get_id(), $listed);
        $this->assertStringContainsString('chapter1.pdf', $listed[$file->get_id()]);
    }

    /**
     * A file published in a Folder resource is offered too.
     */
    public function test_folder_files_are_listed(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()
            ->get_plugin_generator('mod_folder')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('folder', $instance->id, $course->id,
            false, MUST_EXIST);
        $file = $this->add_file($cm, 'slides.pptx', 'mod_folder');

        $this->assertArrayHasKey($file->get_id(), file_lister::list_course_files($course->id));
    }

    /**
     * Student work must never be offered as generation input.
     */
    public function test_student_submissions_are_not_listed(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $instance = $this->getDataGenerator()
            ->get_plugin_generator('mod_assign')
            ->create_instance(['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $instance->id, $course->id,
            false, MUST_EXIST);
        $submission = $this->add_file($cm, 'homework.docx',
            'assignsubmission_file', 'submission_files');

        $this->assertArrayNotHasKey($submission->get_id(),
            file_lister::list_course_files($course->id));
    }

    /**
     * Directory placeholder rows are not offered as if they were files.
     */
    public function test_directory_placeholders_are_not_listed(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->add_resource($course);
        get_file_storage()->create_directory(
            \context_module::instance($cm->id)->id, 'mod_resource', 'content', 0, '/week1/');
        $this->add_file($cm, 'chapter1.pdf');

        foreach (file_lister::list_course_files($course->id) as $label) {
            $this->assertStringStartsNotWith('.', $label);
        }
    }

    /**
     * A course with no materials offers nothing rather than failing.
     */
    public function test_empty_course_lists_nothing(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();

        $this->assertSame([], file_lister::list_course_files($course->id));
    }

    /**
     * A file id from another course must not resolve, however it was supplied.
     *
     * This is the guard against a tampered form: the ids come from the browser
     * and nothing else re-checks them before the URLs are minted.
     */
    public function test_files_from_another_course_are_refused(): void {
        $this->resetAfterTest();

        $coursea = $this->getDataGenerator()->create_course();
        $courseb = $this->getDataGenerator()->create_course();
        $filea = $this->add_file($this->add_resource($coursea), 'course-a.pdf');
        $fileb = $this->add_file($this->add_resource($courseb), 'course-b.pdf');

        $this->assertSame([], file_lister::get_files_by_ids([$fileb->get_id()], $coursea->id));

        $mixed = file_lister::get_files_by_ids(
            [$filea->get_id(), $fileb->get_id()], $coursea->id);

        $this->assertCount(1, $mixed);
        $this->assertSame($filea->get_id(), $mixed[0]->get_id());
    }

    /**
     * A file id that does not exist is dropped, not fatal.
     */
    public function test_unknown_file_ids_are_dropped(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();

        $this->assertSame([], file_lister::get_files_by_ids([999999], $course->id));
        $this->assertSame([], file_lister::get_files_by_ids([], $course->id));
    }

    /**
     * Ids arriving as strings from the form are still matched.
     */
    public function test_file_ids_are_cast_before_matching(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $file = $this->add_file($this->add_resource($course), 'chapter1.pdf');

        $files = file_lister::get_files_by_ids([(string) $file->get_id()], $course->id);

        $this->assertCount(1, $files);
    }

    /**
     * The search covers the course context and every activity below it.
     */
    public function test_context_ids_include_the_course_and_its_activities(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $cm = $this->add_resource($course);

        $contextids = file_lister::get_course_contextids($course->id);

        $this->assertContains(\context_course::instance($course->id)->id, $contextids);
        $this->assertContains(\context_module::instance($cm->id)->id, $contextids);
    }
}
