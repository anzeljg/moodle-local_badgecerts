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
 * Contains classes, functions and constants used in 'local_badgecerts' plugin.
 *
 * @package    local_badgecerts
 * @copyright  2014 onwards Gregor Anželj
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Gregor Anželj <gregor.anzelj@gmail.com>
 */
defined('MOODLE_INTERNAL') || die();

/*
 * Number of records per page.
 */
define('CERT_PERPAGE', 50);

/*
 * Inactive badge certificate means that this badge certificate cannot be used and
 * has not been used yet. Its elements can be changed.
 */
define('CERT_STATUS_INACTIVE', 0);

/*
 * Active badge certificate means that this badge certificate can be used, but it
 * has not been used yet. Can be deactivated for the purpose of changing its elements.
 */
define('CERT_STATUS_ACTIVE', 1);

/*
 * Inactive badge certificate can no longer be used, but it has been used in the past
 * and therefore its elements cannot be changed.
 */
define('CERT_STATUS_INACTIVE_LOCKED', 2);

/*
 * Active badge certificate means that it can be used and has already been used by
 * users. Its elements cannot be changed any more.
 */
define('CERT_STATUS_ACTIVE_LOCKED', 3);

/*
 * Badge certificate type for site badge certificates.
 */
define('CERT_TYPE_SITE', 1);

/*
 * Badge certificate type for course badge certificates.
 */
define('CERT_TYPE_COURSE', 2);

/**
 * Class that represents badge certificate.
 *
 */
class badge_certificate {

    /** @var int Certificate id */
    public $id;

    /** Values from the table 'badge_certificate' */
    public $name;
    public $description;
    public $certbgimage;
    public $bookingid;
    public $official;
    public $timecreated;
    public $timemodified;
    public $usercreated;
    public $usermodified;
    public $issuername;
    public $issuercontact;
    public $format;
    public $orientation;
    public $unit;
    public $type;
    public $courseid;
    public $status = 0;
    public $nextcron;

    /** @var array Badge certificate elements */
    public $elements = array();

    /**
     * Constructs with badge certificate details.
     *
     * @param int $certid badge certificate ID.
     */
    public function __construct($certid) {
        global $DB;
        $this->id = $certid;

        $data = $DB->get_record('badge_certificate', array('id' => $certid));

        if (empty($data)) {
            print_error('error:nosuchbadgecertificate', 'badges', $certid);
        }

        foreach ((array) $data as $field => $value) {
            if (property_exists($this, $field)) {
                $this->{$field} = $value;
            }
        }
    }

    /**
     * Use to get context instance of a badge certificate.
     * @return context instance.
     */
    public function get_context() {
        if ($this->type == CERT_TYPE_SITE) {
            return context_system::instance();
        } else if ($this->type == CERT_TYPE_COURSE) {
            return context_course::instance($this->courseid);
        } else {
            debugging('Something is wrong...');
        }
    }

    /**
     * Save/update badge certificate information in 'badge_certificate'
     * table only. Cannot be used for updating badge certificate elements.
     *
     * @return bool Returns true on success.
     */
    public function save() {
        global $DB;

        $fordb = new stdClass();
        foreach (get_object_vars($this) as $k => $v) {
            $fordb->{$k} = $v;
        }
        unset($fordb->elements);

        $fordb->timemodified = time();
        if ($DB->update_record_raw('badge_certificate', $fordb)) {
            return true;
        } else {
            throw new moodle_exception('error:save', 'badges');
            return false;
        }
    }

    /**
     * Checks if badge certificate is active.
     * Used in badge certificate.
     *
     * @return bool A status indicating badge certificate is active
     */
    public function is_active() {
        if (($this->status == CERT_STATUS_ACTIVE) ||
                ($this->status == CERT_STATUS_ACTIVE_LOCKED)) {
            return true;
        }
        return false;
    }

    /**
     * Use to get the name of badge certificate status.
     *
     */
    public function get_status_name() {
        return get_string('badgecertificatestatus_' . $this->status, 'local_badgecerts');
    }

