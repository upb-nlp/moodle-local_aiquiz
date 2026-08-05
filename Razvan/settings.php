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
 * Site wide settings for the AI quiz generator plugin.
 *
 * @package    local_aiquiz
 * @copyright  2026 NLP Reseach Lab
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {

    $settings = new admin_settingpage('local_aiquiz', get_string('pluginname', 'local_aiquiz'));

    $settings->add(new admin_setting_configtext(
        'local_aiquiz/apiendpoint',
        get_string('apiendpoint', 'local_aiquiz'),
        get_string('apiendpoint_desc', 'local_aiquiz'),
        '',
        PARAM_URL
    ));

    $settings->add(new admin_setting_configpasswordunmask(
        'local_aiquiz/authtoken',
        get_string('authtoken', 'local_aiquiz'),
        get_string('authtoken_desc', 'local_aiquiz'),
        ''
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_aiquiz/allowlocalapi',
        get_string('allowlocalapi', 'local_aiquiz'),
        get_string('allowlocalapi_desc', 'local_aiquiz'),
        0
    ));

    $ADMIN->add('localplugins', $settings);
}
