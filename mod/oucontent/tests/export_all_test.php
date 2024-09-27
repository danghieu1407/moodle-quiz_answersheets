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

namespace mod_oucontent;

use mod_oucontent\task\export_all;

/**
 * Tests exporting all structure content documents for a course into a zip file.
 *
 * @covers \mod_oucontent\task\export_all
 * @package mod_oucontent
 * @copyright 2024 The Open University
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class export_all_test extends \advanced_testcase {

    /**
     * Tests {@see export_all::get_filename_safe()}.
     */
    public function test_get_filename_safe(): void {
        $this->assertEquals('some_invalid__', export_all::get_filename_safe('some invalid!/'));
        $this->assertEquals('unicode_character', export_all::get_filename_safe("unicode\u{697D}character"));
    }

    /**
     * Tests {@see export_all::get_xml()}.
     */
    public function test_get_xml(): void {
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $oucontent = $generator->get_plugin_generator('mod_oucontent')
            ->create_instance(['course' => $course->id]);

        $document = (object)['oucontentid' => $oucontent->id];
        $xml = export_all::get_xml($document);
        $this->assertStringContainsString('<Title>Minimal sample document</Title>', $xml);
    }

    /**
     * Tests {@see export_all::is_restricted()}.
     */
    public function test_is_restricted():  void {
        global $DB;
        $this->resetAfterTest();

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['format' => 'oustudyplan']);

        // Availability conditions which fail or pass.
        $datefail = json_encode(\core_availability\tree::get_root_json([
            \availability_date\condition::get_json(
                \availability_date\condition::DIRECTION_UNTIL,
                strtotime('2020-01-01'),
            ),
        ]));
        $datepass = json_encode(\core_availability\tree::get_root_json([
            \availability_date\condition::get_json(
                \availability_date\condition::DIRECTION_FROM,
                strtotime('2020-01-01'),
            ),
        ]));

        // Documents: unrestricted, not visible, restricted but pass for all, restricuted.
        $oucontentgenerator = $generator->get_plugin_generator('mod_oucontent');
        $doc1 = $oucontentgenerator->create_instance(['course' => $course->id, 'section' => 0]);
        $doc2 = $oucontentgenerator->create_instance(['course' => $course->id, 'section' => 0, 'visible' => 0]);
        $doc3 = $oucontentgenerator->create_instance(['course' => $course->id, 'section' => 0], ['availability' => $datepass]);
        $doc4 = $oucontentgenerator->create_instance(['course' => $course->id, 'section' => 0], ['availability' => $datefail]);

        // Make two subpages, one of which is restricted.
        $subpage1 = $generator->create_module('oustudyplansubpage',
            ['course' => $course->id, 'section' => 0]);
        \format_oustudyplan\sections::create_owned($course, $subpage1->cmid);
        $subpage2 = $generator->create_module('oustudyplansubpage',
            ['course' => $course->id, 'section' => 0, 'visible' => 0]);

        // Ensure the sections are all in correct order (0, subpage1 1 and 2, subpage2 3).
        \format_oustudyplan\sections::rearrange_sections(course_get_format($course));

        // Second section in subpage1 is restricted.
        $DB->set_field('course_sections', 'availability', $datefail, ['course' => $course->id, 'section' => 2]);

        // Document in each subpage section.
        $doc5 = $oucontentgenerator->create_instance(['course' => $course->id, 'section' => 1]);
        $doc6 = $oucontentgenerator->create_instance(['course' => $course->id, 'section' => 2]);
        $doc7 = $oucontentgenerator->create_instance(['course' => $course->id, 'section' => 3]);

        rebuild_course_cache($course->id);
        $modinfo = get_fast_modinfo($course->id);

        $this->assertFalse(export_all::is_restricted($modinfo->get_cm($doc1->cmid)));
        $this->assertTrue(export_all::is_restricted($modinfo->get_cm($doc2->cmid)));
        $this->assertFalse(export_all::is_restricted($modinfo->get_cm($doc3->cmid)));
        $this->assertTrue(export_all::is_restricted($modinfo->get_cm($doc4->cmid)));
        $this->assertFalse(export_all::is_restricted($modinfo->get_cm($doc5->cmid)));
        $this->assertTrue(export_all::is_restricted($modinfo->get_cm($doc6->cmid)));
        $this->assertTrue(export_all::is_restricted($modinfo->get_cm($doc7->cmid)));
    }

    /**
     * Tests {@see export_all::get_course_details()} and {@see export_all::process_course()}. It
     * is easier to test these together because we have to set up the same fake data for both.
     */
    public function test_handle_course(): void {
        global $CFG, $DB;
        $this->resetAfterTest();

        // Set the delay to 0 to make the test marginally faster.
        set_config('exportalldelay', '9', 'oucontent');

        // Create course with 3 documents one of which is restricted.
        $generator = $this->getDataGenerator();
        $oucontentgenerator = $generator->get_plugin_generator('mod_oucontent');
        $course = $generator->create_course([
            'format' => 'oustudyplan',
            'shortname' => 'C2',
            'fullname' => 'Course 2',
        ]);
        \format_oustudyplan\sections::rearrange_sections(course_get_format($course));

        \mod_oucontent\util::fix_time(1000);
        $doc1 = $oucontentgenerator->create_instance([
            'course' => $course->id,
            'section' => 0,
        ], [
            'xmlfile' => 'minimal.xml',
        ]);
        \mod_oucontent\util::fix_time(1200);
        $doc2 = $oucontentgenerator->create_instance([
            'course' => $course->id,
            'section' => 0,
            'visible' => 0,
        ], [
            'xmlfile' => 'numbering.xml',
        ]);
        \mod_oucontent\util::fix_time(1100);
        $doc3 = $oucontentgenerator->create_instance([
            'course' => $course->id,
            'section' => 1,
        ], [
            'xmlfile' => 'search.xml',
        ]);

        $sectionids = $DB->get_records_menu('course_sections', ['course' => $course->id], fields: 'section, id');

        rebuild_course_cache($course->id);
        $modinfo = get_fast_modinfo($course->id);

        // Get the details.
        $details = export_all::get_course_details($course->id);

        $this->assertEquals($course->id, $details->courseid);
        $this->assertEquals('C2', $details->shortname);
        $this->assertEquals('Course 2', $details->fullname);
        $this->assertEquals(true, $details->visible);
        $this->assertEquals(1200, $details->maxpublished);
        $this->assertCount(3, $details->documents);

        // Test all fields for first document.
        $doc = $details->documents[1];
        $this->assertEquals(1, $doc->sequence);
        $this->assertEquals($doc1->cmid, $doc->cmid);
        $this->assertEquals($doc1->id, $doc->oucontentid);
        $this->assertEquals('Minimal sample document', $doc->name);
        $this->assertEquals('', $doc->error);
        $this->assertEquals($sectionids[0], $doc->sectionid);
        $this->assertEquals(0, $doc->sectionnumber);
        $this->assertEquals('Week 1', $doc->sectionname);
        $this->assertEquals(1000, $doc->publishedat);
        $this->assertEquals(false, $doc->restricted);

        // Test the other two documents (just a few fields).
        $doc = $details->documents[2];
        $this->assertEquals('Numbering sample document', $doc->name);
        $this->assertEquals(true, $doc->restricted);

        $doc = $details->documents[3];
        $this->assertEquals('Search sample document', $doc->name);
        $this->assertEquals($sectionids[1], $doc->sectionid);
        $this->assertEquals(1, $doc->sectionnumber);
        $this->assertEquals('Week 2', $doc->sectionname);

        // Do the actual export.
        $tempfolder = make_request_directory();
        export_all::process_course($course->id, $tempfolder);

        // Check the temp files were deleted.
        $gettempfiles = function($folder) {
            return array_values(array_filter(scandir($folder),
                fn ($name) => !in_array($name, ['.', '..'])));
        };
        $this->assertCount(0, $gettempfiles($tempfolder));

        // Check the zip file exists.
        $zipfile = export_all::get_file($course->id);
        $this->assertNotNull($zipfile);

        // Extract the zipfile and check it contains the right files.
        $zipfile->extract_to_pathname(new \zip_packer(), $tempfolder);
        $files = $gettempfiles($tempfolder);
        $this->assertCount(4, $files);
        $this->assertEquals('001.Week_1.Minimal_sample_document.xml', $files[0]);
        $this->assertEquals('003.Week_2.Search_sample_document.xml', $files[1]);
        $this->assertEquals('metadata.xml', $files[2]);
        $this->assertEquals('restricted', $files[3]);
        $restrictedfiles = $gettempfiles($tempfolder . '/restricted');
        $this->assertCount(1, $restrictedfiles);
        $this->assertEquals('002.Week_1.Numbering_sample_document.xml', $restrictedfiles[0]);

        // Note we are not checking the times of the extracted files because these don't get set
        // correctly, unfortunately.

        // Check file contents (XML).
        $this->assertStringContainsString(
            '<ItemTitle>Minimal sample document</ItemTitle>',
            file_get_contents($tempfolder . '/' . $files[0]),
        );
        $this->assertStringContainsString(
            '<ItemTitle>Numbering sample document</ItemTitle>',
            file_get_contents($tempfolder . '/restricted/' . $restrictedfiles[0]),
        );
        $this->assertStringContainsString(
            '<ItemTitle>Search sample document</ItemTitle>',
            file_get_contents($tempfolder . '/' . $files[1]),
        );

        // Load metadata XML.
        $doc = new \DOMDocument();
        $doc->loadXML(file_get_contents($tempfolder . '/' . $files[2]));
        $xpath = new \DOMXPath($doc);

        $xpaths = [
            // Check course tag elements.
            'string(/course/@id)' => $course->id,
            'string(/course/@shortname)' => 'C2',
            'string(/course/@fullname)' => 'Course 2',
            'string(/course/@visible)' => 'true',
            'string(/course/@href)' => $CFG->wwwroot . '/course/view.php?id=' . $course->id,
            'string(/course/@lastpublished)' => '1970-01-01T08:20:00+08:00',

            // Check documents all present.
            'count(/course/document)' => 3,

            // Check first document in detail.
            'string(/course/document[1]/@sequence)' => 1,
            'string(/course/document[1]/@name)' => 'Minimal sample document',
            'string(/course/document[1]/@href)' => $CFG->wwwroot . '/mod/oucontent/view.php?id=' . $doc1->cmid,
            'string(/course/document[1]/@cmid)' => $doc1->cmid,
            'string(/course/document[1]/@oucontentid)' => $doc1->id,
            'string(/course/document[1]/@restricted)' => 'false',
            'string(/course/document[1]/@sectionid)' => $sectionids[0],
            'string(/course/document[1]/@sectionnumber)' => 0,
            'string(/course/document[1]/@sectionname)' => 'Week 1',
            'string(/course/document[1]/@filename)' => $files[0],
            'string(/course/document[1]/@published)' => '1970-01-01T08:16:40+08:00',

            // Check the other two are there.
            'string(/course/document[2]/@name)' => 'Numbering sample document',
            'string(/course/document[3]/@name)' => 'Search sample document',
        ];

        $this->assert_xpaths($xpath, $xpaths);
    }

    /**
     * Tests {@see export_all::get_metadata()}.
     */
    public function test_get_metadata(): void {
        global $CFG;

        // Create fake course details object.
        $details = (object)[
            'courseid' => 123,
            'shortname' => 'FROGS',
            'fullname' => 'Frogs and amphibians',
            'visible' => true,
            'maxpublished' => 1727654400,
            'documents' => [],
        ];

        // Document with the least possible fields complete.
        $details->documents[] = (object)[
            'sequence' => 1,
            'cmid' => 101,
            'oucontentid' => 1,
            'name' => 'D1',
            'error' => 'minimal',
            'sectionid' => 0,
            'sectionnumber' => 0,
            'sectionname' => '',
            'publishedat' => 0,
            'restricted' => false,
            'filename' => '',
        ];

        // Next up: has published date.
        $details->documents[] = (object)[
            'sequence' => 2,
            'cmid' => 102,
            'oucontentid' => 2,
            'name' => 'D2',
            'error' => 'published',
            'sectionid' => 0,
            'sectionnumber' => 0,
            'sectionname' => '',
            'publishedat' => 1727568000,
            'restricted' => false,
            'filename' => '',
        ];

        // Next up: has published date.
        $details->documents[] = (object)[
            'sequence' => 3,
            'cmid' => 103,
            'oucontentid' => 3,
            'name' => 'D3',
            'error' => 'section',
            'sectionid' => 200,
            'sectionnumber' => 17,
            'sectionname' => 'Week 17',
            'publishedat' => 1727568000,
            'restricted' => false,
            'filename' => '',
        ];

        // Next up: full data (also set it restricted).
        $details->documents[] = (object)[
            'sequence' => 4,
            'cmid' => 104,
            'oucontentid' => 4,
            'name' => 'D4',
            'error' => '',
            'sectionid' => 200,
            'sectionnumber' => 17,
            'sectionname' => 'Week 17',
            'publishedat' => 1727654400,
            'restricted' => true,
            'filename' => 'whatever.xml',
        ];

        $doc = new \DOMDocument();
        $doc->loadXML(export_all::get_metadata($details));
        $xpath = new \DOMXPath($doc);

        $xpaths = [
            // Check course tag elements.
            'string(/course/@id)' => 123,
            'string(/course/@shortname)' => 'FROGS',
            'string(/course/@fullname)' => 'Frogs and amphibians',
            'string(/course/@visible)' => 'true',
            'string(/course/@href)' => $CFG->wwwroot . '/course/view.php?id=123',
            'string(/course/@lastpublished)' => '2024-09-30T08:00:00+08:00',

            // Check documents.
            'count(/course/document)' => 4,

            'string(/course/document[1]/@sequence)' => 1,
            'string(/course/document[1]/@name)' => 'D1',
            'string(/course/document[1]/@href)' => $CFG->wwwroot . '/mod/oucontent/view.php?id=101',
            'string(/course/document[1]/@cmid)' => 101,
            'string(/course/document[1]/@oucontentid)' => 1,
            'string(/course/document[1]/@restricted)' => 'false',
            'string(/course/document[1]/@error)' => 'minimal',
            'count(/course/document[1]/@sectionid)' => 0,
            'count(/course/document[1]/@sectionnumber)' => 0,
            'count(/course/document[1]/@sectionname)' => 0,
            'count(/course/document[1]/@filename)' => 0,
            'count(/course/document[1]/@published)' => 0,

            'string(/course/document[2]/@sequence)' => 2,
            'string(/course/document[2]/@name)' => 'D2',
            'string(/course/document[2]/@href)' => $CFG->wwwroot . '/mod/oucontent/view.php?id=102',
            'string(/course/document[2]/@cmid)' => 102,
            'string(/course/document[2]/@oucontentid)' => 2,
            'string(/course/document[2]/@restricted)' => 'false',
            'string(/course/document[2]/@error)' => 'published',
            'count(/course/document[2]/@sectionid)' => 0,
            'count(/course/document[2]/@sectionnumber)' => 0,
            'count(/course/document[2]/@sectionname)' => 0,
            'count(/course/document[2]/@filename)' => 0,
            'string(/course/document[2]/@published)' => '2024-09-29T08:00:00+08:00',

            'string(/course/document[3]/@sequence)' => 3,
            'string(/course/document[3]/@name)' => 'D3',
            'string(/course/document[3]/@href)' => $CFG->wwwroot . '/mod/oucontent/view.php?id=103',
            'string(/course/document[3]/@cmid)' => 103,
            'string(/course/document[3]/@oucontentid)' => 3,
            'string(/course/document[3]/@restricted)' => 'false',
            'string(/course/document[3]/@error)' => 'section',
            'string(/course/document[3]/@sectionid)' => 200,
            'string(/course/document[3]/@sectionnumber)' => 17,
            'string(/course/document[3]/@sectionname)' => 'Week 17',
            'count(/course/document[3]/@filename)' => 0,
            'string(/course/document[3]/@published)' => '2024-09-29T08:00:00+08:00',

            'string(/course/document[4]/@sequence)' => 4,
            'string(/course/document[4]/@name)' => 'D4',
            'string(/course/document[4]/@href)' => $CFG->wwwroot . '/mod/oucontent/view.php?id=104',
            'string(/course/document[4]/@cmid)' => 104,
            'string(/course/document[4]/@oucontentid)' => 4,
            'string(/course/document[4]/@restricted)' => 'true',
            'count(/course/document[4]/@error)' => 0,
            'string(/course/document[4]/@sectionid)' => 200,
            'string(/course/document[4]/@sectionnumber)' => 17,
            'string(/course/document[4]/@sectionname)' => 'Week 17',
            'string(/course/document[4]/@filename)' => 'whatever.xml',
            'string(/course/document[4]/@published)' => '2024-09-30T08:00:00+08:00',
        ];

        $this->assert_xpaths($xpath, $xpaths);
    }

    /**
     * Checks a number of xpaths.
     *
     * @param \DOMXPath $xpath Xpath evaluator
     * @param array $xpaths Array from xpath => expected value
     */
    protected function assert_xpaths(\DOMXPath $xpath, array $xpaths): void {
        foreach ($xpaths as $xpathstr => $expected) {
            $this->assertEquals($expected, $xpath->evaluate($xpathstr),
                'Failed xpath ' . $xpathstr);
        }
    }

    /**
     * Tests the {@see export_all::get_file_record()} function.
     */
    public function test_get_file_record(): void {
        $filerecord = export_all::get_file_record(123);
        $this->assertEquals(\context_system::instance()->id, $filerecord->contextid);
        $this->assertEquals(export_all::FILE_COMPONENT, $filerecord->component);
        $this->assertEquals(export_all::FILE_FILEAREA, $filerecord->filearea);
        $this->assertEquals(0, $filerecord->itemid);
        $this->assertEquals('/', $filerecord->filepath);
        $this->assertEquals('123.zip', $filerecord->filename);
    }

    /**
     * Tests the {@see export_all::get_file()} function.
     */
    public function test_get_file(): void {
        $this->resetAfterTest();

        // Null if file does not exist.
        $file = export_all::get_file(123);
        $this->assertNull($file);

        // Create file according to get_file_record.
        $fs = get_file_storage();
        $filerecord = export_all::get_file_record(123);
        $fs->create_file_from_string($filerecord, 'fake');

        // File now is returned correctly.
        $file = export_all::get_file(123);
        $this->assertEquals(4, $file->get_filesize());
    }

    /**
     * Tests the {@see export_all::get_download_token()} and
     * {@see export_all::verify_download_token()} functions.
     */
    public function test_download_tokens(): void {
        $this->resetAfterTest();

        // Create a course with download (there are no documents but doesn't matter).
        $generator = self::getDataGenerator();
        $course = $generator->create_course();
        $tempfolder = make_request_directory();
        export_all::process_course($course->id, $tempfolder);

        // Verify a valid token.
        $this->setAdminUser();
        $token = export_all::get_download_token($course->id);
        $file = export_all::verify_download_token($token);
        $this->assertEquals($course->id . '.zip', $file->get_filename());

        // Use a token that has expired.
        $this->mock_clock_with_frozen(1000);
        $token = export_all::get_download_token($course->id);
        $this->mock_clock_with_frozen(1000 + export_all::LINK_EXPIRY + 1);
        try {
            export_all::verify_download_token($token);
            $this->fail();
        } catch (\moodle_exception $e) {
            $this->assertEquals('expired', $e->debuginfo);
        }

        // Test with user who is not an admin.
        $user = $generator->create_user();
        $this->setUser($user);
        $token = export_all::get_download_token($course->id);
        try {
            export_all::verify_download_token($token);
            $this->fail();
        } catch (\moodle_exception $e) {
            $this->assertEquals('notadmin', $e->debuginfo);
        }

        // Test with invalid signature.
        $this->setAdminUser();
        $token = export_all::get_download_token($course->id);
        if ($token[30] === 'a') {
            $token[30] = 'b';
        } else {
            $token[30] = 'a';
        }
        try {
            export_all::verify_download_token($token);
            $this->fail();
        } catch (\moodle_exception $e) {
            $this->assertEquals('invalidtoken', $e->debuginfo);
        }

        // Test when there isn't any such file.
        $token = export_all::get_download_token($course->id + 1);
        try {
            export_all::verify_download_token($token);
            $this->fail();
        } catch (\moodle_exception $e) {
            $this->assertEquals('nofile', $e->debuginfo);
        }
    }

    /**
     * Tests {@see export_all::get_available_files()}.
     */
    public function test_get_available_files(): void {
        $this->resetAfterTest();

        // Initial check that it works with no files.
        $this->assertCount(0, export_all::get_available_files());

        // Create a course with download (there are no documents but doesn't matter).
        $tempfolder = make_request_directory();
        $generator = self::getDataGenerator();
        $courses = [];
        for ($i = 0; $i < 3; $i++) {
            $course = $generator->create_course();
            export_all::process_course($course->id, $tempfolder);
            $courses[] = $course;
        }

        // Check we have the right number of files.
        $files = export_all::get_available_files();
        $this->assertCount(count($courses), $files);

        // Check the filenames match the expected course ids.
        $filenames = array_map(fn($file) => $file->get_filename(), $files);
        sort($filenames);
        $expectedfilenames = array_map(fn($course) => $course->id . '.zip', $courses);
        sort($expectedfilenames);
        $this->assertEquals($expectedfilenames, $filenames);
    }

    /**
     * Tests {@see export_all::list_courses_for_update_and_delete_old_files()}.
     */
    public function test_list_courses_for_update_and_delete_old_files(): void {
        $this->resetAfterTest();

        // When there are no courses with SC documents, it returns nothing.
        $this->assertEquals([],
            export_all::list_courses_for_update_and_delete_old_files());

        $this->resetAfterTest();

        // Make 3 courses with documents in.
        $generator = $this->getDataGenerator();
        $oucontentgenerator = $generator->get_plugin_generator('mod_oucontent');
        $courses = [];
        for ($i = 1; $i <= 3; $i++) {
            $courses[$i] = $generator->create_course(['format' => 'oustudyplan']);
        }
        \mod_oucontent\util::fix_time(1100);
        $oucontentgenerator->create_instance([
            'course' => $courses[1]->id,
            'section' => 0,
        ], [
            'xmlfile' => 'minimal.xml',
        ]);
        \mod_oucontent\util::fix_time(1000);
        $oucontentgenerator->create_instance([
            'course' => $courses[2]->id,
            'section' => 0,
        ], [
            'xmlfile' => 'minimal.xml',
        ]);
        \mod_oucontent\util::fix_time(1200);
        $oucontentgenerator->create_instance([
            'course' => $courses[3]->id,
            'section' => 0,
        ], [
            'xmlfile' => 'minimal.xml',
        ]);

        // Make a fake existing zipfile for course3.
        $fs = get_file_storage();
        $record = export_all::get_file_record($courses[3]->id);
        $record->timemodified = 1200;
        $fs->create_file_from_string($record, 'hmmm');

        // Also make a stored file for a nonexistent course, check it exists.
        $nosuchcourse = $courses[3]->id + 1;
        $unused = export_all::get_file_record($nosuchcourse);
        $fs->create_file_from_string($unused, 'whatever');
        $this->assertNotNull(export_all::get_file($nosuchcourse));

        // The function should return course2 first as it has the oldest date, then course 1,
        // and not course3 as the existing zip already matches the date.
        $this->assertEquals([$courses[2]->id, $courses[1]->id],
            export_all::list_courses_for_update_and_delete_old_files());

        // The existing file for course 3 should not be deleted, the one for missing course should.
        $this->assertNotNull(export_all::get_file($courses[3]->id));
        $this->assertNull(export_all::get_file($nosuchcourse));
    }
}
