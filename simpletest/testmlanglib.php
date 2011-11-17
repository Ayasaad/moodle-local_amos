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
 * Unit tests for Moodle language manipulation library defined in mlanglib.php
 *
 * @package   local-amos
 * @copyright 2010 David Mudrak <david.mudrak@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $DB, $CFG;

if (empty($CFG->unittestprefix)) {
    die('You must define $CFG->unittestprefix to run these unit tests.');
}

require_once($CFG->dirroot . '/local/amos/mlanglib.php'); // Include the code to test

/**
 * Makes protected method accessible for testing purposes
 */
class testable_mlang_tools extends mlang_tools {
    public static function legacy_component_name($newstyle) {
        return parent::legacy_component_name($newstyle);
    }
}

/**
 * Test cases for the internal workshop api
 */
class mlang_test extends UnitTestCase {

    /** setup testing environment */
    public function setUp() {
        global $DB, $CFG;

        $this->realDB = $DB;
        $dbclass = get_class($this->realDB);
        $DB = new $dbclass();
        $DB->connect($CFG->dbhost, $CFG->dbuser, $CFG->dbpass, $CFG->dbname, $CFG->unittestprefix);

        if ($DB->get_manager()->table_exists('amos_repository')) {
            $DB->get_manager()->delete_tables_from_xmldb_file($CFG->dirroot . '/local/amos/db/install.xml');
        }
        $DB->get_manager()->install_from_xmldb_file($CFG->dirroot . '/local/amos/db/install.xml');
    }

    public function tearDown() {
        global $DB, $CFG;

        // $DB->get_manager()->delete_tables_from_xmldb_file($CFG->dirroot . '/local/amos/db/install.xml');
        $DB = $this->realDB;
    }

    /**
     * Excercise various helper methods
     */
    public function test_helpers() {
        $this->assertEqual('workshop', mlang_component::name_from_filename('/web/moodle/mod/workshop/lang/en/workshop.php'));
        $this->assertFalse(mlang_string::differ(
                new mlang_string('first', 'This is a test string'),
                new mlang_string('second', 'This is a test string')));
        $this->assertFalse(mlang_string::differ(
                new mlang_string('first', '  This is a test string  '),
                new mlang_string('second', 'This is a test string ')));
        $this->assertTrue(mlang_string::differ(
                new mlang_string('first', 'This is a test string'),
                new mlang_string('first', 'This is a test string!')));
        $this->assertTrue(mlang_string::differ(
                new mlang_string('empty', ''),
                new mlang_string('null', null)));
        $this->assertFalse(mlang_string::differ(
                new mlang_string('null', null),
                new mlang_string('anothernull', null)));
    }

    /**
     * Standard procedure to add strings into AMOS repository
     */
    public function test_simple_string_lifecycle() {
        global $DB, $CFG, $USER;

        // basic operations
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertFalse($component->has_string());
        $this->assertFalse($component->has_string('nonexisting'));
        $this->assertFalse($component->has_string('welcome'));
        $component->add_string(new mlang_string('welcome', 'Welcome'));
        $this->assertTrue($component->has_string());
        $this->assertFalse($component->has_string('nonexisting'));
        $this->assertTrue($component->has_string('welcome'));
        $this->assertNull($component->get_string('nonexisting'));
        $s = $component->get_string('welcome');
        $this->assertTrue($s instanceof mlang_string);
        $this->assertEqual('welcome', $s->id);
        $this->assertEqual('Welcome', $s->text);
        $component->unlink_string('nonexisting');
        $this->assertTrue($component->has_string());
        $component->unlink_string('welcome');
        $this->assertFalse($component->has_string());
        $component->add_string(new mlang_string('welcome', 'Welcome'));
        $component->clear();
        unset($component);

        // commit a single string
        $now = time();
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('welcome', 'Welcome', $now));
        $stage = new mlang_stage();
        $stage->add($component);
        $stage->commit('First string in AMOS', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unset($stage);
        $this->assertEqual(1, $DB->count_records('amos_repository'));
        $this->assertTrue($DB->record_exists('amos_repository', array(
                'component' => 'test', 'lang' => 'xx', 'stringid' => 'welcome', 'timemodified' => $now)));

        // add two other strings
        $now = time();
        $stage = new mlang_stage();
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('hello', 'Hello', $now));
        $component->add_string(new mlang_string('world', 'World', $now));
        $stage->add($component);
        $stage->commit('Two other string into AMOS', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unset($stage);
        $this->assertEqual(3, $DB->count_records('amos_repository'));
        $this->assertTrue($DB->record_exists('amos_repository', array(
                'component' => 'test', 'lang' => 'xx', 'stringid' => 'hello', 'timemodified' => $now)));
        $this->assertTrue($DB->record_exists('amos_repository', array(
                'component' => 'test', 'lang' => 'xx', 'stringid' => 'world', 'timemodified' => $now)));

        // delete a string
        $now = time();
        $stage = new mlang_stage();
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('welcome', '', $now, true));
        $stage->add($component);
        $stage->commit('Marking string as deleted', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unset($stage);
        $this->assertEqual(4, $DB->count_records('amos_repository'));
        $this->assertTrue($DB->record_exists('amos_repository', array(
                'component' => 'test', 'lang' => 'xx', 'stringid' => 'welcome', 'timemodified' => $now, 'deleted' => 1)));
    }

    public function test_add_existing_string() {
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_22_STABLE'));
        $this->assertFalse($component->has_string());
        $component->add_string(new mlang_string('welcome', 'Welcome'));
        $component->add_string(new mlang_string('welcome', 'Overwriting existing must be forced'), true);
        $this->assertEqual($component->get_string('welcome')->text, 'Overwriting existing must be forced');
        $this->expectException();
        $component->add_string(new mlang_string('welcome', 'Overwriting existing throws exception'));
        $component->clear();
        unset($component);
    }

