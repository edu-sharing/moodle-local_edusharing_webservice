<?php

/**
 * edusharing
 *
 * @package    edusharing
 * @copyright  2017 shippeli
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once("config.php");
require_once($CFG->libdir . "/externallib.php");
require_once($CFG->libdir . "/coursecatlib.php");


class local_edusharing_external extends external_api {

    //create user if not exists
    //enroll user if not enrolled (set appropriate role)
    //generate login token
    //return token
    public static function handleuser($username, $courseid, $role) {
        global $CFG, $DB;
        $user = $DB->get_record("user", array("username" => $username));
        if(empty($user))
            $user = create_user_record($username, uniqid());

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
        $hash -> userid = $userid;
        $hash -> courseid = $courseid;
        $hash -> ts = time();
        $hash -> unique = uniqid();

        $DB->insert_record('edusharingtoken', $hash);

        $hash = json_encode($hash);
        $token = self::encrypt($hash);
        $token = base64_encode($token);
        return $token;
    }

    private function encrypt($data) {
        $encrypted = '';
        $privKey = openssl_get_privatekey(SSL_PRIVATE);
        openssl_private_encrypt($data,$encrypted,$privKey);
        return $encrypted;
    }

    public static function getcategories() {
        $cats = self::getCategoriesRecursively();
        return json_encode($cats);
    }
    
    private static function getCategoriesRecursively($id = 0) {
        foreach(coursecat::get($id) -> get_children() as $id => $cat) {
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
        $path = self::prepareCourse($nodeId);
        $courseId = self::restoreCourse($path, $categoryId, $title);
        $course = $DB -> get_record('course', array('id' => $courseId));
        
        $updCourse = array('id' => $courseId, 'fullname' => $title, 'shortname' => $title);
        $DB->update_record('course', $updCourse, $bulk = false);
        
        return json_encode(array('id' => $courseId));
    } 
    
    public static function prepareCourse($nodeId) {
        $path = uniqid();
        self::saveFile($path, $nodeId);
        self::unzipFile($path);
        return $path;
    }
    
    public static function unzipFile($path) {
        global $CFG;
        
        $coursePath = $CFG -> dataroot . '/temp/backup/' .$path;
        
        $zip = new ZipArchive;
        $res = $zip -> open($coursePath . '/course.zip');
        if ($res === TRUE) {
          $zip -> extractTo($coursePath);
          $zip -> close();
        } else {
          error_log('Error unzip');
        }
    }
    
    public static function saveFile($path, $nodeId) {

        global $CFG;
        
        $savePath = $CFG -> dataroot . '/temp/backup/' .$path;

        mkdir($savePath, 0744);
            
        try {       
            $timestamp = round(microtime(true) * 1000);
            $signData = $nodeId . $timestamp;
            $pkeyid = openssl_get_privatekey(SSL_PRIVATE);
            openssl_sign($signData, $signature, $pkeyid);
            $signature = urlencode(base64_encode($signature));
            openssl_free_key($pkeyid); 
            $contentUrl = CONTENT_URL;
            $contentUrl .= '?appId=' . APP_ID;
            $contentUrl .= '&nodeId=' . $nodeId;
            $contentUrl .= '&timeStamp=' . $timestamp;
            $contentUrl .= '&authToken=' . $signature;

            $handle = fopen($contentUrl, "rb");
            if($handle === false) {
                error_log('Error opening ' . $contentUrl);
            }
            $content = stream_get_contents($handle);
            fclose($handle);
            if($content === false) {
                error_log('Error fetching content.');
            }
            
            $handle = fopen($savePath . '/course.zip', "wb");
            fwrite($handle, $content);
            fclose($handle);    

        } catch (Exception $e) {
            error_log('Error in local_edusharing_external::saveFile()');
            return false;
        }
        
        return true;
    }

    
       /**
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
        return new external_value(PARAM_TEXT, 'course id');
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
        return new external_value(PARAM_TEXT, 'category tree in json format');
    }
    
    
}

