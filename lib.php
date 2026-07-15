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
 * Callbacks for the AI quiz generator plugin.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add the quiz generator link to the course navigation.
 *
 * Called by core for every course page. The node is added to the course
 * settings tree, which the secondary navigation renders in the More menu.
 *
 * @param navigation_node $navigation The course node to extend.
 * @param stdClass $course The course being viewed.
 * @param context_course $context The course context.
 */
function local_aiquiz_extend_navigation_course($navigation, $course, $context) {
    if (!has_capability('local/aiquiz:generate', $context)) {
        return;
    }

    $url = new moodle_url('/local/aiquiz/create.php', ['courseid' => $course->id]);

    $navigation->add(
        get_string('pluginname', 'local_aiquiz'),
        $url,
        navigation_node::TYPE_SETTING,
        null,
        'local_aiquiz',
        new pix_icon('i/star', '')
    );
}