    public function test_deleted_has_same_timemodified() {
        $now = time();
        $stage = new mlang_stage();
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('welcome', 'Welcome', $now));
        $stage = new mlang_stage();
        $stage->add($component);
        $stage->commit('First string in AMOS', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unset($stage);

        $stage = new mlang_stage();
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('welcome', '', $now, true));
        $stage->add($component);
        $stage->commit('Marking string as deleted', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unset($stage);

        $component = mlang_component::from_snapshot('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertFalse($component->has_string());
        $component->clear();
        unset($component);
    }

    public function test_more_recently_inserted_wins() {
        $now = time();
        $stage = new mlang_stage();
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('welcome', 'Welcome', $now));
        $stage = new mlang_stage();
        $stage->add($component);
        $stage->commit('First string in AMOS', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unset($stage);

        $stage = new mlang_stage();
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('welcome', 'Welcome!', $now));
        $stage->add($component);
        $stage->commit('Making a modification of the string with the same timestamp', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unset($stage);

        $component = mlang_component::from_snapshot('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertEqual($component->get_string('welcome')->text, 'Welcome!');
        $component->clear();
        unset($component);

        $stage = new mlang_stage();
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('welcome', 'Welcome!', $now, 1));
        $stage->add($component);
        $stage->commit('Deleting the string with the same timestamp', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unset($stage);

        $component = mlang_component::from_snapshot('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertFalse($component->has_string('welcome'));
        $component->clear();
        unset($component);
    }

    public function test_component_from_phpfile_same_timestamp() {
        $now = time();
        // get the initial strings from a file
        $filecontents = <<<EOF
<?php
\$string['welcome'] = 'Welcome';

EOF;
        $tmp = make_upload_directory('temp/amos');
        $filepath = $tmp . '/mlangunittest.php';
        file_put_contents($filepath, $filecontents);

        $component = mlang_component::from_phpfile($filepath, 'en', mlang_version::by_branch('MOODLE_20_STABLE'), $now, 'test', 2);
        $stage = new mlang_stage();
        $stage->add($component);
        $stage->commit('First string in AMOS', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unlink($filepath);

        $filecontents = <<<EOF
<?php
\$string['welcome'] = 'Welcome!';

EOF;
        file_put_contents($filepath, $filecontents);

        // commit modified file with the same timestamp
        $component = mlang_component::from_phpfile($filepath, 'en', mlang_version::by_branch('MOODLE_20_STABLE'), $now, 'test', 2);
        $stage->add($component);
        $stage->commit('The string modified in the same timestamp', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unset($stage);
        unlink($filepath);

        // now make sure that the more recently committed string wins
        $component = mlang_component::from_snapshot('test', 'en', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertEqual($component->get_string('welcome')->text, 'Welcome!');
        $component->clear();
        unset($component);
    }

    public function test_component_from_phpfile_legacy_format() {
        $filecontents = <<<EOF
<?php
\$string['about'] = 'Multiline
string';
\$string['pluginname'] = 'AMOS';
\$string['author'] = 'David Mudrak';
\$string['syntax'] = 'What \$a\\'Pe%%\\"be';
\$string['percents'] = '%%Y-%%m-%%d-%%H-%%M';

EOF;
        $tmp = make_upload_directory('temp/amos');
        $filepath = $tmp . '/mlangunittest.php';
        file_put_contents($filepath, $filecontents);

        $component = mlang_component::from_phpfile($filepath, 'en', mlang_version::by_branch('MOODLE_20_STABLE'), null, null, 1);
        $this->assertTrue($component->has_string('about'));
        $this->assertTrue($component->has_string('pluginname'));
        $this->assertTrue($component->has_string('author'));
        $this->assertTrue($component->has_string('syntax'));
        $this->assertEqual("Multiline\nstring", $component->get_string('about')->text);
        $this->assertEqual('What {$a}\'Pe%"be', $component->get_string('syntax')->text);
        $this->assertEqual('%Y-%m-%d-%H-%M', $component->get_string('percents')->text);
        $component->clear();

        $component = mlang_component::from_phpfile($filepath, 'en', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertTrue($component->has_string('about'));
        $this->assertTrue($component->has_string('pluginname'));
        $this->assertTrue($component->has_string('author'));
        $this->assertTrue($component->has_string('syntax'));
        $this->assertEqual("Multiline\nstring", $component->get_string('about')->text);
        $this->assertEqual('What $a\'Pe%%"be', $component->get_string('syntax')->text);
        $this->assertEqual('%%Y-%%m-%%d-%%H-%%M', $component->get_string('percents')->text);
        $component->clear();

        $component = mlang_component::from_phpfile($filepath, 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $this->assertTrue($component->has_string('about'));
        $this->assertTrue($component->has_string('pluginname'));
        $this->assertTrue($component->has_string('author'));
        $this->assertTrue($component->has_string('syntax'));
        $this->assertEqual("Multiline\nstring", $component->get_string('about')->text);
        $this->assertEqual('What $a\'Pe%%\\"be', $component->get_string('syntax')->text);
        $this->assertEqual('%%Y-%%m-%%d-%%H-%%M', $component->get_string('percents')->text);
        $component->clear();

        unlink($filepath);
    }

    public function test_explicit_rebasing() {
        // prepare the "cap" (base for rebasing)
        $stage = new mlang_stage();
        $component = new mlang_component('numbers', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $component->add_string(new mlang_string('one', 'One'));
        $component->add_string(new mlang_string('two', 'Two'));
        $component->add_string(new mlang_string('three', 'Tree')); // typo here
        $stage->add($component);
        $stage->commit('Initial commit', array('source' => 'unittest'), true);
        $component->clear();
        unset($component);
        unset($stage);

        // rebasing without string removal - the stage is not complete
        $stage = new mlang_stage();
        $this->assertFalse($stage->has_component());
        $component = new mlang_component('numbers', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $component->add_string(new mlang_string('one', 'One')); // same as the current
        $component->add_string(new mlang_string('two', 'Two')); // same as the current
        $component->add_string(new mlang_string('three', 'Three')); // changed
        $stage->add($component);
        $stage->rebase();
        $rebased = $stage->get_component('numbers', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $this->assertTrue($rebased instanceof mlang_component);
        $this->assertFalse($rebased->has_string('one'));
        $this->assertFalse($rebased->has_string('two'));
        $this->assertTrue($rebased->has_string('three'));
        $s = $rebased->get_string('three');
        $this->assertEqual('Three', $s->text);
        $stage->clear();
        $component->clear();
        unset($component);
        unset($stage);

        // rebasing with string removal - the stage is considered to be the full snapshot of the state
        $stage = new mlang_stage();
        $this->assertFalse($stage->has_component());
        $component = new mlang_component('numbers', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $component->add_string(new mlang_string('one', 'One')); // same as the current
        // string 'two' is missing and we want it to be removed from repository
        $component->add_string(new mlang_string('three', 'Three')); // changed
        $stage->add($component);
        $stage->rebase(null, true);
        $rebased = $stage->get_component('numbers', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $this->assertTrue($rebased instanceof mlang_component);
        $this->assertFalse($rebased->has_string('one'));
        $this->assertTrue($rebased->has_string('two'));
        $this->assertTrue($rebased->has_string('three'));
        $s = $rebased->get_string('two');
        $this->assertTrue($s->deleted);
        $s = $rebased->get_string('three');
        $this->assertEqual('Three', $s->text);
        $stage->clear();
        $component->clear();
        unset($component);
        unset($stage);
    }

    public function test_rebasing_deletion_already_deleted() {
        $stage = new mlang_stage();
        $this->assertFalse($stage->has_component());
        $component = new mlang_component('trash', 'cs', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('delme', 'Del me', time() - 1000, true));    // deleted string
        $stage->add($component);
        $stage->commit('The string was already born deleted... sad');
        $this->assertFalse($stage->has_component());
        unset($stage);

        $stage = new mlang_stage();
        $component = new mlang_component('trash', 'cs', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('delme', 'Del me', null, true));    // already deleted string
        $stage->add($component);
        $stage->rebase();
        $this->assertFalse($stage->has_component());
    }

    public function test_implicit_rebasing_during_commit() {
        global $DB;

        // prepare the "cap" (base for rebasing)
        $stage = new mlang_stage();
        $component = new mlang_component('numbers', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $component->add_string(new mlang_string('one', 'One'));
        $component->add_string(new mlang_string('two', 'Two'));
        $component->add_string(new mlang_string('three', 'Tree')); // typo here
        $stage->add($component);
        $stage->commit('Initial commit', array('source' => 'unittest'));
        $component->clear();
        unset($component);
        unset($stage);

        // commit the fix of the string
        $stage = new mlang_stage();
        $this->assertFalse($stage->has_component());
        $component = new mlang_component('numbers', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $component->add_string(new mlang_string('one', 'One')); // same as the current
        $component->add_string(new mlang_string('two', 'Two')); // same as the current
        $component->add_string(new mlang_string('three', 'Three')); // changed
        $stage->add($component);
        $stage->commit('Fixed typo Tree > Three', array('source' => 'unittest'));
        $component->clear();
        unset($component);
        unset($stage);
        $where = "component = 'numbers' AND lang = 'en' AND stringid = 'three'";
        $this->assertTrue($DB->record_exists_select('amos_repository',
                "$where AND ".$DB->sql_compare_text('text')." = ".$DB->sql_compare_text("'Tree'")));
        $this->assertTrue($DB->record_exists_select('amos_repository',
                "$where AND ".$DB->sql_compare_text('text')." = ".$DB->sql_compare_text("'Three'")));

        // get the most recent version (so called "cap") of the component
        $cap = mlang_component::from_snapshot('numbers', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $concat = '';
        foreach ($cap->get_iterator() as $s) {
            $concat .= $s->text;
        }
        // strings in the cap shall be ordered by stringid
        $this->assertEqual('OneThreeTwo', $concat);
    }

    /**
     * Rebasing should respect commits in whatever order so it is safe to re-run the import scripts
     */
    public function test_non_chronological_commits() {
        global $DB;

        // firstly commit the most recent version
        $today = time();
        $stage = new mlang_stage();
        $component = new mlang_component('things', 'en', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('foo', 'Today Foo', $today));   // changed today
        $component->add_string(new mlang_string('bar', 'New Bar', $today));     // new today
        $component->add_string(new mlang_string('job', 'Boring', $today));      // not changed
        $stage->add($component);
        $stage->commit('Initial commit', array('source' => 'unittest'));
        $component->clear();
        unset($component);
        unset($stage);

        // we are re-importing the history - let us commit the version that was actually created yesterday
        $yesterday = time() - DAYSECS;
        $stage = new mlang_stage();
        $this->assertFalse($stage->has_component());
        $component = new mlang_component('things', 'en', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('foo', 'Foo', $yesterday));         // as it was yesterday
        $component->add_string(new mlang_string('job', 'Boring', $yesterday));      // still the same
        $stage->add($component);
        $stage->rebase($yesterday, true, $yesterday);
        $rebased = $stage->get_component('things', 'en', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertIsA($rebased, 'mlang_component');
        $component->clear();
        unset($component);
        unset($stage);
        $this->assertTrue($rebased->has_string('foo'));
        $this->assertFalse($rebased->has_string('bar'));
        $this->assertTrue($rebased->has_string('job'));

        // and the same case using rebase() without deleting
        $stage = new mlang_stage();
        $this->assertFalse($stage->has_component());
        $component = new mlang_component('things', 'en', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('foo', 'Foo', $yesterday));         // as it was yesterday
        $component->add_string(new mlang_string('job', 'Boring', $yesterday));      // still the same
        $stage->add($component);
        $stage->rebase($yesterday);
        $rebased = $stage->get_component('things', 'en', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertIsA($rebased, 'mlang_component');
        $component->clear();
        unset($component);
        unset($stage);
        $this->assertTrue($rebased->has_string('foo'));
        $this->assertFalse($rebased->has_string('bar'));
        $this->assertTrue($rebased->has_string('job'));
    }

    /**
     * Sanity 1.x string
     * - all variables but $a placeholders must be escaped because the string is eval'ed
     * - all ' and " must be escaped
     * - all single % must be converted into %% for backwards compatibility
     */
    public function test_fix_syntax_sanity_v1_strings() {
        $this->assertEqual(mlang_string::fix_syntax('No change', 1), 'No change');
        $this->assertEqual(mlang_string::fix_syntax('Completed 100% of work', 1), 'Completed 100%% of work');
        $this->assertEqual(mlang_string::fix_syntax('Completed 100%% of work', 1), 'Completed 100%% of work');
        $this->assertEqual(mlang_string::fix_syntax("Windows\r\nsucks", 1), "Windows\nsucks");
        $this->assertEqual(mlang_string::fix_syntax("Linux\nsucks", 1), "Linux\nsucks");
        $this->assertEqual(mlang_string::fix_syntax("Mac\rsucks", 1), "Mac\nsucks");
        $this->assertEqual(mlang_string::fix_syntax("LINE TABULATION\x0Bnewline", 1), "LINE TABULATION\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("FORM FEED\x0Cnewline", 1), "FORM FEED\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("END OF TRANSMISSION BLOCK\x17newline", 1), "END OF TRANSMISSION BLOCK\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("END OF MEDIUM\x19newline", 1), "END OF MEDIUM\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("SUBSTITUTE\x1Anewline", 1), "SUBSTITUTE\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("Empty\n\n\n\n\n\nlines", 1), "Empty\n\nlines");
        $this->assertEqual(mlang_string::fix_syntax('Escape $variable names', 1), 'Escape \$variable names');
        $this->assertEqual(mlang_string::fix_syntax('Escape $alike names', 1), 'Escape \$alike names');
        $this->assertEqual(mlang_string::fix_syntax('String $a placeholder', 1), 'String $a placeholder');
        $this->assertEqual(mlang_string::fix_syntax('Escaped \$a', 1), 'Escaped \$a');
        $this->assertEqual(mlang_string::fix_syntax('Wrapped {$a}', 1), 'Wrapped {$a}');
        $this->assertEqual(mlang_string::fix_syntax('Trailing $a', 1), 'Trailing $a');
        $this->assertEqual(mlang_string::fix_syntax('$a leading', 1), '$a leading');
        $this->assertEqual(mlang_string::fix_syntax('Hit $a-times', 1), 'Hit $a-times'); // this is placeholder',
        $this->assertEqual(mlang_string::fix_syntax('This is $a_book', 1), 'This is \$a_book'); // this is not
        $this->assertEqual(mlang_string::fix_syntax('Bye $a, ttyl', 1), 'Bye $a, ttyl');
        $this->assertEqual(mlang_string::fix_syntax('Object $a->foo placeholder', 1), 'Object $a->foo placeholder');
        $this->assertEqual(mlang_string::fix_syntax('Trailing $a->bar', 1), 'Trailing $a->bar');
        $this->assertEqual(mlang_string::fix_syntax('<strong>AMOS</strong>', 1), '<strong>AMOS</strong>');
        $this->assertEqual(mlang_string::fix_syntax('<a href="http://localhost">AMOS</a>', 1), '<a href=\"http://localhost\">AMOS</a>');
        $this->assertEqual(mlang_string::fix_syntax('<a href=\"http://localhost\">AMOS</a>', 1), '<a href=\"http://localhost\">AMOS</a>');
        $this->assertEqual(mlang_string::fix_syntax("'Murder!', she wrote", 1), "'Murder!', she wrote"); // will be escaped by var_export()
        $this->assertEqual(mlang_string::fix_syntax("\t  Trim Hunter  \t\t", 1), 'Trim Hunter');
        $this->assertEqual(mlang_string::fix_syntax('Delete role "$a->role"?', 1), 'Delete role \"$a->role\"?');
        $this->assertEqual(mlang_string::fix_syntax('Delete role \"$a->role\"?', 1), 'Delete role \"$a->role\"?');
    }

    /**
     * Sanity 2.x string
     * - the string is not eval'ed any more - no need to escape $variables
     * - placeholders can be only {$a} or {$a->something} or {$a->some_thing}, nothing else
     * - quoting marks are not escaped
     * - percent signs are not duplicated any more, reverting them into single (is it good idea?)
     */
    public function test_fix_syntax_sanity_v2_strings() {
        $this->assertEqual(mlang_string::fix_syntax('No change'), 'No change');
        $this->assertEqual(mlang_string::fix_syntax('Completed 100% of work'), 'Completed 100% of work');
        $this->assertEqual(mlang_string::fix_syntax('%%%% HEADER %%%%'), '%%%% HEADER %%%%'); // was not possible before
        $this->assertEqual(mlang_string::fix_syntax("Windows\r\nsucks"), "Windows\nsucks");
        $this->assertEqual(mlang_string::fix_syntax("Linux\nsucks"), "Linux\nsucks");
        $this->assertEqual(mlang_string::fix_syntax("Mac\rsucks"), "Mac\nsucks");
        $this->assertEqual(mlang_string::fix_syntax("LINE TABULATION\x0Bnewline"), "LINE TABULATION\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("FORM FEED\x0Cnewline"), "FORM FEED\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("END OF TRANSMISSION BLOCK\x17newline"), "END OF TRANSMISSION BLOCK\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("END OF MEDIUM\x19newline"), "END OF MEDIUM\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("SUBSTITUTE\x1Anewline"), "SUBSTITUTE\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("Empty\n\n\n\n\n\nlines"), "Empty\n\n\nlines"); // now allows up to two empty lines
        $this->assertEqual(mlang_string::fix_syntax('Do not escape $variable names'), 'Do not escape $variable names');
        $this->assertEqual(mlang_string::fix_syntax('Do not escape $alike names'), 'Do not escape $alike names');
        $this->assertEqual(mlang_string::fix_syntax('Not $a placeholder'), 'Not $a placeholder');
        $this->assertEqual(mlang_string::fix_syntax('String {$a} placeholder'), 'String {$a} placeholder');
        $this->assertEqual(mlang_string::fix_syntax('Trailing {$a}'), 'Trailing {$a}');
        $this->assertEqual(mlang_string::fix_syntax('{$a} leading'), '{$a} leading');
        $this->assertEqual(mlang_string::fix_syntax('Trailing $a'), 'Trailing $a');
        $this->assertEqual(mlang_string::fix_syntax('$a leading'), '$a leading');
        $this->assertEqual(mlang_string::fix_syntax('Not $a->foo placeholder'), 'Not $a->foo placeholder');
        $this->assertEqual(mlang_string::fix_syntax('Object {$a->foo} placeholder'), 'Object {$a->foo} placeholder');
        $this->assertEqual(mlang_string::fix_syntax('Trailing $a->bar'), 'Trailing $a->bar');
        $this->assertEqual(mlang_string::fix_syntax('Invalid $a-> placeholder'), 'Invalid $a-> placeholder');
        $this->assertEqual(mlang_string::fix_syntax('<strong>AMOS</strong>'), '<strong>AMOS</strong>');
        $this->assertEqual(mlang_string::fix_syntax("'Murder!', she wrote"), "'Murder!', she wrote"); // will be escaped by var_export()
        $this->assertEqual(mlang_string::fix_syntax("\t  Trim Hunter  \t\t"), 'Trim Hunter');
        $this->assertEqual(mlang_string::fix_syntax('Delete role "$a->role"?'), 'Delete role "$a->role"?');
        $this->assertEqual(mlang_string::fix_syntax('Delete role \"$a->role\"?'), 'Delete role "$a->role"?');
    }

    /**
     * Converting 1.x strings into 2.x strings
     * - unescape all variables
     * - wrap all placeholders in curly brackets
     * - unescape quoting marks
     * - collapse percent signs
     */
    public function test_fix_syntax_converting_from_v1_to_v2() {
        $this->assertEqual(mlang_string::fix_syntax('No change', 2, 1), 'No change');
        $this->assertEqual(mlang_string::fix_syntax('Completed 100% of work', 2, 1), 'Completed 100% of work');
        $this->assertEqual(mlang_string::fix_syntax('Completed 100%% of work', 2, 1), 'Completed 100% of work');
        $this->assertEqual(mlang_string::fix_syntax("Windows\r\nsucks", 2, 1), "Windows\nsucks");
        $this->assertEqual(mlang_string::fix_syntax("Linux\nsucks", 2, 1), "Linux\nsucks");
        $this->assertEqual(mlang_string::fix_syntax("Mac\rsucks", 2, 1), "Mac\nsucks");
        $this->assertEqual(mlang_string::fix_syntax("LINE TABULATION\x0Bnewline", 2, 1), "LINE TABULATION\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("FORM FEED\x0Cnewline", 2, 1), "FORM FEED\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("END OF TRANSMISSION BLOCK\x17newline", 2, 1), "END OF TRANSMISSION BLOCK\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("END OF MEDIUM\x19newline", 2, 1), "END OF MEDIUM\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("SUBSTITUTE\x1Anewline", 2, 1), "SUBSTITUTE\nnewline");
        $this->assertEqual(mlang_string::fix_syntax("Empty\n\n\n\n\n\nlines", 2, 1), "Empty\n\n\nlines");
        $this->assertEqual(mlang_string::fix_syntax('Do not escape $variable names', 2, 1), 'Do not escape $variable names');
        $this->assertEqual(mlang_string::fix_syntax('Do not escape \$variable names', 2, 1), 'Do not escape $variable names');
        $this->assertEqual(mlang_string::fix_syntax('Do not escape $alike names', 2, 1), 'Do not escape $alike names');
        $this->assertEqual(mlang_string::fix_syntax('Do not escape \$alike names', 2, 1), 'Do not escape $alike names');
        $this->assertEqual(mlang_string::fix_syntax('Do not escape \$a names', 2, 1), 'Do not escape $a names');
        $this->assertEqual(mlang_string::fix_syntax('String $a placeholder', 2, 1), 'String {$a} placeholder');
        $this->assertEqual(mlang_string::fix_syntax('String {$a} placeholder', 2, 1), 'String {$a} placeholder');
        $this->assertEqual(mlang_string::fix_syntax('Trailing $a', 2, 1), 'Trailing {$a}');
        $this->assertEqual(mlang_string::fix_syntax('$a leading', 2, 1), '{$a} leading');
        $this->assertEqual(mlang_string::fix_syntax('$a', 2, 1), '{$a}');
        $this->assertEqual(mlang_string::fix_syntax('$a->single', 2, 1), '{$a->single}');
        $this->assertEqual(mlang_string::fix_syntax('Trailing $a->foobar', 2, 1), 'Trailing {$a->foobar}');
        $this->assertEqual(mlang_string::fix_syntax('Trailing {$a}', 2, 1), 'Trailing {$a}');
        $this->assertEqual(mlang_string::fix_syntax('Hit $a-times', 2, 1), 'Hit {$a}-times');
        $this->assertEqual(mlang_string::fix_syntax('This is $a_book', 2, 1), 'This is $a_book');
        $this->assertEqual(mlang_string::fix_syntax('Object $a->foo placeholder', 2, 1), 'Object {$a->foo} placeholder');
        $this->assertEqual(mlang_string::fix_syntax('Object {$a->foo} placeholder', 2, 1), 'Object {$a->foo} placeholder');
        $this->assertEqual(mlang_string::fix_syntax('Trailing $a->bar', 2, 1), 'Trailing {$a->bar}');
        $this->assertEqual(mlang_string::fix_syntax('Trailing {$a->bar}', 2, 1), 'Trailing {$a->bar}');
        $this->assertEqual(mlang_string::fix_syntax('Invalid $a-> placeholder', 2, 1), 'Invalid {$a}-> placeholder'); // weird but BC
        $this->assertEqual(mlang_string::fix_syntax('<strong>AMOS</strong>', 2, 1), '<strong>AMOS</strong>');
        $this->assertEqual(mlang_string::fix_syntax("'Murder!', she wrote", 2, 1), "'Murder!', she wrote"); // will be escaped by var_export()
        $this->assertEqual(mlang_string::fix_syntax("\'Murder!\', she wrote", 2, 1), "'Murder!', she wrote"); // will be escaped by var_export()
        $this->assertEqual(mlang_string::fix_syntax("\t  Trim Hunter  \t\t", 2, 1), 'Trim Hunter');
        $this->assertEqual(mlang_string::fix_syntax('Delete role "$a->role"?', 2, 1), 'Delete role "{$a->role}"?');
        $this->assertEqual(mlang_string::fix_syntax('Delete role \"$a->role\"?', 2, 1), 'Delete role "{$a->role}"?');
        $this->assertEqual(mlang_string::fix_syntax('See &#36;CFG->foo', 2, 1), 'See $CFG->foo');
    }

    /*
    public function test_get_phpfile_location() {
        $component = new mlang_component('moodle', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $this->assertEqual('lang/en_utf8/moodle.php', $component->get_phpfile_location());

        $component = new mlang_component('moodle', 'en', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertEqual('lang/en/moodle.php', $component->get_phpfile_location());

        $component = new mlang_component('workshop', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $this->assertEqual('lang/en_utf8/workshop.php', $component->get_phpfile_location());

        $component = new mlang_component('workshop', 'en', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertEqual('mod/workshop/lang/en/workshop.php', $component->get_phpfile_location());
        $this->assertEqual('lang/en/workshop.php', $component->get_phpfile_location(false));

        $component = new mlang_component('workshopform_accumulative', 'cs', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertEqual('mod/workshop/form/accumulative/lang/cs/workshopform_accumulative.php', $component->get_phpfile_location());
        $this->assertEqual('lang/cs/workshopform_accumulative.php', $component->get_phpfile_location(false));

        $component = new mlang_component('gradeexport_xml', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $this->assertEqual('lang/en_utf8/gradeexport_xml.php', $component->get_phpfile_location());

        $component = new mlang_component('gradeexport_xml', 'es', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertEqual('grade/export/xml/lang/es/gradeexport_xml.php', $component->get_phpfile_location());
        $this->assertEqual('lang/es/gradeexport_xml.php', $component->get_phpfile_location(false));
    }
     */

    public function test_get_string_keys() {
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $keys = $component->get_string_keys();
        $this->assertEqual(count($keys), 0);
        $this->assertTrue(empty($keys));

        $component->add_string(new mlang_string('hello', 'Hello'));
        $component->add_string(new mlang_string('world', 'World'));
        $keys = $component->get_string_keys();
        $keys = array_flip($keys);
        $this->assertEqual(count($keys), 2);
        $this->assertTrue(isset($keys['hello']));
        $this->assertTrue(isset($keys['world']));
    }

    public function test_intersect() {
        $master = new mlang_component('moodle', 'en', mlang_version::by_branch('MOODLE_18_STABLE'));
        $master->add_string(new mlang_string('one', 'One'));
        $master->add_string(new mlang_string('two', 'Two'));
        $master->add_string(new mlang_string('three', 'Three'));

        $slave = new mlang_component('moodle', 'cs', mlang_version::by_branch('MOODLE_18_STABLE'));
        $slave->add_string(new mlang_string('one', 'Jedna'));
        $slave->add_string(new mlang_string('two', 'Dva'));
        $slave->add_string(new mlang_string('seven', 'Sedm'));
        $slave->add_string(new mlang_string('eight', 'Osm'));

        $slave->intersect($master);
        $this->assertEqual(2, count($slave->get_string_keys()));
        $this->assertTrue($slave->has_string('one'));
        $this->assertTrue($slave->has_string('two'));
    }

    public function test_extract_script_from_text() {
        $noscript = 'This is text with no AMOS script';
        $emptyarray = mlang_tools::extract_script_from_text($noscript);
        $this->assertTrue(empty($emptyarray));

        $oneliner = 'MDL-12345 Some message AMOS   BEGIN  MOV   [a,  b],[c,d] CPY [e,f], [g ,h]  AMOS'."\t".'END BEGIN ignore AMOS   ';
        $script = mlang_tools::extract_script_from_text($oneliner);
        $this->assertIsA($script, 'array');
        $this->assertEqual(2, count($script));
        $this->assertEqual('MOV [a, b],[c,d]', $script[0]);
        $this->assertEqual('CPY [e,f], [g ,h]', $script[1]);

        $multiline = 'This is a typical usage of AMOS script in a commit message
                    AMOS BEGIN
                     MOV a,b  
                     CPY  c,d
                    AMOS END
                   Here it can continue';
        $script = mlang_tools::extract_script_from_text($multiline);
        $this->assertIsA($script, 'array');
        $this->assertEqual(2, count($script));
        $this->assertEqual('MOV a,b', $script[0]);
        $this->assertEqual('CPY c,d', $script[1]);

        // if there is no empty line between commit subject and AMOS script:
        $oneliner2 = 'Blah blah blah AMOS   BEGIN  CMD AMOS END blah blah';
        $script = mlang_tools::extract_script_from_text($oneliner2);
        $this->assertIsA($script, 'array');
        $this->assertEqual(1, count($script));
        $this->assertEqual('CMD', $script[0]);
    }

    public function test_list_languages() {
        $stage = new mlang_stage();
        $component = new mlang_component('langconfig', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $component->add_string(new mlang_string('thislanguageint', 'English'));
        $stage->add($component);
        $component->clear();

        $component = new mlang_component('langconfig', 'cs', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('thislanguageint', 'Czech'));
        $stage->add($component);
        $component->clear();

        $component = new mlang_component('langconfig', 'cs', mlang_version::by_branch('MOODLE_19_STABLE'));
        $component->add_string(new mlang_string('thislanguageint', 'CS'));
        $stage->add($component);
        $component->clear();

        $stage->commit('Registering two languages', array('source' => 'unittest'));

        $langs = mlang_tools::list_languages(true, true, false);
        $this->assertIsA($langs, 'array');
        $this->assertEqual(count($langs), 2);
        $this->assertTrue(array_key_exists('cs', $langs));
        $this->assertTrue(array_key_exists('en', $langs));
        $this->assertEqual($langs['en'], 'English');
        $this->assertEqual($langs['cs'], 'Czech');
        // todo test caching
    }

    public function test_list_components() {
        $stage = new mlang_stage();
        $component = new mlang_component('workshop', 'en', mlang_version::by_branch('MOODLE_19_STABLE'));
        $component->add_string(new mlang_string('modulename', 'Workshop'));
        $stage->add($component);
        $component->clear();

        $component = new mlang_component('auth', 'en', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component->add_string(new mlang_string('foo', 'Bar'));
        $stage->add($component);
        $component->clear();

        $component = new mlang_component('langconfig', 'cs', mlang_version::by_branch('MOODLE_19_STABLE'));
        $component->add_string(new mlang_string('thislanguage', 'CS'));
        $stage->add($component);
        $component->clear();

        $stage->commit('Registering two English components', array('source' => 'unittest'));

        $comps = mlang_tools::list_components();
        $this->assertIsA($comps, 'array');
        $this->assertEqual(count($comps), 2);
        $this->assertTrue(array_key_exists('workshop', $comps));
        $this->assertTrue(array_key_exists('auth', $comps));
        // todo test caching
    }

    public function test_execution_strings() {
        $stage = new mlang_stage();
        $version = mlang_version::by_branch('MOODLE_20_STABLE');
        // this is to prevent situation where a string is added and immediately removed in the same second. Such
        // situations are not supported yet very well in AMOS as it would require to rewrite well tuned getting
        // component from snapshot
        $past = time() - 1;
        $component = new mlang_component('auth', 'en', $version);
        $component->add_string(new mlang_string('authenticate', 'Authenticate', $past));
        $component->add_string(new mlang_string('ldap', 'Use LDAP', $past));
        $stage->add($component);
        $component->clear();

        $component = new mlang_component('auth_ldap', 'en', $version);
        $component->add_string(new mlang_string('pluginname', 'LDAP', $past));
        $stage->add($component);
        $component->clear();

        $component = new mlang_component('auth', 'cs', $version);
        $component->add_string(new mlang_string('authenticate', 'Autentizovat', $past));
        $component->add_string(new mlang_string('ldap', 'Pouzit LDAP', $past));
        $stage->add($component);
        $component->clear();
        unset($component);

        $stage->commit('Adding some testing strings', array('source' => 'unittest'));
        unset($stage);

        $stage = mlang_tools::execute('MOV [ldap,core_auth],[pluginname,auth_ldap]', $version);
        $stage->commit('Moving string ldap into auth_ldap', array('source' => 'unittest'));
        unset($stage);

        $component = mlang_component::from_snapshot('auth_ldap', 'cs', $version);
        $this->assertTrue($component->has_string('pluginname'));
        $component->clear();

        $component = mlang_component::from_snapshot('auth', 'cs', $version);
        $this->assertFalse($component->has_string('ldap'));
        $component->clear();

        $component = mlang_component::from_snapshot('auth', 'en', $version);
        $this->assertTrue($component->has_string('ldap'));  // English string are not affected by AMOS script!
        $component->clear();

        $component = mlang_component::from_snapshot('auth_ldap', 'en', $version);
        $string = $component->get_string('pluginname');
        $this->assertEqual($string->text, 'LDAP');
        unset($string);
        $component->clear();
    }

    public function test_execution_strings_move() {
        $stage = new mlang_stage();
        $version = mlang_version::by_branch('MOODLE_20_STABLE');
        $now = time();

        // this block emulates parse-core.php
        $component = new mlang_component('admin', 'en', $version);
        $component->add_string(new mlang_string('configsitepolicy', 'OLD', $now - 2));
        $stage->add($component);
        $stage->rebase($now - 2, true, $now - 2);
        $stage->commit('Committed initial English string', array('source' => 'unittest'), true, $now - 2);
        $component->clear();
        unset($component);

        // this block emulates parse-lang.php
        $component = new mlang_component('admin', 'cs', $version);
        $component->add_string(new mlang_string('configsitepolicy', 'OLD in cs', $now - 1));
        $stage->add($component);
        $stage->rebase();
        $stage->commit('Committed initial Czech translation', array('source' => 'unittest'), true, $now - 1);
        $component->clear();
        unset($component);

        // this block emulates parse-core.php again later
        // now the string is moved in the English pack by the developer who provides AMOS script in commit message
        // this happened in b593d49d593ee778f525b4074f5ee7978c5e2960
        $component = new mlang_component('admin', 'en', $version);
        $component->add_string(new mlang_string('sitepolicy_help', 'NEW', $now));
        $component->add_string(new mlang_string('configsitepolicy', 'OLD', $now, true));
        $commitmsg = 'MDL-24570 multiple sitepolicy fixes + adding new separate guest user policy
AMOS BEGIN
 MOV [configsitepolicy,core_admin],[sitepolicy_help,core_admin]
AMOS END';
        $stage->add($component);
        $stage->rebase($now, true, $now);
        $stage->commit($commitmsg, array('source' => 'unittest'), true, $now);
        $component->clear();
        unset($component);

        // execute AMOS script if the commit message contains some
        if ($version->code >= mlang_version::MOODLE_20) {
            $instructions = mlang_tools::extract_script_from_text($commitmsg);
            if (!empty($instructions)) {
                foreach ($instructions as $instruction) {
                    $changes = mlang_tools::execute($instruction, $version, $now);
                    $changes->rebase($now);
                    $changes->commit($commitmsg, array('source' => 'commitscript'), true, $now);
                    unset($changes);
                }
            }
        }

        // check the results
        $component = mlang_component::from_snapshot('admin', 'cs', $version, $now);
        $this->assertTrue($component->has_string('sitepolicy_help'));
        $this->assertEqual('OLD in cs', $component->get_string('sitepolicy_help')->text);
        $this->assertEqual(1, $component->get_number_of_strings());
    }

    public function test_legacy_component_name() {
        $this->assertEqual(testable_mlang_tools::legacy_component_name('core'), 'moodle');
        $this->assertEqual(testable_mlang_tools::legacy_component_name('core_grades'), 'grades');
        $this->assertEqual(testable_mlang_tools::legacy_component_name('block_foobar'), 'block_foobar');
        $this->assertEqual(testable_mlang_tools::legacy_component_name('mod_foobar'), 'foobar');
        $this->assertEqual(testable_mlang_tools::legacy_component_name('moodle'), 'moodle');
        $this->assertEqual(testable_mlang_tools::legacy_component_name('admin'), 'admin');
        $this->assertEqual(testable_mlang_tools::legacy_component_name(' mod_whitespace  '), 'whitespace');
        $this->assertEqual(testable_mlang_tools::legacy_component_name('[syntaxerr'), false);
        $this->assertEqual(testable_mlang_tools::legacy_component_name('syntaxerr,'), false);
        $this->assertEqual(testable_mlang_tools::legacy_component_name('syntax err'), false);
    }

    public function test_component_get_recent_timemodified() {
        $now = time();
        $component = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $this->assertEqual(0, $component->get_recent_timemodified());
        $component->add_string(new mlang_string('first', 'Hello', $now - 5));
        $component->add_string(new mlang_string('second', 'Moodle', $now - 12));
        $component->add_string(new mlang_string('third', 'World', $now - 4));
        $this->assertEqual($now - 4, $component->get_recent_timemodified());
    }

    public function test_merge_strings_from_another_component() {
        // prepare two components with some strings
        $component19 = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_19_STABLE'));
        $component19->add_string(new mlang_string('first', 'First $a'));
        $component19->add_string(new mlang_string('second', 'Second \"string\"'));
        $component19->add_string(new mlang_string('third', 'Third'));
        $component19->add_string(new mlang_string('fifth', 'Fifth \"string\"'));

        $component20 = new mlang_component('test', 'xx', mlang_version::by_branch('MOODLE_20_STABLE'));
        $component20->add_string(new mlang_string('second', '*deleted*', null, true));
        $component20->add_string(new mlang_string('third', 'Third already merged'));
        $component20->add_string(new mlang_string('fourth', 'Fourth only in component20'));
        // merge component19 into component20
        mlang_tools::merge($component19, $component20);
        // check the results
        $this->assertEqual(4, $component19->get_number_of_strings());
        $this->assertEqual(5, $component20->get_number_of_strings());
        $this->assertEqual('First {$a}', $component20->get_string('first')->text);
        $this->assertEqual('*deleted*', $component20->get_string('second')->text);
        $this->assertTrue($component20->get_string('second')->deleted);
        $this->assertEqual('Third already merged', $component20->get_string('third')->text);
        $this->assertEqual('Fourth only in component20', $component20->get_string('fourth')->text);
        $this->assertFalse($component19->has_string('fourth'));
        $this->assertEqual('Fifth "string"', $component20->get_string('fifth')->text);
        // clear source component and make sure that strings are still in the new one
        $component19->clear();
        unset($component19);
        $this->assertEqual(5, $component20->get_number_of_strings());
        $this->assertEqual('First {$a}', $component20->get_string('first')->text);
    }

    public function test_get_affected_strings() {
        $diff = file(dirname(__FILE__) . '/parserdata002.txt');
        $affected = mlang_tools::get_affected_strings($diff);
        $this->assertEqual(count($affected), 5);
        $this->assertTrue(in_array('configdefaultuserroleid', $affected));
        $this->assertTrue(in_array('confignodefaultuserrolelists', $affected));
        $this->assertTrue(in_array('nodefaultuserrolelists', $affected));
        $this->assertTrue(in_array('nolangupdateneeded', $affected));
        $this->assertTrue(in_array('mod/something:really_nasty-like0098187.this', $affected));
    }

    public function test_components_tree() {
        // prepare a tree
        $stage = new mlang_stage();
        $components = array();
        foreach (array('MOODLE_19_STABLE', 'MOODLE_20_STABLE', 'MOODLE_21_STABLE') as $branch) {
            foreach (array('en', 'cs', 'es') as $language) {
                foreach (array('moodle', 'auth_mnet', 'workshop') as $componentname) {
                    $component = new mlang_component($componentname, $language, mlang_version::by_branch($branch));
                    $component->add_string(new mlang_string('foo', 'Bar'));
                    $stage->add($component);
                    $component->clear();
                }
            }
        }
        $stage->commit('Committing test strings');

        // full tree
        $tree = mlang_tools::components_tree();
        $this->assertIsA($tree, 'array');
        $this->assertEqual(3, count($tree)); // 3 versions
        $this->assertEqual(3, count($tree[1900])); // 3 languages
        $this->assertEqual(3, count($tree[2000])); // 3 languages
        $this->assertEqual(3, count($tree[2100])); // 3 languages
        $this->assertEqual(3, count($tree[1900]['cs'])); // 3 components
        $this->assertEqual(3, count($tree[2000]['en'])); // 3 components
        $this->assertEqual(3, count($tree[2100]['es'])); // 3 components
        $this->assertEqual(1, $tree[2100]['es']['moodle']); // 1 string

        // empty trees
        $tree = mlang_tools::components_tree(array('branch' => 1800));
        $this->assertIsA($tree, 'array');
        $this->assertTrue(empty($tree));

        $tree = mlang_tools::components_tree(array('component' => 'book'));
        $this->assertIsA($tree, 'array');
        $this->assertTrue(empty($tree));

        // single value filter
        $tree = mlang_tools::components_tree(array('branch' => 2000, 'lang' => 'cs'));
        $this->assertIsA($tree, 'array');
        $this->assertEqual(1, count($tree));
        $this->assertEqual(1, count($tree[2000]));
        $this->assertEqual(3, count($tree[2000]['cs']));
        $this->assertEqual(1, $tree[2000]['cs']['workshop']);

        // yet another single value filter
        $tree = mlang_tools::components_tree(array('component' => 'auth_mnet'));
        $this->assertEqual(3, count($tree));
        $this->assertEqual(3, count($tree[1900]));
        $this->assertEqual(3, count($tree[2000]));
        $this->assertEqual(3, count($tree[2100]));
        $this->assertEqual(1, count($tree[1900]['cs']));
        $this->assertEqual(1, count($tree[2000]['en']));
        $this->assertEqual(1, count($tree[2100]['es']));
        $this->assertEqual(1, $tree[2100]['en']['auth_mnet']);

        // multivalues filter
        $tree = mlang_tools::components_tree(array('branch' => array(2000, 2100), 'lang' => array('en'), 'component' => array('moodle', 'workshop')));
        $this->assertEqual(2, count($tree));
        $this->assertEqual(1, count($tree[2000]));
        $this->assertEqual(1, count($tree[2100]));
        $this->assertEqual(2, count($tree[2000]['en']));
        $this->assertEqual(2, count($tree[2100]['en']));
        $this->assertEqual(1, $tree[2000]['en']['moodle']);
        $this->assertEqual(1, $tree[2000]['en']['workshop']);
        $this->assertEqual(1, $tree[2100]['en']['moodle']);
        $this->assertEqual(1, $tree[2100]['en']['workshop']);
    }

    public function test_stage_propagate() {

        $version20 = mlang_version::by_branch('MOODLE_20_STABLE');
        $version21 = mlang_version::by_branch('MOODLE_21_STABLE');
        $version22 = mlang_version::by_branch('MOODLE_22_STABLE');

        $component20en = new mlang_component('admin', 'en', $version20);
        $component20en->add_string(new mlang_string('foo1', 'Bar1'));
        $component20en->add_string(new mlang_string('foo2', 'Bar2'));

        $component20cs = new mlang_component('admin', 'cs', $version20);
        $component20cs->add_string(new mlang_string('foo1', 'TranslatedOldBar1'));

        $component21en = new mlang_component('admin', 'en', $version21);
        $component21en->add_string(new mlang_string('foo1', 'Bar1'));
        $component21en->add_string(new mlang_string('foo2', 'Bar2'));
        $component21en->add_string(new mlang_string('foo3', 'Bar3'));

        $component22en = new mlang_component('admin', 'en', $version22);
        $component22en->add_string(new mlang_string('foo1', 'Bar1'));
        $component22en->add_string(new mlang_string('foo2', 'Bar2'));
        $component22en->add_string(new mlang_string('foo3', 'NewBar3'));

        $stage = new mlang_stage();
        $stage->add($component20en);
        $stage->add($component20cs);
        $stage->add($component21en);
        $stage->add($component22en);
        $stage->commit('Initial strings', array('source' => 'unittest'), true);
        $component20en->clear();
        $component21en->clear();
        $component22en->clear();
        unset($stage);

        // simple usage - the user translated a string on 2.1 and want it being applied to 2.2, too
        $stage = new mlang_stage();
        $component21cs = new mlang_component('admin', 'cs', $version21);
        $component21cs->add_string(new mlang_string('foo1', 'TranslatedBar1'));
        $stage->add($component21cs);
        $component21cs->clear();

        $this->assertEqual($stage->propagate(array($version22)), 1);

        $propagatedcomponent = $stage->get_component('admin', 'cs', $version22);
        $this->assertNotNull($propagatedcomponent, 'The component "admin" must exist on '.$version22->label);
        $this->assertTrue($propagatedcomponent->has_string('foo1'));
        $propagatedstring = $propagatedcomponent->get_string('foo1');
        $this->assertTrue($propagatedstring->text, 'TranslatedBar1');
        $stage->clear();

        // the change is not propagated if the changed string is staged several times and the values
        // are different
        $stage = new mlang_stage();
        $component20cs = new mlang_component('admin', 'cs', $version20);
        $component20cs->add_string(new mlang_string('foo2', 'TranslatedOldBar2'));
        $component21cs = new mlang_component('admin', 'cs', $version21);
        $component21cs->add_string(new mlang_string('foo2', 'TranslatedBar2'));
        $stage->add($component20cs);
        $stage->add($component21cs);
        $component20cs->clear();
        $component21cs->clear();

        $this->assertEqual($stage->propagate(array($version20, $version21, $version22)), 0);

        $this->assertEqual($stage->get_component('admin', 'cs', $version20)->get_string('foo2')->text, 'TranslatedOldBar2');
        $this->assertEqual($stage->get_component('admin', 'cs', $version21)->get_string('foo2')->text, 'TranslatedBar2');
        $this->assertNull($stage->get_component('admin', 'cs', $version22));
        $stage->clear();

        // but the change is propagated if the changed string is staged several times and the values
        // are the same
        $stage = new mlang_stage();
        $component20cs = new mlang_component('admin', 'cs', $version20);
        $component20cs->add_string(new mlang_string('foo2', 'TranslatedBar2'));
        $component21cs = new mlang_component('admin', 'cs', $version21);
        $component21cs->add_string(new mlang_string('foo2', 'TranslatedBar2'));
        $stage->add($component20cs);
        $stage->add($component21cs);
        $component20cs->clear();
        $component21cs->clear();

        $this->assertEqual($stage->propagate(array($version20, $version21, $version22)), 1);

        $this->assertEqual($stage->get_component('admin', 'cs', $version22)->get_string('foo2')->text, 'TranslatedBar2');
        $this->assertEqual($stage->get_component('admin', 'cs', $version21)->get_string('foo2')->text, 'TranslatedBar2');
        $this->assertEqual($stage->get_component('admin', 'cs', $version20)->get_string('foo2')->text, 'TranslatedBar2');
        $stage->clear();

        // the staged string is propagated to another branch only if the English originals match
        // in the following test, the 2.1 translation of foo3 should not propagate to neither 2.0
        // (because the string does not exist there) not 2.2 (because the English originals differ)
        $stage = new mlang_stage();
        $component21cs = new mlang_component('admin', 'cs', $version21);
        $component21cs->add_string(new mlang_string('foo3', 'TranslatedBar3'));
        $stage->add($component21cs);
        $component21cs->clear();

        $this->assertEqual($stage->propagate(array($version20, $version21, $version22)), 0);

        $this->assertNull($stage->get_component('admin', 'cs', $version20));
        $this->assertNull($stage->get_component('admin', 'cs', $version22));
        $stage->clear();
    }

    public function test_stash_push() {

    }
}