    /**
     * Use to set badge certificate status.
     * Only active badge certificates can be used.
     *
     * @param int $status Status from CERT_STATUS constants
     */
    public function set_status($status = 0) {
        $this->status = $status;
        $this->save();
    }

    /**
     * Checks if badge certificate is locked.
     * Used in badge certificate editing.
     *
     * @return bool A status indicating badge certificate is locked
     */
    public function is_locked() {
        if (($this->status == CERT_STATUS_ACTIVE_LOCKED) ||
                ($this->status == CERT_STATUS_INACTIVE_LOCKED)) {
            return true;
        }
        return false;
    }

    /**
     * Fully deletes the badge certificate.
     */
    public function delete() {
        global $DB;

        $fs = get_file_storage();

        // Delete badge certificate images.
        $certcontext = $this->get_context();
        $fs->delete_area_files($certcontext->id, 'certificates', 'certbgimage', $this->id);

        // Detach badge certificate from badge(s).
        $ids = $DB->get_fieldset_sql("SELECT id FROM {badge} WHERE certid = :certid", array('certid' => $this->id));
        foreach ($ids as $id) {
            $record = new StdClass();
            $record->id = $id;
            $record->certid = null;
            $DB->update_record('badge', $record);
        }

        // Finally, remove badge certificate itself.
        $DB->delete_records('badge_certificate', array('id' => $this->id));
    }

