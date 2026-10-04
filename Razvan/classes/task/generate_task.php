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
 * Adhoc task that sends a generation request to the AI service.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz\task;

use local_aiquiz\client;
use local_aiquiz\manager;

defined('MOODLE_INTERNAL') || die();

/**
 * Builds a job's request body and POSTs it to the generation service.
 *
 * Runs in cron so the transfer never blocks the teacher's page submit.
 */
class generate_task extends \core\task\adhoc_task {

    /**
     * A human-readable name shown on the task admin screens.
     *
     * @return string
     */
    public function get_name() {
        return get_string('taskgenerate', 'local_aiquiz');
    }

    /**
     * Send the request for the job carried in the custom data.
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        $jobid = (int) ($data->jobid ?? 0);

        $job = $DB->get_record('local_aiquiz_jobs', ['id' => $jobid]);
        if (!$job) {
            mtrace("local_aiquiz: job {$jobid} no longer exists, skipping.");
            return;
        }
        // A job leaves 'pending' only via the service callback or a previous
        // failure, so anything else here means the work is already done.
        if ($job->status !== manager::STATUS_PENDING) {
            mtrace("local_aiquiz: job {$jobid} is '{$job->status}', not pending, skipping.");
            return;
        }

        // Only the send is guarded: once the service has accepted the request a
        // callback is coming, so a later failure here must not mark the job
        // failed and make that callback a no-op.
        try {
            $remotejobid = client::send(manager::build_request($job));
        } catch (\Throwable $e) {
            // Record the failure as a terminal state rather than rethrowing:
            // re-queueing would keep the dashboard stuck on 'Generating' and,
            // once cron gives up, leave the job pending forever.
            $job->status = manager::STATUS_FAILED;
            $job->errormessage = $e->getMessage();
            $job->timemodified = time();
            $DB->update_record('local_aiquiz_jobs', $job);
            mtrace("local_aiquiz: job {$jobid} failed: " . $e->getMessage());
            return;
        }

        // Record the service's own id, so a row here can be matched against
        // their logs. Just this column, not the whole row: the callback can
        // land while we are still here, and rewriting $job would push the
        // status we read earlier back over the one the callback just set.
        if ($remotejobid !== '') {
            $DB->update_record('local_aiquiz_jobs', (object) [
                'id' => $job->id,
                'remotejobid' => $remotejobid,
                'timemodified' => time(),
            ]);
        }

        mtrace("local_aiquiz: sent generation request for job {$jobid} "
            . "({$job->requestid}), service job id: " . ($remotejobid ?: 'not returned') . ".");
    }
}
