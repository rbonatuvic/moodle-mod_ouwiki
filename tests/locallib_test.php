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
 * OUWiki unit tests - test locallib functions
 *
 * @package    mod_ouwiki
 * @copyright  2014 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

if (!defined('MOODLE_INTERNAL')) {
    die('Direct access to this script is forbidden.'); // It must be included from a Moodle page.
}
global $CFG;

require_once($CFG->dirroot . '/mod/ouwiki/locallib.php');

class ouwiki_locallib_test extends advanced_testcase {

    /**
     * OU Wiki generator reference
     * @var testing_module_generator
     */
    public $generator = null;

    /**
     * Create temporary test tables and entries in the database for these tests.
     * These tests have to work on a brand new site.
     */
    public function setUp() {
        global $CFG;

        parent::setup();

        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_ouwiki');
    }

    /*

    Backend functions covered:

    ouwiki_get_subwiki()
    ouwiki_get_current_page()
    ouwiki_save_new_version()
    ouwiki_create_new_page()
    ouwiki_get_page_history()
    ouwiki_get_page_version()
    ouwiki_get_subwiki_recentpages()
    ouwiki_get_subwiki_recentchanges()
    ouwiki_init_pages()

    Functions not covered:
    Delete/undelete page version - no backend functions for this process
    File attachment - difficult to test through backend functions due to moodle core handling of files

    */


    public function test_ouwiki_pages_and_versions() {
        $this->resetAfterTest(true);
        $user = $this->get_new_user();
        $course = $this->get_new_course();

        // Setup a wiki to use.
        $ouwiki = $this->get_new_ouwiki($course->id, OUWIKI_SUBWIKIS_SINGLE);
        $cm = get_coursemodule_from_instance('ouwiki', $ouwiki->id);
        $this->assertNotEmpty($cm);
        $context = context_module::instance($cm->id);
        $groupid = 0;
        $this->setUser($user);
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, $groupid, $user->id, true);

        // Create the start page.
        $startpagename = 'startpage';
        $formdata = null;
        $startpageversion = ouwiki_get_current_page($subwiki, $startpagename, OUWIKI_GETPAGE_CREATE);
        $verid = ouwiki_save_new_version($course, $cm, $ouwiki, $subwiki, $startpagename, $startpagename, -1, -1, -1, null, $formdata);
        $this->assertEquals(1, $verid);
        // Create a page.
        $pagename1 = 'testpage1';
        $content1 = 'testcontent';
        ouwiki_create_new_page($course, $cm, $ouwiki, $subwiki, $startpagename, $pagename1, $content1, $formdata);

        // Try get that page.
        $pageversion = ouwiki_get_current_page($subwiki, $pagename1);
        $this->assertEquals($pageversion->title, $pagename1);
        // Test fullname info from ouwiki_get_current_page.
        $this->assertEquals(fullname($user), fullname($pageversion));

        // Make some more versions.
        $content2 = 'testcontent2';
        $content3 = 'testcontent3';
        ouwiki_save_new_version($course, $cm, $ouwiki, $subwiki, $pagename1, $content2, -1, -1, -1, null, $formdata);
        $verid = ouwiki_save_new_version($course, $cm, $ouwiki, $subwiki, $pagename1, $content3, -1, -1, -1, null, $formdata);
        $this->assertEquals(5, $verid);
        $pageversion = ouwiki_get_current_page($subwiki, $pagename1);
        $this->assertEquals($content3, $pageversion->xhtml);

        // Get the history.
        $history = ouwiki_get_page_history($pageversion->pageid, true);
        $this->assertEquals('array', gettype($history));

        // Last version should match $content3.
        $version = array_shift($history);
        $this->assertEquals(5, $version->versionid);
        $this->assertEquals($user->id, $version->id);
        $this->assertEquals(1, $version->wordcount);
        $this->assertEquals(4, $version->previousversionid);
        $this->assertNull($version->importversionid);

        // Add another page.
        $pagename2 = 'testpage2';
        $content4 = 'testcontent4';

        // We don't get anything returned for this.
        ouwiki_create_new_page($course, $cm, $ouwiki, $subwiki, $startpagename, $pagename2, $content4, $formdata);

        // Test recent pages.
        $changes = ouwiki_get_subwiki_recentpages($subwiki->id);
        $this->assertEquals('array', gettype($changes));
        $this->assertEquals(fullname($user), fullname($changes[1]));
        // First page should be startpage.
        $this->assertEquals($changes[1]->title, $startpagename);
        // 3rd page should be pagename2.
        $this->assertEquals($changes[3]->title, $pagename2);

        $testfullname = fullname($changes[1]);
        $this->assertEquals(fullname($user), $testfullname);

