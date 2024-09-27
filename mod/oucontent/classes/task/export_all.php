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

namespace mod_oucontent\task;

use core_availability\info_module;
use core_availability\info_section;
use core_availability\tree_node;

/**
 * Scheduled task that exports all XML into per course zip files.
 *
 * @package mod_oucontent
 * @copyright 2024 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_all extends \core\task\scheduled_task {
    /** @var string Component used in file storage. */
    const FILE_COMPONENT = 'mod_oucontent';

    /** @var string File area used in file storage. */
    const FILE_FILEAREA = 'exportall';

    /** @var int Links can be downloaded for up to 4 hours */
    const LINK_EXPIRY = 4 * HOURSECS;

    #[\Override]
    public function get_name() {
        return get_string('exportallheading', 'mod_oucontent');
    }

    #[\Override]
    public function execute() {
        $config = get_config('oucontent');

        // Get task start time.
        $clock = \core\di::get(\core\clock::class);
        $start = $clock->time();

        $courselist = self::list_courses_for_update_and_delete_old_files();
        if ($courselist) {
            $tempfolder = get_request_storage_directory();
        }
        while ($courselist && ($clock->time() - $start < $config->exportalltimelimit)) {
            $courseid = array_shift($courselist);

            try {
                self::process_course($courseid, $tempfolder, true);
            } catch (\Throwable $t) {
                mtrace("Error processing {$courseid}: " . $t->getMessage());
            }
        }
        mtrace(count($courselist) . ' course(s) still due for processing');
    }

    /**
     * Creates the archive file for a single specified course.
     *
     * @param int $courseid Course id
     * @param string $tempfolder Root directory for temporary storage
     * @param bool $output True to show output text using mtrace
     */
    public static function process_course(int $courseid, string $tempfolder, $output = false) : void {
        global $DB;

        $config = get_config('oucontent');

        $fs = get_file_storage();

        $details = self::get_course_details($courseid);

        if ($output) {
            mtrace($details->shortname . ': starting processing (' .
                count($details->documents) . ' documents');
        }

        // Create a folder for zipping (we have to do this on disk if we want file dates to work).
        $folder = $tempfolder . '/' . $courseid;
        mkdir($folder);

        // Save all the document XML files.
        $archivefiles = [];
        foreach ($details->documents as $document) {
            $threedigitsequence = sprintf('%03d', $document->sequence);
            if ($output) {
                mtrace('  ' . $threedigitsequence . ' ' . $document->name);
            }

            // Skip documents that already encountered an error.
            if ($document->error) {
                continue;
            }

            // Make up a suitable file name, short enough that it should expand OK in any operating
            // system (allowing some room for contingency).
            $filename = $threedigitsequence . '.' .
                self::get_filename_safe($document->sectionname) . '.' .
                self::get_filename_safe($document->name);
            $filename = substr($filename, 0, 200) . '.xml';

            if ($document->restricted) {
                $filename = 'restricted/' . $filename;
                check_dir_exists($folder . '/restricted');
            }

            $document->filename = $filename;

            $filepath = $folder . '/' . $filename;

            try {
                $xml = self::get_xml($document);
            } catch (\Throwable $t) {
                $document->error = "Error getting XML: " . $t->getMessage();
                continue;
            }
            if (!file_put_contents($filepath, $xml)) {
                $document->error = "Unable to save XML to {$filepath}";
                continue;
            }
            $archivefiles[$filename] = $filepath;
            if (!touch($filepath, $document->publishedat)) {
                $document->error = 'Unable to update modified time';
            }

            // Sleep for the export delay (in milliseconds).
            usleep($config->exportalldelay * 1000);
        }

        // Save the metadata XML file.
        if ($output) {
            mtrace('  Writing metadata...');
        }
        $metadata = self::get_metadata($details);
        $filepath = $folder . '/metadata.xml';
        if (!file_put_contents($filepath, $metadata)) {
            throw new \coding_exception('Unable to write metadata.xml');
        }
        $archivefiles['metadata.xml'] = $filepath;

        // Zip it.
        if ($output) {
            mtrace('  Creating zip...');
        }
        $zippath = $folder . '/archive.zip';
        (new \zip_packer())->archive_to_pathname($archivefiles, $zippath);

        // Store in filesystem.
        if ($output) {
            mtrace('  Storing...');
        }
        $transaction = $DB->start_delegated_transaction();
        $existing = self::get_file($courseid);
        if ($existing) {
            $existing->delete();
        }
        $filerecord = self::get_file_record($courseid);
        $filerecord->timemodified = $details->maxpublished;
        $filerecord->timecreated = $details->maxpublished;
        $fs->create_file_from_pathname($filerecord, $zippath);
        $transaction->allow_commit();

        remove_dir($folder);
        if ($output) {
            mtrace('  Done');
        }
    }

    /**
     * Gets a file record for the export for a particular course id.
     *
     * @param int $courseid Course id
     * @return \stdClass Various fields for Moodle filesystem
     */
    public static function get_file_record(int $courseid): \stdClass {
        $context = \context_system::instance();
        return (object)[
            'contextid' => $context->id,
            'component' => self::FILE_COMPONENT,
            'filearea' => self::FILE_FILEAREA,
            'itemid' => 0,
            'filepath' => '/',
            'filename' => $courseid . '.zip',
        ];
    }

    /**
     * Gets the stored file for a specific course id.
     *
     * @param int $courseid Course id
     * @return \stored_file|null Stored file or null if it doesn't exist
     */
    public static function get_file(int $courseid): ?\stored_file {
        $fs = get_file_storage();
        $filerecord = self::get_file_record($courseid);
        $existing = $fs->get_file(
            $filerecord->contextid,
            $filerecord->component,
            $filerecord->filearea,
            $filerecord->itemid,
            $filerecord->filepath,
            $filerecord->filename,
        );
        if ($existing) {
            return $existing;
        } else {
            return null;
        }
    }

    /**
     * Gets metadata for a course.
     *
     * @param \stdClass $details Course details as returned by {@see self::get_course_details()}
     * @return string Metadata XML as a string
     */
    public static function get_metadata($details): string {
        global $CFG;

        $metadata = '<?xml version="1.0"?>';
        $metadata .= "\n" . \html_writer::start_tag('course', [
            'id' => $details->courseid,
            'shortname' => $details->shortname,
            'fullname' => $details->fullname,
            'visible' => $details->visible ? 'true' : 'false',
            'href' => $CFG->wwwroot . '/course/view.php?id=' . $details->courseid,
            'lastpublished' => date('c', $details->maxpublished),
        ]);

        foreach ($details->documents as $document) {
            $attributes = [
                'sequence' => $document->sequence,
                'name' => $document->name,
                'href' => $CFG->wwwroot . '/mod/oucontent/view.php?id=' . $document->cmid,
                'cmid' => $document->cmid,
                'oucontentid' => $document->oucontentid,
                'restricted' => $document->restricted ? 'true' : 'false',
            ];
            if ($document->error) {
                $attributes['error'] = $document->error;
            }
            if ($document->sectionid) {
                $attributes['sectionid'] = $document->sectionid;
                $attributes['sectionnumber'] = $document->sectionnumber;
                $attributes['sectionname'] = $document->sectionname;
            }
            if ($document->filename) {
                $attributes['filename'] = $document->filename;
            }
            if ($document->publishedat) {
                $attributes['published'] = date('c', $document->publishedat);
            }
            $metadata .= "\n" . \html_writer::empty_tag('document', $attributes);
        }

        $metadata .= "\n" . \html_writer::end_tag('course');
        return $metadata;
    }

    /**
     * Converts text into a filename safe for use on disk.
     *
     * @param string $filename Desired filename
     * @return string Filename with all strange characters replaced by underlines
     */
    public static function get_filename_safe(string $filename) : string {
        return preg_replace('~[^A-Za-z0-9_\-]~u', '_', $filename);
    }

    /**
     * Gets a list of courses that need updating in oldest-first order.
     *
     * Also deletes any archive files that don't correspond to a current course.
     *
     * @return int[] Course ids
     */
    public static function list_courses_for_update_and_delete_old_files(): array {
        global $DB;

        // Get list of courses with at least one structured content document, with max date.
        $courselist = $DB->get_records_sql("
            SELECT course, max(coalesce(publishedat, convertedat)) AS publishedat
              FROM {oucontent}
             WHERE course != 0
          GROUP BY course
          ORDER BY 2");

        // Get list of existing zip files with corresponding published date.
        $files = self::get_available_files();
        $filelist = [];
        foreach ($files as $courseid => $file) {
            // If the course no longer exists, delete it.
            if (!array_key_exists($courseid, $courselist)) {
                $file->delete();
            } else {
                $filelist[(int)$courseid] = $file->get_timemodified();
            }
        }

        // List all courses where there is no file or the file is older.
        $result = [];
        foreach ($courselist as $courseid => $course) {
            if (!array_key_exists($courseid, $filelist) ||
                $course->publishedat > $filelist[$courseid]) {
                $result[] = $courseid;
            }
        }

        return $result;
    }

    /**
     * Gets a list of all available export files.
     *
     * @return \stored_file[] Array of files, keys are course ids
     */
    public static function get_available_files(): array {
        $fs = get_file_storage();
        $systemcontext = \context_system::instance();
        $files = $fs->get_area_files(
            contextid: $systemcontext->id,
            component: self::FILE_COMPONENT,
            filearea: self::FILE_FILEAREA,
            includedirs: false,
        );
        $result = [];
        foreach ($files as $file) {
            if (preg_match('~^([0-9]+).zip$~', $file->get_filename(), $matches)) {
                $result[$matches[1]] = $file;
            }
        }
        return $result;
    }

    /**
     * Gathers all the details required to process a course.
     *
     * @param int $courseid Course id
     * @return \stdClass Object with various data
     */
    public static function get_course_details(int $courseid): \stdClass {
        global $DB;

        $result = new \stdClass();
        $result->courseid = $courseid;

        $modinfo = get_fast_modinfo($courseid);
        $course = $modinfo->get_course();
        $format = course_get_format($course);
        $result->shortname = $course->shortname;
        $result->fullname = $course->fullname;
        $result->visible = (bool)$course->visible;
        $result->maxpublished = 0;

        // Load published date for all documents on course.
        $published = $DB->get_records_sql_menu("
            SELECT id, COALESCE(publishedat, convertedat) AS publishedat
              FROM {oucontent}
             WHERE course = ?", [$courseid]);

        // Loop through all documents (these should be returned in section order).
        $result->documents = [];
        $sequence = 1;
        foreach ($modinfo->get_instances_of('oucontent') as $cm) {
            // The 1-based sequence will be used in filenames to identify the document.
            $document = (object)['sequence' => $sequence++];
            $result->documents[$document->sequence] = $document;

            $document->cmid = $cm->id;
            $document->oucontentid = $cm->instance;
            $document->name = $cm->name;

            // Set default values for all the fields that might be missed if there is an error.
            $document->error = '';
            $document->sectionid = 0;
            $document->sectionnumber = 0;
            $document->sectionname = '';
            $document->publishedat = 0;
            $document->restricted = false;

            // This field isn't set in this function but is sometimes set later.
            $document->filename = '';

            if (!array_key_exists($cm->instance, $published)) {
                $document->error = 'Cannot find published time';
                continue;
            }
            $document->publishedat = (int)$published[$cm->instance];
            $result->maxpublished = max($result->maxpublished, $document->publishedat);

            try {
                $section = $modinfo->get_section_info_by_id($cm->sectionid, MUST_EXIST);
            } catch (\Throwable $t) {
                $document->error = "Error getting section {$cm->sectionid}: " . $t->getMessage();
                continue;
            }
            $document->sectionid = (int)$section->id;
            $document->sectionnumber = (int)$section->sectionnum;
            $document->sectionname = $format->get_section_name($section);

            try {
                $document->restricted = self::is_restricted($cm);
            } catch (\Throwable $t) {
                $document->error = 'Error getting restriction data: ' . $t->getMessage();
                continue;
            }
        }

        return $result;
    }

    /**
     * Checks if a particular course module has any restrictions that might mean it should not be
     * shown to students.
     *
     * @param \cm_info $cm
     * @return bool True if restricted
     */
    public static function is_restricted(\cm_info $cm): bool {
        if (!$cm->visible) {
            // Hidden from students.
            return true;
        }
        $info = new info_module($cm);
        if (!$info->is_available_for_all()) {
            return true;
        }
        $sectioninfo = new info_section($cm->get_section_info());
        if ($sectioninfo->is_available_for_all()) {
            return false;
        } else {
            // Special case for section restrictions used on subpages.
            $tree = $sectioninfo->get_availability_tree();
            $allchildren = $tree->get_all_children(tree_node::class);
            if (count($allchildren) === 1 &&
                    $allchildren[0] instanceof \availability_otheractivity\condition) {
                return self::is_restricted($cm->get_modinfo()->get_cm($allchildren[0]->save()->cm));
            }
            return true;
        }
    }

    /**
     * Gets the XML to return for a single structured content document.
     *
     * @param \stdClass $document Document object from {@see self::get_course_details()}
     * @return string XML content as string
     */
    public static function get_xml(\stdClass $document): string {
        global $CFG;
        require_once($CFG->dirroot . '/mod/oucontent/oucontent.php');

        $oucontent = oucontent_get_record($document->oucontentid);
        return oucontent_generate_scxml($oucontent);
    }

    /**
     * Gets a download token for the current user for the given course id.
     *
     * @param int $courseid Course id
     * @return string Download token
     */
    public static function get_download_token(int $courseid): string {
        global $USER;

        $time = \core\di::get(\core\clock::class)->time();
        return self::calculate_download_token($courseid, $USER->id, $time);
    }

    /**
     * Parses the basic data out of a download token.
     *
     * @param string $token Token
     * @return \stdClass Object containing basic data
     * @throws \moodle_exception If not valid
     */
    public static function parse_download_token(string $token): \stdClass {
        if (!preg_match('~^([0-9]+)_([0-9]+)_([0-9]+)_[a-f0-9]+$~', $token, $matches)) {
            throw new \moodle_exception(
                'exportalltoken_invalid',
                'oucontent',
                debuginfo: 'wrongformat',
            );
        }
        return (object)['courseid' => $matches[1], 'userid' => $matches[2], 'time' => $matches[3]];
    }

    /**
     * Verifies that the download token is correct. If it is, returns the file.
     *
     * @param string $token Token
     * @return \stored_file File to download
     * @throws \moodle_exception If token is not valid
     */
    public static function verify_download_token(string $token): \stored_file {
        global $CFG;

        // Parse token.
        $tokendata = self::parse_download_token($token);

        // Check expiry.
        $age = \core\di::get(\core\clock::class)->time() - $tokendata->time;
        if ($age < 0 || $age > self::LINK_EXPIRY) {
            throw new \moodle_exception(
                'exportalltoken_invalid',
                'oucontent',
                debuginfo: 'expired',
            );
        }

        // Check user is an admin still.
        if (!str_contains(',' . $CFG->siteadmins . ',', ',' . $tokendata->userid . ',')) {
            throw new \moodle_exception(
                'exportalltoken_invalid',
                'oucontent',
                debuginfo: 'notadmin',
            );
        }

        // Check the token including signature is actually valid.
        if ($token !== self::calculate_download_token(
            $tokendata->courseid,
            $tokendata->userid,
            $tokendata->time,
        )) {
            throw new \moodle_exception(
                'exportalltoken_invalid',
                'oucontent',
                debuginfo: 'invalidtoken',
            );
        }

        // Find the actual file.
        $file = self::get_file($tokendata->courseid);
        if (!$file) {
            throw new \moodle_exception(
                'exportalltoken_invalid',
                'oucontent',
                debuginfo: 'nofile',
            );
        }

        // Nothing was wrong so return file.
        return $file;
    }

    /**
     * Calculates a download token given the basic data.
     *
     * @param int $courseid Course id
     * @param int $userid User id
     * @param int $time Time
     * @return string Signed token
     */
    protected static function calculate_download_token(int $courseid, int $userid, int $time): string {
        $tokendata = $courseid . '_' . $userid . '_' . $time;
        $data = $tokendata . get_config('oucontent', 'exportallsalt');
        return $tokendata . '_' . hash('sha256', $data);
    }
}
