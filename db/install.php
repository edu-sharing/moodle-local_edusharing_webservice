<?php

defined('MOODLE_INTERNAL') || die();


function xmldb_local_edusharing_install(){
    global $DB;
    $query = 'ALTER TABLE ' . $DB->get_prefix() . 'scorm MODIFY COLUMN reference VARCHAR(1000)';
    $DB->execute($query);
    $query = 'ALTER TABLE ' . $DB->get_prefix() . 'files MODIFY COLUMN filename VARCHAR(1000)';
    $DB->execute($query);
    return true;
}