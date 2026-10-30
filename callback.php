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
 * Callback the generation service posts to when a job finishes.
 *
 * Machine endpoint: no Moodle session. Expects the same envelope the service
 * returns from GET /question-bank/generation-jobs/<job_id>:
 *
 *     {job_id, request_id, status, error, result: {questions, ...}}
 *
 * The flatter body the HRIA import API was built for, {requestid, questions},
 * is accepted as well. On completion the questions are imported into the
 * job's quiz (see \local_aiquiz\importer), which stays hidden until the
 * teacher reviews and publishes it.
 *
 * Authenticated by the per-job key in the URL (see manager::callback_url).
 * A Bearer token equal to the plugin's configured token is also accepted, in
 * case the service echoes it back.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

use local_aiquiz\importer;
use local_aiquiz\manager;

header('Content-Type: application/json');

/**
 * Send a JSON response with an HTTP status and stop.
 *
 * @param int $code
 * @param array $body
 */
function local_aiquiz_respond(int $code, array $body): void {
    http_response_code($code);
    echo json_encode($body);
    die();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    local_aiquiz_respond(405, ['error' => 'method not allowed']);
}

$payload = json_decode(file_get_contents('php://input'), true);

// The service names it request_id; the dev mock and older builds send
// requestid. Accept both so a local mock run stays useful.
$requestid = '';
if (is_array($payload)) {
    $requestid = (string) ($payload['request_id'] ?? $payload['requestid'] ?? '');
}
if ($requestid === '') {
    local_aiquiz_respond(400, ['error' => 'invalid payload']);
}

// Authenticate. The per-job key in the URL is the primary proof, because the
// configured token authenticates us to the service, not the service to us.
// A matching Bearer token is accepted as well, should the service echo it.
$key = optional_param('key', '', PARAM_ALPHANUM);
$authorised = $key !== '' && hash_equals(manager::callback_key($requestid), $key);

if (!$authorised) {
    $expected = trim((string) get_config('local_aiquiz', 'authtoken'));
    $authheader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    $provided = (stripos($authheader, 'Bearer ') === 0) ? trim(substr($authheader, 7)) : '';
    $authorised = $expected !== '' && $provided !== '' && hash_equals($expected, $provided);
}

if (!$authorised) {
    local_aiquiz_respond(401, ['error' => 'unauthorized']);
}

$job = $DB->get_record('local_aiquiz_jobs', ['requestid' => $requestid]);
if (!$job) {
    local_aiquiz_respond(404, ['error' => 'unknown request_id']);
}

// The unique requestid index makes a job completable once. A repeated
// callback (service retry) is acknowledged without changing anything.
if ($job->status !== manager::STATUS_PENDING) {
    local_aiquiz_respond(200, ['ok' => true, 'note' => 'already processed']);
}

// The service's own vocabulary is 'queued', 'processing' and 'completed'.
// Treat anything it might call success or failure as such, and acknowledge
// progress updates without touching the job. The HRIA shape has no status at
// all: a question list on its own means the job completed.
$status = strtolower((string) ($payload['status'] ?? ''));
$questions = importer::extract_questions($payload);
if ($status === '' && $questions !== null) {
    $status = 'completed';
}

if (in_array($status, ['queued', 'processing', 'running', 'pending'], true)) {
    local_aiquiz_respond(200, ['ok' => true, 'note' => 'progress noted']);
}

$failed = in_array($status, ['failed', 'error', 'cancelled', 'canceled'], true);
if (!$failed && !in_array($status, ['completed', 'done', 'succeeded', 'success'], true)) {
    local_aiquiz_respond(400, ['error' => 'unrecognised status: ' . $status]);
}

// One delivery per job at a time. The status check above only sees a job
// that is finished, not one being imported, so a retry landing mid-import
// would otherwise fill the quiz a second time.
$lock = \core\lock\lock_config::get_lock_factory('local_aiquiz_callback')->get_lock('job' . $job->id, 30);
if (!$lock) {
    local_aiquiz_respond(409, ['error' => 'another delivery for this job is still being processed']);
}

try {
    // Read again under the lock: the delivery that held it may have finished the job.
    $job = $DB->get_record('local_aiquiz_jobs', ['id' => $job->id], '*', MUST_EXIST);

    if ($job->status !== manager::STATUS_PENDING) {
        $code = 200;
        $response = ['ok' => true, 'note' => 'already processed'];
    } else {
        if ($failed) {
            $job->status = manager::STATUS_FAILED;
            $job->errormessage = (string) ($payload['error']
                ?? 'The generation service reported an error.');
            $code = 200;
            $response = ['ok' => true];
        } else {
            try {
                // Import as the teacher who asked for the quiz, so the questions
                // are theirs in the question bank. Core's importer renders its
                // error notices, which needs a page context.
                \core\session\manager::set_user(\core_user::get_user($job->userid) ?: get_admin());
                $PAGE->set_context(context_system::instance());

                $result = importer::import($job, $questions ?? []);
                $job->status = manager::STATUS_DONE;
                $job->errormessage = null;
                $code = 200;
                $response = ['ok' => true] + $result;
            } catch (\Throwable $e) {
                // Finished either way: a retry would deliver the same questions,
                // so the teacher is told rather than the job left generating.
                $job->status = manager::STATUS_FAILED;
                $job->errormessage = $e->getMessage();
                $code = 422;
                $response = ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        $job->timemodified = time();
        $DB->update_record('local_aiquiz_jobs', $job);
    }
} finally {
    $lock->release();
}

local_aiquiz_respond($code, $response);
