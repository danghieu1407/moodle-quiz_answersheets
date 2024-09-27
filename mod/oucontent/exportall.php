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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * View and download script for the all-SC export files.
 *
 * @package mod_oucontent
 * @copyright 2024 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_oucontent;

use mod_oucontent\event\exportall_downloaded;
use mod_oucontent\output\export_all_list;
use mod_oucontent\task\export_all;

require(__DIR__ . '/../../config.php');

$download = optional_param('download', '', PARAM_ALPHANUMEXT);
if ($download) {
    $file = export_all::verify_download_token($download);

    // Record download in log as it might be security-sensitive.
    $tokendata = export_all::parse_download_token($download);
    exportall_downloaded::create([
        'objectid' => $tokendata->courseid,
        'context' => \context_course::instance($tokendata->courseid),
        'userid' => $tokendata->userid,
    ])->trigger();

    // Send file with course shortname instead of numeric ID.
    $shortname = $DB->get_field(
        'course',
        'shortname',
        ['id' => (int)preg_replace('~\..*$~', '', $file->get_filename())],
        MUST_EXIST,
    );
    send_file(
        path: $file,
        filename: export_all::get_filename_safe($shortname) . '.zip',
        lifetime: 0,
        forcedownload: true,
        options: ['cacheability' => 'private'],
    );
}

require_once($CFG->libdir . '/adminlib.php');
admin_externalpage_setup('mod_oucontent_exportall');

// User must be logged in as admin not just site:config which is checked above.
require_admin();
if (!is_siteadmin()) {
    throw new \moodle_exception('cannotuseadmin');
}

echo $OUTPUT->header();

$list = new export_all_list(export_all::get_available_files());
echo $OUTPUT->render($list);

echo $OUTPUT->footer();
