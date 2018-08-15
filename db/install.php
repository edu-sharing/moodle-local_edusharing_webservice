<?php

defined('MOODLE_INTERNAL') || die();

global $DB;
$query = 'ALTER TABLE ' . $DB->get_prefix() . 'scorm ALTER COLUMN reference VARCHAR (1000)';
$DB->execute($query);