        // Test recent wiki changes.
        $changes = ouwiki_get_subwiki_recentchanges($subwiki->id);
        $testfullname = fullname($changes[1]);
        $this->assertEquals(fullname($user), $testfullname);
        $this->assertEquals($changes[1]->title, $startpagename);
        // Sixth change should be to testpage2  - when we created testpage2.
        $this->assertEquals($changes[6]->title, $pagename2);
        // Seventh change shouldbe start page again - when we linked to testpage2 to startpage.
        $this->assertEquals($changes[7]->title, $startpagename);

    }

    public function test_ouwiki_init_course_wiki_access() {
        global $DB;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $user = $this->get_new_user();
        $course = $this->get_new_course();
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $studentrole->id);
        $ouwiki = $this->get_new_ouwiki($course->id, OUWIKI_SUBWIKIS_SINGLE);
        $cm = get_coursemodule_from_instance('ouwiki', $ouwiki->id);
        $this->assertNotEmpty($cm);
        $context = context_module::instance($cm->id);
        // Add annotation for student role as not allowed by default.
        role_change_permission($studentrole->id, $context, 'mod/ouwiki:annotate', CAP_ALLOW);
        $this->setUser($user);
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, 0, $user->id, true);
        $createdsubwikiid = $subwiki->id;
        $this->check_subwiki($ouwiki, $subwiki, true);

        // Get the same one we created above (without 'create').
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, 0, $user->id);
        $this->assertEquals($subwiki->id, $createdsubwikiid);
    }

    public function test_ouwiki_init_group_wiki_access() {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        // Create course, ouwiki, course module, context, groupid, userid.
        $user = $this->get_new_user();
        $course = $this->get_new_course();
        // Enrol user as student on course.
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $studentrole->id);

        // Store admin user id for later use.
        $adminuserid = $USER->id;

        $this->setUser($user);

        // Test group wikis (visible - test access across groups).
        $this->setAdminUser();
        $ouwiki = $this->get_new_ouwiki($course->id, OUWIKI_SUBWIKIS_GROUPS, array('groupmode' => VISIBLEGROUPS));
        $cm = get_coursemodule_from_instance('ouwiki', $ouwiki->id);
        $this->assertNotEmpty($cm);
        $context = context_module::instance($cm->id);

        $group1 = $this->get_new_group($course->id);
        $group2 = $this->get_new_group($course->id);

        $this->setUser($user);

        // Subwiki with 'create'.
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, $group1->id, $user->id, true);
        $createdsubwikiid = $subwiki->id;
        $this->check_subwiki($ouwiki, $subwiki, false, $group1->id);

        // Add annotation for student role as not allowed by default.
        role_change_permission($studentrole->id, $context, 'mod/ouwiki:annotate', CAP_ALLOW);
        $member = $this->get_new_group_member($group1->id, $user->id);// Adds our user to group1.

        // Check student can access, now in group.
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, $group1->id, $user->id, true);
        $this->assertEquals($subwiki->id, $createdsubwikiid);
        $this->check_subwiki($ouwiki, $subwiki, true, $group1->id);

        // Check student edit/annotate access to other group wiki when has specific capabilities.
        role_change_permission($studentrole->id, $context, 'mod/ouwiki:annotateothers', CAP_ALLOW);
        role_change_permission($studentrole->id, $context, 'mod/ouwiki:editothers', CAP_ALLOW);
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, $group2->id, $user->id, true);
        $this->check_subwiki($ouwiki, $subwiki, true, $group2->id);

        // Check admin has access to any group.
        $this->setAdminUser();
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, $group1->id, $USER->id);
        $this->check_subwiki($ouwiki, $subwiki, true, $group1->id);

        // Check separate groups (student should only edit own group).
        $ouwiki = $this->get_new_ouwiki($course->id, OUWIKI_SUBWIKIS_GROUPS, array('groupmode' => SEPARATEGROUPS));
        $cm = get_coursemodule_from_instance('ouwiki', $ouwiki->id);
        $this->assertNotEmpty($cm);
        $context = context_module::instance($cm->id);
        $this->setUser($user);
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, $group2->id, $user->id, true);
        $this->check_subwiki($ouwiki, $subwiki, false, $group2->id);
    }

    public function test_ouwiki_init_individual_wiki_access() {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        // Create course, ouwiki, course module, context, groupid, userid.
        $user = $this->get_new_user();
        $course = $this->get_new_course();
        // Enrol user as student on course.
        $studentrole = $DB->get_record('role', array('shortname' => 'student'));
        $this->getDataGenerator()->enrol_user($user->id, $course->id, $studentrole->id);

        // Store admin user id for later use.
        $adminuserid = $USER->id;

        $this->setUser($user);

        // Test invididual wikis.
        $ouwiki = $this->get_new_ouwiki($course->id, OUWIKI_SUBWIKIS_INDIVIDUAL);
        $cm = get_coursemodule_from_instance('ouwiki', $ouwiki->id);
        $this->assertNotEmpty($cm);
        $context = context_module::instance($cm->id);
        $groupid = 0;
        // Add annotation for student role as not allowed by default.
        role_change_permission($studentrole->id, $context, 'mod/ouwiki:annotate', CAP_ALLOW);

        // Subwiki with 'create'.
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, $groupid, $user->id, true);
        $this->check_subwiki($ouwiki, $subwiki, true, $user->id);

        // Check admin can access students wiki just created.
        $this->setAdminUser();
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, $groupid, $user->id);
        $this->check_subwiki($ouwiki, $subwiki, true, $user->id);

        // Check student viewing someone else's wiki throws exception (add nothing after this).
        $this->setUser($user);
        $this->setExpectedException('moodle_exception');
        $subwiki = ouwiki_get_subwiki($course, $ouwiki, $cm, $context, $groupid, $adminuserid, true);
        $this->fail('Expected exception on access to another users wiki');// Shouldn't get here.
    }

    public function test_ouwiki_word_count() {
        $tests = array();

        $test['string'] = "This is four words";
        $test['count'] = 4;
        $testcount = ouwiki_count_words($test['string']);
        $this->assertEquals($test['count'], $testcount);

        $test['string'] = " ";
        $test['count'] = 0;
        $testcount = ouwiki_count_words($test['string']);
        $this->assertEquals($test['count'], $testcount);

        $test['string'] = "word";
        $test['count'] = 1;
        $testcount = ouwiki_count_words($test['string']);
        $this->assertEquals($test['count'], $testcount);

        $test['string'] = "Two\n\nwords";
        $test['count'] = 2;
        $testcount = ouwiki_count_words($test['string']);
        $this->assertEquals($test['count'], $testcount);

        $test['string'] = "<p><b>two <i>words</i></b></p>";
        $test['count'] = 2;
        $testcount = ouwiki_count_words($test['string']);
        $this->assertEquals($test['count'], $testcount);

        $test['string'] = "Isnâ€™t it three";
        $test['count'] = 3;
        $testcount = ouwiki_count_words($test['string']);
        $this->assertEquals($test['count'], $testcount);

        $test['string'] = "Isn't it three";
        $test['count'] = 3;
        $testcount = ouwiki_count_words($test['string']);
        $this->assertEquals($test['count'], $testcount);

        $test['string'] = "three-times-hyphenated words";
        $test['count'] = 2;
        $testcount = ouwiki_count_words($test['string']);
        $this->assertEquals($test['count'], $testcount);

        $test['string'] = "one,two,さん";
        $test['count'] = 3;
        $testcount = ouwiki_count_words($test['string']);
        $this->assertEquals($test['count'], $testcount);

        $test['string'] = 'Two&nbsp;words&nbsp;&nbsp;&nbsp;&nbsp;';
        $test['count'] = 2;
        $testcount = ouwiki_count_words($test['string']);
        $this->assertEquals($test['count'], $testcount);
    }

    /*
     These functions enable us to create database entries and/or grab objects to make it possible to test the
    many permuations required for OU Wiki.
    */

    public function get_new_user() {
        return $this->getDataGenerator()->create_user(array('username' => 'testouwikiuser'));
    }


    public function get_new_course() {
        return $this->getDataGenerator()->create_course(array('shortname' => 'ouwikitest'));
    }

    public function get_new_ouwiki($courseid, $subwikis = null, $options = array()) {

        $ouwiki = new stdClass();
        $ouwiki->course = $courseid;

        if ($subwikis != null) {
            $ouwiki->subwikis = $subwikis;
        }

        $ouwiki->timeout = null;
        $ouwiki->template = null;
        $ouwiki->editbegin = null;
        $ouwiki->editend = null;

        $ouwiki->completionpages = 0;
        $ouwiki->completionedits = 0;

        $ouwiki->introformat = 0;

        return $this->generator->create_instance($ouwiki, $options);

    }

    public function get_new_group($courseid) {
        static $counter = 0;
        $counter++;
        $group = new stdClass();
        $group->courseid = $courseid;
        $group->name = 'test group' . $counter;
        return $this->getDataGenerator()->create_group($group);
    }

    public function get_new_group_member($groupid, $userid) {
        $member = new stdClass();
        $member->groupid = $groupid;
        $member->userid = $userid;
        return $this->getDataGenerator()->create_group_member($member);
    }

    /**
     * Checks subwiki object created as expected
     * @param object $ouwiki
     * @param object $subwiki
     * @param boolean $canaccess - true if user can access + edit etc
     * @param int $userorgroup - set to expected user or group id for group/individual wikis
     */
    public function check_subwiki($ouwiki, $subwiki, $canaccess = true, $userorgroup = null) {
        $this->assertInstanceOf('stdClass', $subwiki);
        $this->assertEquals($ouwiki->id, $subwiki->wikiid);
        if ($ouwiki->subwikis == OUWIKI_SUBWIKIS_SINGLE) {
            $this->assertNull($subwiki->groupid);
            $this->assertNull($subwiki->userid);
        } else if ($ouwiki->subwikis == OUWIKI_SUBWIKIS_GROUPS) {
            $this->assertEquals($userorgroup, $subwiki->groupid);
        } else if ($ouwiki->subwikis == OUWIKI_SUBWIKIS_INDIVIDUAL) {
            $this->assertEquals($userorgroup, $subwiki->userid);
        }
        if ($ouwiki->annotation == 1) {
            $this->assertEquals(1, $subwiki->annotation);
        }
        $this->assertEquals($canaccess, $subwiki->canedit);
        $this->assertEquals($canaccess, $subwiki->canannotate);
    }
}
