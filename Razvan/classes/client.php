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
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_aiquiz;

defined('MOODLE_INTERNAL') || die();

/**
 * Sends generation requests to the configured AI service.
 *
 * Only the manifest travels here; the file bytes are pulled by the service
 * from the tokenpluginfile.php URLs the manifest carries.
 */
class client {

    /** @var string Path appended to the base URL to start a generation. */
    const GENERATE_PATH = '/generate';

    /** @var int Seconds to wait for the TCP connection. */
    const CONNECT_TIMEOUT = 10;

    /** @var int Seconds to wait for the whole request. The manifest is tiny,
     * so this never has to cover a file transfer. */
    const REQUEST_TIMEOUT = 30;

    /**
     * POST a manifest to the generation service.
     *
     * @param array $manifest The payload from manager::build_manifest().
     * @throws \moodle_exception When the service is unconfigured, unreachable
     *                           or answers with a non 2xx status.
     */
    public static function send(array $manifest): void {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $config = get_config('local_aiquiz');
        $base = rtrim($config->apiendpoint ?? '', '/');
        if ($base === '') {
            throw new \moodle_exception('errornoendpoint', 'local_aiquiz');
        }

        // ignoresecurity lets us reach localhost / private IPs during
        // development; in production the admin leaves allowlocalapi off and
        // Moodle's SSRF blocklist stays in force.
        $curl = new \curl(['ignoresecurity' => !empty($config->allowlocalapi)]);
        $curl->setHeader('Content-Type: application/json');
        $curl->setHeader('Accept: application/json');
        if (!empty($config->authtoken)) {
            $curl->setHeader('Authorization: Bearer ' . $config->authtoken);
        }

        $body = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $response = $curl->post($base . self::GENERATE_PATH, $body, [
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
            // Surface a short slice of the body to help diagnose the failure.
            $detail = (object) ['code' => $code, 'body' => shorten_text((string) $response, 200)];
            throw new \moodle_exception('errorservicestatus', 'local_aiquiz', '', $detail);
        }
    }
}
