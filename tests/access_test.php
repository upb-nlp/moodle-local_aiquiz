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
 * Tests for who may generate quizzes and who may see the entry point.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

/**
 * Covers the capability behind create.php, index.php and the course menu.
 *
 * Both pages call require_capability() with this one capability, so it is the
 * whole access control story for the plugin.
 *
 * @covers ::local_aiquiz_extend_navigation_course
 */
final class access_test extends \advanced_testcase {
    /** @var string The capability guarding every page in the plugin. */
    const CAPABILITY = 'local/aiquiz:generate';

    /**
     * The capability is installed and lives at the course level.
     */
    public function test_the_capability_is_defined_for_courses(): void {
        global $DB;

        $capability = $DB->get_record('capabilities', ['name' => self::CAPABILITY]);

        $this->assertNotFalse($capability, 'The capability is not installed.');
        $this->assertEquals(CONTEXT_COURSE, $capability->contextlevel);
        $this->assertSame('local_aiquiz', $capability->component);
    }

    /**
     * An editing teacher may generate quizzes; a student may not.
     */
    public function test_only_teachers_may_generate(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $outsider = $this->getDataGenerator()->create_user();

        $this->assertTrue(has_capability(self::CAPABILITY, $context, $teacher));
        $this->assertFalse(has_capability(self::CAPABILITY, $context, $student));
        $this->assertFalse(has_capability(self::CAPABILITY, $context, $outsider));
    }

    /**
     * A non editing teacher cannot generate either.
     *
     * The capability is cloned from mod/quiz:addinstance, so it should follow
     * who may add activities rather than who may merely teach.
     */
    public function test_a_non_editing_teacher_may_not_generate(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');

        $this->assertFalse(has_capability(self::CAPABILITY, $context, $teacher));
    }

    /**
     * The course menu offers the generator to a teacher.
     */
    public function test_the_course_navigation_node_is_added_for_teachers(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/aiquiz/lib.php');
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $navigation = new \navigation_node('Course');
        local_aiquiz_extend_navigation_course($navigation, $course, $context);

        $node = $navigation->find('local_aiquiz', \navigation_node::TYPE_SETTING);
        $this->assertNotFalse($node);
        $this->assertSame(get_string('pluginname', 'local_aiquiz'), $node->text);
    }

    /**
     * A student is never shown the entry point.
     */
    public function test_the_course_navigation_node_is_hidden_from_students(): void {
        global $CFG;
        require_once($CFG->dirroot . '/local/aiquiz/lib.php');
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));

        $navigation = new \navigation_node('Course');
        local_aiquiz_extend_navigation_course($navigation, $course, $context);

        $this->assertFalse($navigation->find('local_aiquiz', \navigation_node::TYPE_SETTING));
    }
}
