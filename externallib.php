<?php

/**
 * Defines the version of the edu-sharing_webservice plugin
 *
 * @package    edusharing_webservice
 * @copyright  metaVentis GmbH — http://metaventis.com
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

global $CFG;

require_once($CFG->libdir . "/externallib.php");
//require_once($CFG->libdir . "/coursecatlib.php");
require_once ($CFG->dirroot . '/course/lib.php');
require_once ($CFG->dirroot . '/course/modlib.php');
require_once ($CFG->dirroot . '/mod/scorm/lib.php');


class local_edusharing_webservice_external extends external_api {

    public static function ping(String $repoId): Int {
        try {
            $connectedRepoId = get_config('edusharing', 'application_homerepid');
            if (empty($connectedRepoId)) {
                return 2;
            }
            if ($connectedRepoId !== $repoId) {
                return 3;
            }
        } catch (Exception $exception) {
            error_log($exception->getMessage());
            return 4;
        }
        return 1;
    }

    //create user if not exists
    //enroll user if not enrolled (set appropriate role)
    //generate login token
    //return token
    public static function handleuser($user_name, $user_givenname, $user_surname, $user_email, $courseid, $role) {
        global $DB;
        $user = $DB->get_record("user", array("username" => $user_name));
        if(empty($user)) {
            $user = create_user_record($user_name, uniqid());
            $user -> firstname = $user_givenname;
            $user -> lastname = $user_surname;
            $user -> email = $user_email;
            $DB -> update_record('user', $user);
        }

        $context = context_course::instance($courseid);

        $roleshortname = 'student';
        if($role == 'editingteacher') {
            $roleshortname = 'editingteacher';
        }
        $roleid = $DB->get_field('role', 'id', array('shortname' => $roleshortname));

        if (!is_enrolled($context, $user->id)) {
            if (!enrol_try_internal_enrol($courseid, $user->id, $roleid, time())) {
                throw new moodle_exception('unabletoenrolerrormessage', 'langsourcefile');
            }
        }
        $token = self::generateToken($user->id, $courseid);

        return $token;

    }

    private static function generateToken($userid, $courseid) {

        global $DB;

        $hash = new stdClass;
        $hash -> userid = (int)$userid;
        $hash -> courseid = (int)$courseid;
        $hash -> ts = time();
        $hash -> uniqid = uniqid();

        $DB->insert_record('edusharingtoken', $hash);

        $hash = json_encode($hash);
        $token = self::encrypt($hash);
        $token = base64_encode($token);

        return $token;
    }

    public static function encrypt($data) {
        $encrypted = '';
        //$privKey = openssl_get_privatekey(SSL_PRIVATE);
        $privKey = openssl_get_privatekey(get_config('edusharing', 'application_private_key'));
        openssl_private_encrypt($data,$encrypted,$privKey);
        return $encrypted;
    }

    public static function getcategories() {
        $cats = self::getCategoriesRecursively();
        return json_encode($cats);
    }

    private static function getCategoriesRecursively($id = 0) {
        foreach(core_course_category::get($id) -> get_children() as $id => $cat) {
            $catArray[$id] = $cat -> getIterator() -> getArrayCopy();
            $catArray[$id]['children'] = self::getCategoriesRecursively($id);
        }
        return $catArray;
    }


    private static function restoreCourse($path, $categoryId, $title) {
        global $CFG, $DB;

        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        // Transaction.
        $transaction = $DB -> start_delegated_transaction();
        // Create new course.
        $folder  = $path; // as found in: $CFG->dataroot . '/temp/backup/'
        $categoryid = $categoryId;
        $userdoingrestore = 2; // e.g. 2 == admin
        $courseid = restore_dbops::create_new_course('', '', $categoryid);

        // Restore backup into course.
        $controller = new restore_controller($folder, $courseid,
            backup::INTERACTIVE_NO, backup::MODE_GENERAL, $userdoingrestore,
            backup::TARGET_NEW_COURSE);
        $controller->execute_precheck();
        $controller->execute_plan();

        // Commit.
        $transaction->allow_commit();
        return $courseid;
    }


    public static function restore($nodeId, $categoryId, $title) {
        global $CFG, $DB;

        //delete course/enrolments
        self::cleanup($nodeId);

        $path = self::prepareCourse($nodeId);
        $courseId = self::restoreCourse($path, $categoryId, $title);
        $course = $DB -> get_record('course', array('id' => $courseId));

        $updCourse = array('id' => $courseId, 'fullname' => $title, 'shortname' => $title, 'idnumber' => $nodeId);
        $DB->update_record('course', $updCourse, $bulk = false);

        //activity backups do not set enrolement method on restore, so do this manually
        $enrolId = $DB -> get_record('enrol', array('courseid' => $courseId, 'enrol' => 'manual' ));
        if(empty($enrolId)) {
            $enrol = new stdClass();
            $enrol -> enrol = 'manual';
            $enrol -> courseid = $courseId;
            $DB->insert_record('enrol', $enrol);
        }
        return json_encode($courseId);
    }

    public static function cleanup($nodeId) {
        global $DB;
        $course = $DB -> get_record('course', array('idnumber' => $nodeId));
        $DB -> delete_records('course', array('idnumber' => $nodeId));
        $DB -> delete_records('enrol', array('courseid' => $course->id));
    }

    public static function createempty($nodeId, $categoryId, $title) {
        $unique = uniqid();
        $data = new stdClass();
        $data->category = $categoryId;
        $data->fullname = $title;
        $data->shortname = $title . '_' . $unique;
        $data->idnumber = $nodeId;
        $course = create_course($data);
        return json_encode((int)$course->id);
    }


    public static function scorm($nodeId, $categoryId, $title) {
        ini_set(
            'memory_limit',
            getenv('EDUSHARING_COURSE_MAX_SIZE') === false ? "512M" : getenv('EDUSHARING_COURSE_MAX_SIZE')
        );
        $unique = uniqid();
        global $DB;

        $data = new stdClass();
        $data->category = $categoryId;
        $data->fullname = $title . '_' . $unique;
        $data->shortname = $title . '_' . $unique;
        $data->format= 'singleactivity';
        $data->activitytype = 'scorm';
        $course = create_course($data);

        set_config('allowtypelocalsync', 1, 'scorm');
        $scorm_module_id = $DB->get_field('modules', 'id', array('name'  => 'scorm'));

        $timestamp = round(microtime(true) * 1000);
        $signData = $nodeId . $timestamp;
        $pkeyid = openssl_get_privatekey(get_config('edusharing', 'application_private_key'));
        openssl_sign($signData, $signature, $pkeyid);
        $signature = urlencode(base64_encode($signature));
        openssl_free_key($pkeyid);
        $contentUrl = trim(get_config('edusharing', 'application_cc_gui_url'), '/') . '/content';
        $contentUrl .= '?appId=' . get_config('edusharing', 'application_appid');
        $contentUrl .= '&nodeId=' . $nodeId;
        $contentUrl .= '&timeStamp=' . $timestamp;
        $contentUrl .= '&authToken=' . $signature;

        $scormdata = new stdClass();
        $scormdata->scormtype = SCORM_TYPE_LOCALSYNC;
        $scormdata->packageurl = $contentUrl;
        $scormdata->intro = 'scorm';
        $scormdata->datadir = '';
        $scormdata->pkgtype = '';
        $scormdata->launch = '';
        $scormdata->redirect = 'yes';
        $scormdata->redirecturl = '../course/view.php?id=' . $course->id;
        $scormdata->completionunlocked = '1';
        $scormdata->course = $course->id;
        $scormdata->section = 0;
        $scormdata->module = $scorm_module_id;
        $scormdata->modulename = 'scorm';
        $scormdata->instance = '';
        $scormdata->return = '0';
        $scormdata->sr = '0';
        $scormdata->mform_showmore_id_displaysettings = '0';
        $scormdata->mform_isexpanded_id_general = '1';
        $scormdata->mform_isexpanded_id_displaysettings = '0';
        $scormdata->mform_isexpanded_id_availability = '0';
        $scormdata->mform_isexpanded_id_gradesettings = '0';
        $scormdata->mform_isexpanded_id_attemptsmanagementhdr = '0';
        $scormdata->mform_isexpanded_id_compatibilitysettingshdr = '0';
        $scormdata->mform_isexpanded_id_modstandardelshdr = '0';
        $scormdata->mform_isexpanded_id_availabilityconditionsheader = '0';
        $scormdata->mform_isexpanded_id_activitycompletionheader = '0';
        $scormdata->mform_isexpanded_id_tagshdr = '0';
        $scormdata->mform_isexpanded_id_competenciessection = '0';
        $scormdata->updatefreq = '0';
        $scormdata->popup = '0';
        $scormdata->displayactivityname = '0';
        $scormdata->displayactivityname = '1';
        $scormdata->skipview = '0';
        $scormdata->hidebrowse = '0';
        $scormdata->displaycoursestructure = '0';
        $scormdata->hidetoc = '0';
        $scormdata->nav = '1';
        $scormdata->displayattemptstatus = '1';
        $scormdata->grademethod = '1';
        $scormdata->maxgrade = '100';
        $scormdata->maxattempt = '0';
        $scormdata->whatgrade = '0';
        $scormdata->forcenewattempt = '0';
        $scormdata->lastattemptlock = '0';
        $scormdata->forcecompleted = '0';
        $scormdata->auto = '0';
        $scormdata->autocommit = '0';
        $scormdata->masteryoverride = '1';
        $scormdata->visible = '1';
        $scormdata->cmidnumber = '';
        $scormdata->groupmode = '0';
        $scormdata->completion = '1';
        $scormdata->competency_rule = '0';
        $scormdata->name = $title;
        $scormdata->add = 'scorm';
        $scormdata->visible = 1;
        $scormdata->availablefrom = 0;
        $scormdata->availableuntil = 0;
        $scormdata->showavailability = 1;
        $scormdata->width = 100;
        $scormdata->height = 100;

        add_moduleinfo($scormdata, $course);

        return json_encode((int)$course->id);
    }

    public static function prepareCourse($nodeId) {
        $path = uniqid();
        self::saveFile($path, $nodeId);
        self::unpackFile($path);
        return $path;
    }

    public static function unpackFile($path) {

        global $CFG;

        $coursePath = $CFG -> dataroot . '/temp/backup/' .$path;
        try {
            $zip = new ZipArchive;
            $res = $zip -> open($coursePath . '/course.mbz');

            if ($res === TRUE) {
                $zip -> extractTo($coursePath);
                $zip -> close();
            } else {
                // decompress from gz
                $p = new PharData($coursePath . '/course.mbz');
                $p -> decompress();
                // unarchive from the tar
                $phar = new PharData($coursePath . '/course.tar');
                $phar -> extractTo($coursePath);
            }
        } catch(Exception $e) {
            error_log('Error unpacking course');
        }
    }


    public static function saveFile($path, $nodeId) {
        ini_set(
            'memory_limit',
            getenv('EDUSHARING_COURSE_MAX_SIZE') === false ? "512M" : getenv('EDUSHARING_COURSE_MAX_SIZE')
        );
        global $CFG;

        $savePath = $CFG -> dataroot . '/temp/backup/' .$path;

        mkdir($savePath, 0744);

        try {
            $timestamp = round(microtime(true) * 1000);
            $signData = $nodeId . $timestamp;
            $pkeyid = openssl_get_privatekey(get_config('edusharing', 'application_private_key'));
            openssl_sign($signData, $signature, $pkeyid);
            $signature = urlencode(base64_encode($signature));
            openssl_free_key($pkeyid);
            $contentUrl = trim(get_config('edusharing', 'application_cc_gui_url'), '/') . '/content';
            $contentUrl .= '?appId=' . get_config('edusharing', 'application_appid');
            $contentUrl .= '&nodeId=' . $nodeId;
            $contentUrl .= '&timeStamp=' . $timestamp;
            $contentUrl .= '&authToken=' . $signature;


	        $opts=array("ssl"=>array("verify_peer"=>false,"verify_peer_name"=>false));

            $handle = fopen($contentUrl, "rb", false, stream_context_create($opts));
            if($handle === false) {
                error_log('Error opening ' . $contentUrl);
            }
            $content = stream_get_contents($handle);
            fclose($handle);
            if($content === false) {
                error_log('Error fetching content.');
            }

            $handle = fopen($savePath . '/course.mbz', "wb");
            fwrite($handle, $content);
            fclose($handle);

        } catch (Exception $e) {
            error_log('Error in local_edusharing_webservice_external::saveFile()');
            return false;
        }

        return true;
    }


    /**restore_parameters
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function restore_parameters() {
        return new external_function_parameters(array(
            'nodeid' => new external_value(PARAM_TEXT, 'node ID of course file in repository'),
            'category' => new external_value(PARAM_INT, 'category id to restore course'),
            'title' => new external_value(PARAM_TEXT, 'name for course')
        ));
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function restore_returns() {
        return new external_value(PARAM_INT, 'course id');
    }

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function createempty_parameters() {
        return new external_function_parameters(array(
            'nodeid' => new external_value(PARAM_TEXT, 'node ID of course file in repository'),
            'category' => new external_value(PARAM_INT, 'category id to restore course'),
            'title' => new external_value(PARAM_TEXT, 'name for course')
        ));
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function createempty_returns() {
        return new external_value(PARAM_INT, 'course id');
    }


    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function scorm_parameters() {
        return new external_function_parameters(array(
            'nodeid' => new external_value(PARAM_TEXT, 'node ID of course file in repository'),
            'category' => new external_value(PARAM_INT, 'category id to restore course'),
            'title' => new external_value(PARAM_TEXT, 'name for course')
        ));
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function scorm_returns() {
        return new external_value(PARAM_INT, 'course id');
    }

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function handleuser_parameters() {
        return new external_function_parameters(array(
            'user_name' => new external_value(PARAM_TEXT, 'username to create / enrol / login'),
            'user_givenname' => new external_value(PARAM_TEXT, 'user_givenname'),
            'user_surname' => new external_value(PARAM_TEXT, 'user_surname'),
            'user_email' => new external_value(PARAM_TEXT, 'user_email'),
            'courseid' => new external_value(PARAM_INT, 'course id'),
            'role' => new external_value(PARAM_TEXT, 'role for enrolement')
        ));
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function handleuser_returns() {
        return new external_value(PARAM_TEXT, 'token');
    }

    /**
     * Returns description of method parameters
     * @return external_function_parameters
     */
    public static function getcategories_parameters() {
        return new external_function_parameters(array());
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function getcategories_returns() {
        return new external_function_parameters([]);
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function ping_parameters() {
        return new external_function_parameters([
            "repoId" => new external_value(PARAM_TEXT, 'repoId'),
        ]);
    }

    /**
     * Returns description of method result value
     * @return external_description
     */
    public static function ping_returns() {
        return new external_value(PARAM_TEXT, 'category tree in json format');
    }
}

