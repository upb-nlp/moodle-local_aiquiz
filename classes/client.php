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
 * HTTP client for the generation service.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Research Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

/**
 * Sends generation requests to the configured AI service.
 *
 * Only the request description travels here; the file bytes are pulled by the
 * service from the tokenpluginfile.php URLs the request carries.
 */
class client {
    /** @var string Path appended to the base URL to create a generation job. */
    const JOBS_PATH = '/question-bank/generation-jobs';

    /** @var int Seconds to wait for the TCP connection. */
    const CONNECT_TIMEOUT = 10;

    /** @var int Seconds to wait for the whole request. The body is tiny,
     * so this never has to cover a file transfer. */
    const REQUEST_TIMEOUT = 30;

    /**
     * Create a generation job on the service.
     *
     * The service answers 202 immediately and does the work asynchronously,
     * then POSTs the outcome to the callback URL carried in the request.
     *
     * @param array $request The payload from manager::build_request().
     * @return string The service's own job id, or '' if it did not return one.
     *                Stored only so a job can be traced in the service's logs.
     * @throws \moodle_exception When the service is unconfigured, unreachable
     *                           or answers with a non 2xx status.
     */
    public static function send(array $request): string {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $config = get_config('local_aiquiz');
        $base = rtrim($config->apiendpoint ?? '', '/');
        if ($base === '') {
            throw new \moodle_exception('errornoendpoint', 'local_aiquiz');
        }

        // The ignoresecurity option lets us reach localhost / private IPs during
        // development; in production the admin leaves allowlocalapi off and
        // Moodle's SSRF blocklist stays in force.
        $curl = new \curl(['ignoresecurity' => !empty($config->allowlocalapi)]);
        $curl->setHeader('Content-Type: application/json');
        $curl->setHeader('Accept: application/json');
        if (!empty($config->authtoken)) {
            $curl->setHeader('Authorization: Bearer ' . $config->authtoken);
        }

        $body = json_encode($request, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $curl->post($base . self::JOBS_PATH, $body, [
            'CURLOPT_CONNECTTIMEOUT' => self::CONNECT_TIMEOUT,
            'CURLOPT_TIMEOUT' => self::REQUEST_TIMEOUT,
        ]);

        // Transport-level failure: DNS, connection refused, timeout, blocked
        // host. $response is false and errno is set.
        if ($curl->get_errno()) {
            throw new \moodle_exception('errorsendfailed', 'local_aiquiz', '', $curl->error);
        }

        $code = (int) ($curl->get_info()['http_code'] ?? 0);
        if ($code < 200 || $code >= 300) {
            // Surface the body: the service validates with Pydantic and a 422
            // names every field it rejected, which is the whole diagnosis.
            $detail = (object) ['code' => $code, 'body' => shorten_text((string) $response, 500)];
            throw new \moodle_exception('errorservicestatus', 'local_aiquiz', '', $detail);
        }

        // A missing job id is not fatal: the work is already accepted and the
        // callback carries our own request_id, which is what completes the job.
        $decoded = json_decode((string) $response, true);
        return is_array($decoded) ? (string) ($decoded['job_id'] ?? '') : '';
    }
}
