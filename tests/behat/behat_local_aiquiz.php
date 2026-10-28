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
 * Behat page resolvers for the AI quiz generator.
 *
 * @package    local_aiquiz
 * @category   test
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Lets a feature say "the C1 local_aiquiz > Dashboard page" instead of a URL.
 */
class behat_local_aiquiz extends behat_base {

    /**
     * Map a page name onto its URL.
     *
     * Recognised page types:
     *     Dashboard   the generated quizzes of a course, by course shortname
     *     Generate    the generation form for a course, by course shortname
     *
     * @param string $type The page type from the feature file.
     * @param string $identifier A course shortname.
     * @return moodle_url
     */
    protected function resolve_page_instance_url(string $type, string $identifier): moodle_url {
        switch (strtolower($type)) {
            case 'dashboard':
                return new moodle_url('/local/aiquiz/index.php',
                    ['courseid' => $this->get_course_id($identifier)]);

            case 'generate':
                return new moodle_url('/local/aiquiz/create.php',
                    ['courseid' => $this->get_course_id($identifier)]);

            default:
                throw new Exception("Unrecognised local_aiquiz page type '{$type}'.");
        }
    }
}
