<?php

//error_reporting(E_ALL);
//ini_set('display_errors', 1);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/config.php');

global $DB;

$token = base64_decode($_GET['token']);

$pubkey = openssl_pkey_get_public(SSL_PUBLIC);
$decrypted = '';
openssl_public_decrypt($token, $decrypted, $pubkey);

if($DB -> record_exists('edusharingtoken', array('userid' => $decrypted -> userid, 'courseid' => $decrypted-> courseid, 'ts' => $decrypted-> ts, 'unique' => $decrypted -> unique))) {
    //delete record and forward user
    $DB -> delete_records('edusharingtoken', array('userid' => $decrypted -> userid, 'courseid' => $decrypted-> courseid, 'ts' => $decrypted-> ts, 'unique' => $decrypted -> unique));
} else {
    throw new Exception('You are not allowed to access this course');
}

$user = $DB->get_record("user", array("userid" => $decrypted -> userid));
complete_user_login($user);

header('Location: ' . $CFG->wwwroot . '/course/view.php?id=' . $decrypted-> courseid);
exit();