    /**
     * Generates badge certificate preview in PDF format.
     */
    public function preview_badge_certificate() {
        global $CFG, $DB;
        require_once($CFG->libdir . '/tcpdf/tcpdf.php');

        $pdf = new TCPDF($this->orientation, $this->unit, $this->format, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        // remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        // set default monospaced font
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        // set margins
        //$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        // override default margins
        $pdf->SetMargins(0, 0, 0, true);
        // set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        // set image scale factor
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        // set default font subsetting mode
        $pdf->setFontSubsetting(true);

        // Add badge certificate background image
        if ($this->certbgimage) {
            // Add a page
            // This method has several options, check the source code documentation for more information.
            $pdf->AddPage();

            // get the current page break margin
            $break_margin = $pdf->getBreakMargin();
            // get current auto-page-break mode
            $auto_page_break = $pdf->getAutoPageBreak();
            // disable auto-page-break
            $pdf->SetAutoPageBreak(false, 0);

            $template = file_get_contents($this->certbgimage);
            // Replace all placeholder tags
            $now = time();
            $placeholders = array(
                '[[recipient-fname]]', // Adds the recipient's first name
                '[[recipient-lname]]', // Adds the recipient's last name
                '[[recipient-flname]]', // Adds the recipient's full name (first, last)
                '[[recipient-lfname]]', // Adds the recipient's full name (last, first)
                '[[recipient-email]]', // Adds the recipient's email address
                '[[issuer-name]]', // Adds the issuer's name or title
                '[[issuer-contact]]', // Adds the issuer's contact information
                '[[badge-name]]', // Adds the badge's name or title
                '[[badge-desc]]', // Adds the badge's description
                '[[badge-number]]', // Adds the badge's ID number
                '[[badge-course]]', // Adds the name of the course where badge was awarded
                '[[badge-hash]]', // Adds the badge hash value
                '[[datetime-Y]]', // Adds the year
                '[[datetime-d.m.Y]]', // Adds the date in dd.mm.yyyy format
                '[[datetime-d/m/Y]]', // Adds the date in dd/mm/yyyy format
                '[[datetime-F]]', // Adds the date (used in DB datestamps)
                '[[datetime-s]]', // Adds Unix Epoch Time timestamp';
                '[[booking-title]]', // Adds the seminar title
                '[[booking-startdate]]', // Adds the seminar start date
                '[[booking-enddate]]', // Adds the seminar end date
                '[[booking-duration]]', // Adds the seminar duration
                '[[recipient-birthdate]]', // Adds the recipient's date of birth
                '[[recipient-institution]]', // Adds the institution where the recipient is employed
                '[[badge-date-issued]]', // Adds the date when badge was issued
            );
            $values = array(
                get_string('preview:recipientfname', 'local_badgecerts'),
                get_string('preview:recipientlname', 'local_badgecerts'),
                get_string('preview:recipientflname', 'local_badgecerts'),
                get_string('preview:recipientlfname', 'local_badgecerts'),
                get_string('preview:recipientemail', 'local_badgecerts'),
                get_string('preview:issuername', 'local_badgecerts'),
                get_string('preview:issuercontact', 'local_badgecerts'),
                get_string('preview:badgename', 'local_badgecerts'),
                get_string('preview:badgedesc', 'local_badgecerts'),
                $this->id,
                get_string('preview:badgecourse', 'local_badgecerts'),
                sha1(rand() . $this->usercreated . $this->id . $now),
                strftime('%Y', $now),
                userdate($now, get_string('datetimeformat', 'local_badgecerts')),
                userdate($now, get_string('datetimeformat', 'local_badgecerts')),
                strftime('%F', $now),
                strftime('%s', $now),
                get_string('preview:seminartitle', 'local_badgecerts'),
                userdate(strtotime('- 2 month', $now), get_string('datetimeformat', 'local_badgecerts')),
                userdate(strtotime('- 1 month', $now), get_string('datetimeformat', 'local_badgecerts')),
                get_string('preview:seminarduration', 'local_badgecerts'),
                userdate(strtotime('1 January 1970'), get_string('datetimeformat', 'local_badgecerts')),
                get_string('preview:recipientinstitution', 'local_badgecerts'),
                userdate($now, get_string('datetimeformat', 'local_badgecerts')),
            );
            $template = str_replace($placeholders, $values, $template);

            $pdf->ImageSVG($file = '@' . $template, 0, 0, 0, 0, '', '', '', 0, true);

            // restore auto-page-break status
            $pdf->SetAutoPageBreak($auto_page_break, $break_margin);
            // set the starting point for the page content
            $pdf->setPageMark();
        }

        // Close and output PDF document
        // This method has several options, check the source code documentation for more information.
        $pdf->Output('preview_' . $this->name . '.pdf', 'I');
    }

}

/**
 * Get all badge certificates.
 *
 * @param int Type of badges to return
 * @param int Course ID for course badges
 * @param string $sort An SQL field to sort by
 * @param string $dir The sort direction ASC|DESC
 * @param int $page The page or records to return
 * @param int $perpage The number of records to return per page
 * @param int $user User specific search
 * @return array $badge Array of records matching criteria
 */
function badges_get_certificates($type, $courseid = 0, $sort = '', $dir = '', $page = 0, $perpage = CERT_PERPAGE,
        $user = 0) {
    global $DB;
    $records = array();
    $params = array();
    $where = "bc.type = :type AND bc.usercreated = :user";
    $params['type'] = $type;
    $params['user'] = $user;

    $userfields = array('bc.id, bc.name, bc.status');
    $usersql = "";
    $fields = implode(', ', $userfields);

    if ($courseid != 0) {
        $where .= "AND bc.courseid = :courseid ";
        $params['courseid'] = $courseid;
    }

    $sorting = (($sort != '' && $dir != '') ? 'ORDER BY ' . $sort . ' ' . $dir : '');

    $sql = "SELECT $fields FROM {badge_certificate} bc $usersql WHERE $where $sorting";
    $records = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

    $certs = array();
    foreach ($records as $r) {
        $cert = new badge_certificate($r->id);
        $certs[$r->id] = $cert;
        $certs[$r->id]->statstring = $cert->get_status_name();
    }
    return $certs;
}

/**
 * Get all badge certificates for courseid.
 *
 * @param int Course ID for course badges 
 * @return array $badge Array of records matching criteria
 */
function badges_get_certificates_for_courseid($courseid = 0) {
    global $DB;
    $records = array();
    $params = array();
    $where = "bc.courseid = :courseid";
    $params['courseid'] = $courseid;

    $userfields = array('bc.id, bc.name, bc.status');
    $usersql = "";
    $fields = implode(', ', $userfields);

    $sql = "SELECT $fields FROM {badge_certificate} bc $usersql WHERE $where";
    $records = $DB->get_records_sql($sql, $params);

    $certs = array();
    foreach ($records as $r) {
        $cert = new badge_certificate($r->id);
        $certs[$r->id] = $cert;
        $certs[$r->id]->statstring = $cert->get_status_name();
    }
    return $certs;
}

/**
 * Returns array of available badges that badge certificates
 * can be assigned to.
 *
 * @param int $courseid course ID
 * @return array Array containing all the badges
 */
function get_available_badge_options($courseid) {
    global $DB;
    $records = array();
    $params = array();
    $where = ' b.courseid = :courseid AND (b.status = 1 OR b.status = 3) AND b.certid IS NULL ';
    $params['courseid'] = $courseid;

    $userfields = array('b.id, b.name');
    $usersql = '';
    $fields = implode(', ', $userfields);

    $sorting = 'ORDER BY b.name ASC ';

    $sql = "SELECT $fields FROM {badge} b $usersql WHERE $where $sorting";
    $records = $DB->get_records_sql($sql, $params);

    $options = array();
    foreach ($records as $record) {
        $options[$record->id] = $record->name;
    }

    return $options;
}

/**
 * Returns array of assigned badges that badge certificates
 * are already assigned to.
 *
 * @param int $courseid course ID
 * @return array Array containing all the assigned badges
 */
function get_assigned_badge_options($courseid, $certid) {
    global $DB;
    $records = array();
    $params = array();
    $where = ' b.courseid = :courseid AND (b.status = 1 OR b.status = 3) AND b.certid = :certid ';
    $params['courseid'] = $courseid;
    $params['certid'] = $certid;

    $userfields = array('b.id, b.name');
    $usersql = '';
    $fields = implode(', ', $userfields);

    $sorting = 'ORDER BY b.name ASC ';

    $sql = "SELECT $fields FROM {badge} b $usersql WHERE $where $sorting";
    $records = $DB->get_records_sql($sql, $params);

    $options = array();
    foreach ($records as $record) {
        $options[$record->id] = $record->name;
    }

    return $options;
}

/**
 * Get badge certificates for a specific user.
 *
 * @param int $userid User ID
 * @param int $courseid Badge certs earned by a user in a specific course
 * @param int $page The page or records to return
 * @param int $perpage The number of records to return per page
 * @param string $search A simple string to search for
 * @param bool $onlypublic Return only public badges
 * @return array of certs ordered by decreasing date of issue
 */
function badges_get_user_certificates($userid, $courseid = 0, $page = 0, $perpage = 0, $search = '', $onlypublic = false) {
    global $DB;
    $certs = array();

    $params[] = $userid;
    $sql = 'SELECT
                bi.uniquehash,
                bi.dateissued,
                bi.dateexpire,
                bi.id as issuedid,
                bi.visible,
                u.email,
                b.*,
                bc.status as certstatus
            FROM
                {badge} b,
                {badge_issued} bi,
                {user} u,
                {badge_certificate} bc
            WHERE b.id = bi.badgeid
                AND u.id = bi.userid
                AND bi.userid = ?
                AND b.certid IS NOT NULL
                AND bc.id = b.certid
                AND bc.status >= 1';

    if (!empty($search)) {
        $sql .= ' AND (' . $DB->sql_like('b.name', '?', false) . ') ';
        $params[] = "%$search%";
    }
    if ($onlypublic) {
        $sql .= ' AND (bi.visible = 1) ';
    }

    if ($courseid != 0) {
        $sql .= ' AND (b.courseid = ' . $courseid . ') ';
    }
    $sql .= ' ORDER BY bi.dateissued DESC';
    $certs = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

    return $certs;
}

/**
 *  Check if user has the privileges to bulk generate badge certificates
 */
function user_can_bulk_generate_certificates_in_course($currentcourseid) {
    global $USER, $DB;

    $fieldid = $DB->get_field('user_info_field', 'id', array('shortname' => 'bulkGenCerts'));
    $courseid = $DB->get_field('user_info_data', 'data', array('userid' => $USER->id, 'fieldid' => $fieldid));
    if ($courseid == $currentcourseid) {
        // User has the right to bulk generate badge certificates
        return true;
    } else {
        return false;
    }
}

/**
 * Get bookingoptionid - from booking module
 */
function booking_getbookingoptionid($bookingid = NULL, $userid = NULL) {
    global $DB;

    if (is_null($userid) || is_null($bookingid)) {
        return FALSE;
    }

    $ba = $DB->get_record('booking_answers', array('completed' => '1', 'userid' => $userid, 'bookingid' => $bookingid));

    if ($ba === FALSE) {
        return 0;
    } else {
        return $ba->optionid;
    }
}

/**
 * Get bookingoptions - from booking module
 */
function booking_getbookingoptions($cmid = NULL, $optionid = NULL) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/booking/locallib.php');
    
