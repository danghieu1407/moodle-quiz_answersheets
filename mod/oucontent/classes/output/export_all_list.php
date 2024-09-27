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

namespace mod_oucontent\output;

use mod_oucontent\task\export_all;

/**
 * List of available exported content from courses.
 *
 * @package mod_oucontent
 * @copyright 2024 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_all_list implements \renderable, \core\output\named_templatable {
    /** @var int Max course mappings */
    const MAX_COURSES = 100000;

    /**
     * Constructor.
     *
     * @param \stored_file[] $list List of files, indexed by course id
     */
    public function __construct(protected array $list) {
    }

    #[\Override]
    public function get_template_name(\renderer_base $renderer): string {
        return 'mod_oucontent/export_all_list';
    }

    #[\Override]
    public function export_for_template(\renderer_base $output) {
        global $CFG, $DB;

        $data = new \stdClass();
        $data->modules = [];
        $data->subjects = [];
        $data->other = [];

        // Do nothing if no data.
        if ($this->list) {
            [$sql, $params] = $DB->get_in_or_equal(array_keys($this->list));

            // Module and subject information comes from dataload if available.
            $modules = [];
            $subjects = [];
            if ($CFG->hasdataloadtables) {
                // Get module mapping details for all websites.
                $cvp = \local_oudataload\util::table('vl_v_crs_version_pres');
                $modulemappings = $DB->get_recordset_sql("
                    SELECT c.id, cvp.course_code AS coursecode, cvp.pres_code AS prescode
                      FROM $cvp cvp
                      JOIN {course} c ON c.shortname = cvp.vle_course_short_name
                     WHERE c.id $sql", $params, 0, self::MAX_COURSES);
                foreach ($modulemappings as $rec) {
                    if (!array_key_exists($rec->id, $modules)) {
                        $modules[$rec->id] = [];
                    }
                    $modules[$rec->id][] = $rec->coursecode . '-' . $rec->prescode;
                }
                $modulemappings->close();

                // Get subject mapping details.
                $awards = \local_oudataload\util::table('vl_v_academic_awards');
                $awardmappings = $DB->get_recordset_sql("
                    SELECT c.id, awards.academic_award_code AS awardcode
                      FROM $awards awards
                      JOIN {course} c ON c.shortname = awards.vle_award_short_name
                     WHERE c.id $sql", $params, 0, self::MAX_COURSES);
                foreach ($awardmappings as $rec) {
                    if (!array_key_exists($rec->id, $subjects)) {
                        $subjects[$rec->id] = [];
                    }
                    $subjects[$rec->id][] = $rec->awardcode;
                }
                $awardmappings->close();
            }

            // Find recycle bin category, or 0 if it doesn't exist.
            $recyclebinid = (int)$DB->get_field('course_categories', 'id',
                ['idnumber' => \tool_coursedeletion\util::RECYCLE_BIN_ID]);

            // General course information from Moodle tables.
            $coursedetails = $DB->get_records_select('course', "id $sql", $params,
                'id, shortname, visible, category');
            foreach ($coursedetails as $rec) {
                $rec->inrecyclebin = (int)$rec->category === $recyclebinid;
            }

            // File list, sorted by shortname.
            $files = array_filter(
                export_all::get_available_files(),
                fn($id) => array_key_exists($id, $coursedetails),
                ARRAY_FILTER_USE_KEY,
            );

            uksort($files,  fn($a, $b) => strcmp($coursedetails[$a]->shortname,
                $coursedetails[$b]->shortname));

            // Construct file list in categories (modules, subjects, other).
            foreach ($files as $courseid => $file) {
                if (!array_key_exists($courseid, $coursedetails)) {
                    continue;
                }
                if (array_key_exists($courseid, $modules)) {
                    $data->modules[] = self::export_file(
                        $file,
                        $coursedetails[$courseid],
                        module: $modules[$courseid],
                    );
                } else if (array_key_exists($courseid, $subjects)) {
                    $data->subjects[] = self::export_file(
                        $file,
                        $coursedetails[$courseid],
                        subject: $subjects[$courseid],
                    );
                } else {
                    $data->other[] = self::export_file(
                        $file,
                        $coursedetails[$courseid],
                    );
                }
            }
        }

        $data->hasmodules = (bool)count($data->modules);
        $data->hassubjects = (bool)count($data->subjects);
        $data->hasother = (bool)count($data->other);

        $data->hasmodulesorsubjects = $data->hasmodules || $data->hassubjects;

        return $data;
    }

    /**
     * Exports the template data for a single file.
     *
     * @param \stored_file $file File to download
     * @param \stdClass $coursedetails Course details (id, shortname, visible, inrecyblebin)
     * @param array $module Module details if any
     * @param array $subject Subject details if any
     * @return \stdClass Template data
     */
    public static function export_file(
        \stored_file $file,
        \stdClass $coursedetails,
        array $module = [],
        array $subject = [],
    ): \stdClass {
        $data = new \stdClass;
        $data->shortname = $coursedetails->shortname;
        $data->hidden = !$coursedetails->visible;
        $data->inrecyclebin = $coursedetails->inrecyclebin;
        $data->lastpublished = $file->get_timemodified();
        $data->lastpublishedtime = date('c', $data->lastpublished);
        $data->size = display_size($file->get_filesize());
        $data->sizebytes = $file->get_filesize();
        $data->downloadurl = (new \moodle_url(
            '/mod/oucontent/exportall.php',
            ['download' => export_all::get_download_token($coursedetails->id)],
        ))->out(false);

        if ($module) {
            $data->haspresentations = true;
            $data->presentations = [];
            foreach ($module as $modulepres) {
                $data->presentations[] = (object)['modulepres' => $modulepres];
            }
        }
        if ($subject) {
            $data->hasawards = true;
            $data->awards = [];
            foreach ($subject as $awardcode) {
                $data->awards[] = (object)['awardcode' => $awardcode];
            }
        }

        return $data;
    }
}
