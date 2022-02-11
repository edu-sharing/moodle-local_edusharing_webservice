<?php

require_once(__DIR__ . '/../../config.php');
global $DB;

$token = base64_decode($_GET['token']);
if(empty($token)) {
    redirect('../../login/logout.php?sesskey=' . sesskey(), 'Sie haben keine Berechtigung diesen Kurs zu betreten / You are not allowed to access this course (Missing or invalid token)', null, \core\output\notification::NOTIFY_ERROR);
}

$pubkey = openssl_pkey_get_public(get_config('edusharing', 'application_public_key'));
$decrypted = '';
openssl_public_decrypt($token, $decrypted, $pubkey);
$decrypted = json_decode($decrypted);

if($DB -> record_exists('edusharingtoken', array('userid' => $decrypted -> userid, 'courseid' => $decrypted-> courseid, 'ts' => $decrypted-> ts, 'uniqid' => $decrypted -> uniqid))) {
    //delete record and login and forward user
    $DB -> delete_records('edusharingtoken', array('userid' => $decrypted -> userid, 'courseid' => $decrypted-> courseid, 'ts' => $decrypted-> ts, 'uniqid' => $decrypted -> uniqid));
    $user = $DB->get_record("user", array("id" => $decrypted -> userid));
    complete_user_login($user);
    redirect($CFG->wwwroot . '/course/view.php?id=' . $decrypted-> courseid);
} else {
    redirect('../../login/logout.php?sesskey=' . sesskey(), 'Sie haben keine Berechtigung diesen Kurs zu betreten / You are not allowed to access this course', null, \core\output\notification::NOTIFY_ERROR);
}

exit();
