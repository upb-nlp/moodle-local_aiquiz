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
 * Lists the course materials that can be used as generation input.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

defined('MOODLE_INTERNAL') || die();

/**
 * Finds teacher-uploaded course materials suitable for question generation.
 */
class file_lister {

    /**
     * The course context plus every context below it.
     *
     * Course materials live in the context of the activity holding them
     * (a File or Folder resource), not in the course context itself.
     *
     * @param int $courseid
     * @return int[]
     */
    public static function get_course_contextids(int $courseid): array {
        $coursecontext = \context_course::instance($courseid);
        $contextids = [$coursecontext->id];
        foreach ($coursecontext->get_child_contexts() as $child) {
            $contextids[] = $child->id;
        }
        return $contextids;
    }

    /**
     * List the course materials, keyed by file id.
     *
     * Any file type is offered. Only files published as File or Folder
     * resources are returned, so student submissions and other uploads
     * are never listed.
     *
     * @param int $courseid
     * @return array<int,string> fileid => "filename (size)"
     */
    public static function list_course_files(int $courseid): array {
        global $DB;

        $contextids = self::get_course_contextids($courseid);
        if (empty($contextids)) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED, 'ctx');

        $sql = "SELECT id, filename, filesize
                  FROM {files}
                 WHERE contextid $insql
                   AND component IN ('mod_resource', 'mod_folder')
                   AND filearea = 'content'
                   AND filename <> '.'
              ORDER BY filename ASC";

        $result = [];
        foreach ($DB->get_records_sql($sql, $params) as $rec) {
            $result[$rec->id] = $rec->filename . ' (' . display_size($rec->filesize) . ')';
        }
        return $result;
    }

    /**
     * Load stored_file objects by id, restricted to this course's materials.
     *
     * Re-checks the course scope so a tampered form cannot reach files
     * belonging to another course.
     *
     * @param int[] $fileids
     * @param int $courseid
     * @return \stored_file[]
     */
    public static function get_files_by_ids(array $fileids, int $courseid): array {
        if (empty($fileids)) {
            return [];
        }

        $allowed = array_keys(self::list_course_files($courseid));
        $fileids = array_intersect(array_map('intval', $fileids), $allowed);

        $fs = get_file_storage();
        $files = [];
        foreach ($fileids as $fileid) {
            if ($file = $fs->get_file_by_id($fileid)) {
                $files[] = $file;
            }
        }
        return $files;
    }
}
