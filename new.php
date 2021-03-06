<?php
// This file is part of the BadgeCerts plugin for Moodle - http://moodle.org/
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
 * First step page for creating a new badge certificate
 *
 * @package    local_badgecerts
 * @copyright  2014 onwards Gregor Anželj
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Gregor Anželj <gregor.anzelj@gmail.com>
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/local/badgecerts/lib.php');
require_once(dirname(__FILE__) . '/edit_form.php');

$type = required_param('type', PARAM_INT);
$courseid = optional_param('id', 0, PARAM_INT);

require_login();

if (empty($CFG->enablebadges)) {
    print_error('badgesdisabled', 'local_badgecerts');
}

if (empty($CFG->badges_allowcoursebadges) && ($type == CERT_TYPE_COURSE)) {
    print_error('coursebadgesdisabled', 'local_badgecerts');
}

$title = get_string('createcertificate', 'local_badgecerts');

if (($type == CERT_TYPE_COURSE) && ($course = $DB->get_record('course', array('id' => $courseid)))) {
    require_login($course);
    $coursecontext = context_course::instance($course->id);
    $PAGE->set_context($coursecontext);
    $PAGE->set_pagelayout('course');
    $PAGE->set_url('/local/badgecerts/new.php', array('type' => $type, 'id' => $course->id));
    $heading = format_string($course->fullname, true, array('context' => $coursecontext)) . ": " . $title;
    $PAGE->set_heading($heading);
    $PAGE->set_title($heading);
} else {
    $PAGE->set_context(context_system::instance());
    $PAGE->set_pagelayout('admin');
    $PAGE->set_url('/local/badgecerts/new.php', array('type' => $type));
    $PAGE->set_heading($title);
    $PAGE->set_title($title);
}

require_capability('moodle/badges:createcertificate', $PAGE->context);

$fordb = new stdClass();
$fordb->id = null;

$form = new edit_cert_details_form($PAGE->url, array('action' => 'new'));

if ($form->is_cancelled()) {
    redirect(new moodle_url('/local/badgecerts/index.php', array('type' => $type, 'id' => $courseid)));
} else if ($data = $form->get_data()) {
    // Creating new badge certificate here.
    $now = time();

    $getfilename = $form->get_new_filename('certbgimage');
    $fordb->name = $data->name;
    $fordb->description = $data->description;
    $fordb->official = isset($data->official) ? 1 : 0;
    $fordb->timecreated = $now;
    $fordb->timemodified = $now;
    $fordb->usercreated = $USER->id;
    $fordb->usermodified = $USER->id;
    $fordb->issuername = $data->issuername;
    $fordb->issuercontact = $data->issuercontact;
    $fordb->format = $data->format;
    $fordb->orientation = $data->orientation;
    $fordb->unit = $data->unit;
    $fordb->bookingid = $data->bookingid;
    $fordb->type = $type;
    $fordb->courseid = ($type == CERT_TYPE_COURSE) ? $courseid : null;
    $fordb->status = CERT_STATUS_INACTIVE;

    $newid = $DB->insert_record('badge_certificate', $fordb, true);

    if ($getfilename) {
        // Create folder if it doesn't exist.
        $dirname = $CFG->dataroot.'/filedir/cert';
        if (!file_exists($dirname) and !is_dir($dirname)) {
            mkdir($dirname);         
        }
        $filename = $dirname . '/' . $newid . '_' . $getfilename;
        // Save file to standard filesystem.
        $form->save_file('certbgimage', $filename, true);
        // Update record in the database.
        $DB->set_field('badge_certificate', 'certbgimage', $filename, array('id' => $newid));
    }

    $newcert = new badge_certificate($newid);
    $form->set_data($newcert);

    // If a user can configure badge certificate elements, they will be redirected to the elements page.
    /*
    if (has_capability('moodle/badges:configureelements', $PAGE->context)) {
        redirect(new moodle_url('/local/badgecerts/elements.php', array('id' => $newid)));
    }
    */
    redirect(new moodle_url('/local/badgecerts/overview.php', array('id' => $newid)));
}

echo $OUTPUT->header();
echo $OUTPUT->box('', 'notifyproblem hide', 'check_connection');

$form->display();

echo $OUTPUT->footer();