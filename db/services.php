<?php

$services = array(
    'edusharing-webservice' => array(                           //the name of the web service
        'functions' => array (                                  //web service functions of this service
            'local_edusharing_getcategories',
            'local_edusharing_restore',
            'local_edusharing_createempty',
            'local_edusharing_handleuser',
            'local_edusharing_scorm',
            'local_edusharing_ping'
        ),
        'requiredcapability' => '',                             //if set, the web service user need this capability to access any function of this service. For example: 'some/capability:specified'
        'restrictedusers' =>0,                                  //if enabled, the Moodle administrator must link some user to this service into the administration
        'enabled'=>1,                                           //if enabled, the service can be reachable on a default installation
        'shortname' =>  'es-webservice'
    )
);

$functions = array(
    'local_edusharing_getcategories' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.
            'classname'   => 'local_edusharing_webservice_external', // create this class in local/PLUGINNAME/externallib.php
            'methodname'  => 'getcategories', // implement this function into the above class
            'classpath'   => 'local/edusharing_webservice/externallib.php',
            'description' => 'Get categories from moodle.',
            'type'        => 'read', // the value is 'write' if your function does any database change, otherwise it is 'read'.
            'capabilities'  => 'moodle/xxx:yyy, addon/xxx:yyy',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
    ),
    'local_edusharing_restore' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.
            'classname'   => 'local_edusharing_webservice_external', // create this class in local/PLUGINNAME/externallib.php
            'methodname'  => 'restore', // implement this function into the above class
            'classpath'   => 'local/edusharing_webservice/externallib.php',
            'description' => 'Restore course from an edu-sharing repository to moodle.',
            'type'        => 'write', // the value is 'write' if your function does any database change, otherwise it is 'read'.
            'capabilities'  => 'moodle/xxx:yyy, addon/xxx:yyy',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
    ),
    'local_edusharing_createempty' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.
        'classname'   => 'local_edusharing_webservice_external', // create this class in local/PLUGINNAME/externallib.php
        'methodname'  => 'createempty', // implement this function into the above class
        'classpath'   => 'local/edusharing_webservice/externallib.php',
        'description' => 'Create an empty course',
        'type'        => 'write', // the value is 'write' if your function does any database change, otherwise it is 'read'.
        'capabilities'  => 'moodle/xxx:yyy, addon/xxx:yyy',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
    ),
    'local_edusharing_handleuser' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.
        'classname'   => 'local_edusharing_webservice_external', // create this class in local/PLUGINNAME/externallib.php
        'methodname'  => 'handleuser', // implement this function into the above class
        'classpath'   => 'local/edusharing_webservice/externallib.php',
        'description' => 'tba',
        'type'        => 'write', // the value is 'write' if your function does any database change, otherwise it is 'read'.
        'capabilities'  => 'moodle/xxx:yyy, addon/xxx:yyy',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
    ),
    'local_edusharing_scorm' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.
        'classname'   => 'local_edusharing_webservice_external', // create this class in local/PLUGINNAME/externallib.php
        'methodname'  => 'scorm', // implement this function into the above class
        'classpath'   => 'local/edusharing_webservice/externallib.php',
        'description' => 'Restore a SCORM-package from an edu-sharing repository to moodle.',
        'type'        => 'write', // the value is 'write' if your function does any database change, otherwise it is 'read'.
        'capabilities'  => 'moodle/xxx:yyy, addon/xxx:yyy',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
    ),
    'local_edusharing_ping' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.
        'classname'   => 'local_edusharing_webservice_external', // create this class in local/PLUGINNAME/externallib.php
        'methodname'  => 'ping', // implement this function into the above class
        'classpath'   => 'local/edusharing_webservice/externallib.php',
        'description' => 'Test the plugins functionality.',
        'type'        => 'read', // the value is 'write' if your function does any database change, otherwise it is 'read'.
        'capabilities'  => 'moodle/xxx:yyy, addon/xxx:yyy',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
    )
);