    if (is_null($optionid)) {
        return FALSE;
    }

    $booking = new booking_option($cmid, $optionid);
    $booking->apply_tags();

    if (empty($booking)) {
        return FALSE;
    } else {
        return array('text' => $booking->option->text, 'coursestarttime' => $booking->option->coursestarttime, 'courseendtime' => $booking->option->courseendtime, 'duration' => $booking->booking->duration);
    }
}


/**
 * Get all certificates for courseid - for API!
 */
function get_all_certificates($courseid = NULL) {
    global $DB;
    if (is_null($courseid)) {
        return FALSE;
    }

    $allCertificates = badges_get_certificates_for_courseid($courseid);

    $bulkCerts = array();

    foreach ($allCertificates as $certificate) {
        $badgeid = $DB->get_field('badge', '*', array('certid' => $certificate->id));
        $sql = "SELECT bi.userid, bi.uniquehash AS hash
                FROM {badge_issued} bi
                WHERE bi.badgeid = :badgeid";
        $badges = $DB->get_records_sql($sql, array('badgeid' => $badgeid));

        $cert = new badge_certificate($certificate->id);

        foreach ($badges as $badge) {
            $assertion = new core_badges_assertion($badge->hash);
            $cert->issued = $assertion->get_badge_assertion();
            $cert->badgeclass = $assertion->get_badge_class();
            // Get a recipient from database.
            $namefields = get_all_user_name_fields(true, 'u');
            $user = $DB->get_record_sql("SELECT u.id, $namefields, u.deleted,
                                                    u.email AS accountemail, b.email AS backpackemail
                            FROM {user} u LEFT JOIN {badge_backpack} b ON u.id = b.userid
                            WHERE u.id = :userid", array('userid' => $badge->userid));
            // Add custom profile field 'Datumrojstva' value
            $fieldid = $DB->get_field('user_info_field', 'id', array('shortname' => 'Datumrojstva'));
            if ($fieldid && $birthdate = $DB->get_field('user_info_data', 'data',
                    array('userid' => $badge->userid, 'fieldid' => $fieldid))) {
                $user->birthdate = $birthdate;
            } else {
                $user->birthdate = null;
            }
            // Add custom profile field 'VIZ' value
            $fieldid = $DB->get_field('user_info_field', 'id', array('shortname' => 'VIZ'));
            if ($fieldid && $institution = $DB->get_field('user_info_data', 'data',
                    array('userid' => $badge->userid, 'fieldid' => $fieldid))) {
                $user->institution = $institution;
            } else {
                $user->institution = null;
            }
            $cert->recipient = $user;

            $booking = new StdClass();
            $booking->title = get_string('titlenotset', 'local_badgecerts');
            $booking->startdate = get_string('datenotdefined', 'local_badgecerts');
            $booking->enddate = get_string('datenotdefined', 'local_badgecerts');
            $booking->duration = 0;

            if ($cert->bookingid > 0) {
                $optionid = booking_getbookingoptionid($cert->bookingid, $badge->userid);
                if (isset($optionid) && $optionid > 0) {
                    $coursemodule = get_coursemodule_from_id('booking', $cert->bookingid);
                    $options = booking_getbookingoptions($coursemodule->id, $optionid);
                    // Set seminar title
                    if (isset($options['text']) && !empty($options['text'])) {
                        $booking->title = $options['text'];
                    }
                    // Set seminar start date
                    if (isset($options['coursestarttime']) && !empty($options['coursestarttime'])) {
                        $booking->startdate = userdate((int) $options['coursestarttime'], get_string('strftimedatefullshort'));
                    }
                    // Set seminar end date
                    if (isset($options['courseendtime']) && !empty($options['courseendtime'])) {
                        $booking->enddate = userdate((int) $options['courseendtime'], get_string('strftimedatefullshort'));
                    }
                    // Set seminar duration
                    if (isset($options['duration']) && !empty($options['duration'])) {
                        $booking->title = $options['duration'];
                    }
                }
            }
            // Replace all placeholder tags
            $now = time();
            // Set account email if backpack email is not set up and/or connected
            if (isset($cert->recipient->backpackemail) && !empty($cert->recipient->backpackemail)) {
                $recipientemail = $cert->recipient->backpackemail;
            } else {
                $recipientemail = $cert->recipient->accountemail;
            }

            $ownCert = array();

            $ownCert['recipientFirstName'] = $cert->recipient->firstname;
            $ownCert['recipientLastName'] = $cert->recipient->lastname;
            $ownCert['recipientEmail'] = $recipientemail;
            $ownCert['issuerName'] = $cert->issuername;
            $ownCert['issuerContact'] = $cert->issuercontact;
            $ownCert['badgeName'] = $cert->badgeclass['name'];
            $ownCert['badgeDesc'] = $cert->badgeclass['description'];
            $ownCert['badgeNumber'] = $cert->id;
            $ownCert['badgeCourse'] = $DB->get_field('course', 'fullname', array('id' => $cert->courseid));
            $ownCert['badgeHash'] = sha1(rand() . $cert->usercreated . $cert->id . $now);
            $ownCert['bookingTitle'] = $booking->title;
            $ownCert['bookingStartdate'] = $booking->startdate;
            $ownCert['bookingEnddate'] = $booking->enddate;
            $ownCert['bookingDuration'] = $booking->duration;
            $ownCert['recipientBirthdate'] = userdate((int) $cert->recipient->birthdate, get_string('strftimedatefullshort'));
            $ownCert['recipientInstitution'] = $cert->recipient->institution;
            $ownCert['badgeDateIssued'] = userdate((int) $cert->issued, get_string('strftimedatefullshort'));

            $bulkCerts[] = $ownCert;
        }
    }

    return $bulkCerts;
}

/**
 *  Bulk generate badge certificates (and check again if user has necessary privileges)
 */
function bulk_generate_badge_certificates($currentcourseid, $certid) {
    global $CFG, $DB;

    if (user_can_bulk_generate_certificates_in_course($currentcourseid)) {
        // Get issued badges from current course
        $badgeid = $DB->get_field('badge', '*', array('certid' => $certid));
        $sql = "SELECT bi.userid, bi.uniquehash AS hash
                FROM {badge_issued} bi
                WHERE bi.badgeid = :badgeid";
        $badges = $DB->get_records_sql($sql, array('badgeid' => $badgeid));

        // Generate badge certificate for each of the issued badges
        require_once($CFG->libdir . '/tcpdf/tcpdf.php');

        $cert = new badge_certificate($certid);
        $pdf = new TCPDF($cert->orientation, $cert->unit, $cert->format, true, 'UTF-8', false);
        $pdf->SetCreator(PDF_CREATOR);
        // remove default header/footer
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        // set default monospaced font
        $pdf->SetDefaultMonospacedFont(PDF_FONT_MONOSPACED);
        // set margins
        //$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
        // override default margins
        $pdf->SetMargins(0, 0, 0, true);
        // set auto page breaks
        $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
        // set image scale factor
        $pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);
        // set default font subsetting mode
        $pdf->setFontSubsetting(true);

        // Add badge certificate background image
        if ($cert->certbgimage) {
            foreach ($badges as $badge) {
                $assertion = new core_badges_assertion($badge->hash);
                $cert->issued = $assertion->get_badge_assertion();
                $cert->badgeclass = $assertion->get_badge_class();
                // Get a recipient from database.
                $namefields = get_all_user_name_fields(true, 'u');
                $user = $DB->get_record_sql("SELECT u.id, $namefields, u.deleted,
                                                    u.email AS accountemail, b.email AS backpackemail
                            FROM {user} u LEFT JOIN {badge_backpack} b ON u.id = b.userid
                            WHERE u.id = :userid", array('userid' => $badge->userid));
                // Add custom profile field 'Datumrojstva' value
                $fieldid = $DB->get_field('user_info_field', 'id', array('shortname' => 'Datumrojstva'));
                if ($fieldid && $birthdate = $DB->get_field('user_info_data', 'data',
                        array('userid' => $badge->userid, 'fieldid' => $fieldid))) {
                    $user->birthdate = $birthdate;
                } else {
                    $user->birthdate = null;
                }
                // Add custom profile field 'VIZ' value
                $fieldid = $DB->get_field('user_info_field', 'id', array('shortname' => 'VIZ'));
                if ($fieldid && $institution = $DB->get_field('user_info_data', 'data',
                        array('userid' => $badge->userid, 'fieldid' => $fieldid))) {
                    $user->institution = $institution;
                } else {
                    $user->institution = null;
                }
                $cert->recipient = $user;

                // Add a page
                // This method has several options, check the source code documentation for more information.
                $pdf->AddPage();

                // get the current page break margin
                $break_margin = $pdf->getBreakMargin();
                // get current auto-page-break mode
                $auto_page_break = $pdf->getAutoPageBreak();
                // disable auto-page-break
                $pdf->SetAutoPageBreak(false, 0);

                $template = file_get_contents($cert->certbgimage);
                // Get booking related data
                $booking = new StdClass();
                $booking->title = get_string('titlenotset', 'local_badgecerts');
                $booking->startdate = get_string('datenotdefined', 'local_badgecerts');
                $booking->enddate = get_string('datenotdefined', 'local_badgecerts');
                $booking->duration = 0;

                if ($cert->bookingid > 0) {
                    $optionid = booking_getbookingoptionid($cert->bookingid, $badge->userid);
                    if (isset($optionid) && $optionid > 0) {
                        $coursemodule = get_coursemodule_from_id('booking', $cert->bookingid);
                        $options = booking_getbookingoptions($coursemodule->id, $optionid);
                        // Set seminar title
                        if (isset($options['text']) && !empty($options['text'])) {
                            $booking->title = $options['text'];
                        }
                        // Set seminar start date
                        if (isset($options['coursestarttime']) && !empty($options['coursestarttime'])) {
                            $booking->startdate = userdate((int) $options['coursestarttime'], get_string('datetimeformat', 'local_badgecerts'));
                        }
                        // Set seminar end date
                        if (isset($options['courseendtime']) && !empty($options['courseendtime'])) {
                            $booking->enddate = userdate((int) $options['courseendtime'], get_string('datetimeformat', 'local_badgecerts'));
                        }
                        // Set seminar duration
                        if (isset($options['duration']) && !empty($options['duration'])) {
                            $booking->title = $options['duration'];
                        }
                    }
                }
                // Replace all placeholder tags
                $now = time();
                // Set account email if backpack email is not set up and/or connected
                if (isset($cert->recipient->backpackemail) && !empty($cert->recipient->backpackemail)) {
                    $recipientemail = $cert->recipient->backpackemail;
                } else {
                    $recipientemail = $cert->recipient->accountemail;
                }
                $placeholders = array(
                    '[[recipient-fname]]', // Adds the recipient's first name
                    '[[recipient-lname]]', // Adds the recipient's last name
                    '[[recipient-flname]]', // Adds the recipient's full name (first, last)
                    '[[recipient-lfname]]', // Adds the recipient's full name (last, first)
                    '[[recipient-email]]', // Adds the recipient's email address
                    '[[issuer-name]]', // Adds the issuer's name or title
                    '[[issuer-contact]]', // Adds the issuer's contact information
                    '[[badge-name]]', // Adds the badge's name or title
                    '[[badge-desc]]', // Adds the badge's description
                    '[[badge-number]]', // Adds the badge's ID number
                    '[[badge-course]]', // Adds the name of the course where badge was awarded
                    '[[badge-hash]]', // Adds the badge hash value
                    '[[datetime-Y]]', // Adds the year
                    '[[datetime-d.m.Y]]', // Adds the date in dd.mm.yyyy format
                    '[[datetime-d/m/Y]]', // Adds the date in dd/mm/yyyy format
                    '[[datetime-F]]', // Adds the date (used in DB datestamps)
                    '[[datetime-s]]', // Adds Unix Epoch Time timestamp';
                    '[[booking-title]]', // Adds the seminar title
                    '[[booking-startdate]]', // Adds the seminar start date
                    '[[booking-enddate]]', // Adds the seminar end date
                    '[[booking-duration]]', // Adds the seminar duration
                    '[[recipient-birthdate]]', // Adds the recipient's date of birth
                    '[[recipient-institution]]', // Adds the institution where the recipient is employed
                    '[[badge-date-issued]]', // Adds the date when badge was issued
                );
                $values = array(
                    $cert->recipient->firstname,
                    $cert->recipient->lastname,
                    $cert->recipient->firstname . ' ' . $cert->recipient->lastname,
                    $cert->recipient->lastname . ' ' . $cert->recipient->firstname,
                    $recipientemail,
                    $cert->issuername,
                    $cert->issuercontact,
                    $cert->badgeclass['name'],
                    $cert->badgeclass['description'],
                    $cert->id,
                    $DB->get_field('course', 'fullname', array('id' => $cert->courseid)),
                    sha1(rand() . $cert->usercreated . $cert->id . $now),
                    strftime('%Y', $now),
                    userdate($now, get_string('datetimeformat', 'local_badgecerts')),
                    userdate($now, get_string('datetimeformat', 'local_badgecerts')),
                    strftime('%F', $now),
                    strftime('%s', $now),
                    $booking->title,
                    $booking->startdate,
                    $booking->enddate,
                    $booking->duration,
                    userdate((int) $cert->recipient->birthdate, get_string('datetimeformat', 'local_badgecerts')),
                    $cert->recipient->institution,
                    userdate((int) $cert->issued['issuedOn'], get_string('datetimeformat', 'local_badgecerts')),
                );
                $template = str_replace($placeholders, $values, $template);
                $pdf->ImageSVG($file = '@' . $template, 0, 0, 0, 0, '', '', '', 0, true);
                // restore auto-page-break status
                $pdf->SetAutoPageBreak($auto_page_break, $break_margin);
                // set the starting point for the page content
                $pdf->setPageMark();
            }
        }

        // Close and output PDF document
        // This method has several options, check the source code documentation for more information.
        $pdf->Output($cert->badgeclass['name'] . '.pdf', 'D');
    }
}

/**
 *  Hook function to add items to the navigation block.
 */
function local_badgecerts_extends_navigation(global_navigation $nav) {
    if (isloggedin()) {
        // Add 'My badge certificates' item to the navigation block.
        $myprofile = $nav->get('myprofile');
        $myprofile->add(
                get_string('mybadgecertificates', 'local_badgecerts'), new moodle_url('/local/badgecerts/mycerts.php'),
                navigation_node::TYPE_USER, null, 'mybadgecerts'
        );
    }
}

/**
 *  Hook function to add items to the administration block.
 */
function local_badgecerts_extends_settings_navigation(settings_navigation $nav, context $context = null) {
    global $COURSE;
    if (isloggedin()) {
        // Add 'Manage badge certificates' item to the administration block.
        $coursenode = $nav->get('courseadmin');
        if ($coursenode) {
            $coursebadges = $coursenode->get('coursebadges');
            if ($coursebadges) {
                $coursebadges->add(
                        get_string('managebadgecertificates', 'local_badgecerts'),
                        new moodle_url('/local/badgecerts/index.php',
                        array('type' => CERT_TYPE_COURSE, 'id' => $COURSE->id)), navigation_node::TYPE_SETTING, null,
                        'managecerts'
                );
            }
        }
    }
}
