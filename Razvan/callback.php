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
 * Machine endpoint: no Moodle session, authenticated by the shared token.
 * Expects a JSON body: {requestid, status: done|failed, error?, questions?}.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);

require_once(__DIR__ . '/../../config.php');

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

// Authenticate the service with the shared token. An unset token means the
// site is not configured to receive callbacks, so reject everything.
$expected = trim((string) get_config('local_aiquiz', 'authtoken'));
$authheader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
$provided = (stripos($authheader, 'Bearer ') === 0) ? trim(substr($authheader, 7)) : '';
if ($expected === '' || !hash_equals($expected, $provided)) {
    local_aiquiz_respond(401, ['error' => 'unauthorized']);
}

$payload = json_decode(file_get_contents('php://input'), true);
if (!is_array($payload) || empty($payload['requestid'])) {
    local_aiquiz_respond(400, ['error' => 'invalid payload']);
}

$job = $DB->get_record('local_aiquiz_jobs', ['requestid' => $payload['requestid']]);
if (!$job) {
    local_aiquiz_respond(404, ['error' => 'unknown requestid']);
}

// The unique requestid index makes a job completable once. A repeated
// callback (service retry) is acknowledged without changing anything.
if ($job->status !== manager::STATUS_PENDING) {
    local_aiquiz_respond(200, ['ok' => true, 'note' => 'already processed']);
}

$status = $payload['status'] ?? '';
if ($status === 'failed') {
    $job->status = manager::STATUS_FAILED;
    $job->errormessage = (string) ($payload['error'] ?? 'The generation service reported an error.');
} else if ($status === 'done') {
    // TODO: import $payload['questions'] into the quiz $job->quizid. Until the
    // importer exists the job is marked done but the quiz stays an empty draft
    // the teacher can fill in by hand.
    $job->status = manager::STATUS_DONE;
    $job->errormessage = null;
} else {
    local_aiquiz_respond(400, ['error' => 'status must be done or failed']);
}

$job->timemodified = time();
$DB->update_record('local_aiquiz_jobs', $job);

local_aiquiz_respond(200, ['ok' => true]);
