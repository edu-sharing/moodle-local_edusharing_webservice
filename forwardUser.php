<?php

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/config.php');

global $DB;

$token = base64_decode($_GET['token']);
if(empty($token)) {
    throw new Exception('Missing or invalid token');
}


$pubkey = openssl_pkey_get_public(SSL_PUBLIC);
$decrypted = '';
openssl_public_decrypt($token, $decrypted, $pubkey);
$decrypted = json_decode($decrypted);

if($DB -> record_exists('edusharingtoken', array('userid' => $decrypted -> userid, 'courseid' => $decrypted-> courseid, 'ts' => $decrypted-> ts, 'uniqid' => $decrypted -> uniqid))) {
    //delete record and forward user
    $DB -> delete_records('edusharingtoken', array('userid' => $decrypted -> userid, 'courseid' => $decrypted-> courseid, 'ts' => $decrypted-> ts, 'uniqid' => $decrypted -> uniqid));
    $user = $DB->get_record("user", array("id" => $decrypted -> userid));
    complete_user_login($user);
    redirect($CFG->wwwroot . '/course/view.php?id=' . $decrypted-> courseid);
    //header('Location: ' . $CFG->wwwroot . '/course/view.php?id=' . $decrypted-> courseid);
} else {
    throw new Exception('You are not allowed to access this course');
}

exit();
