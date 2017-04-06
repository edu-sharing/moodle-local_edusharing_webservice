<?php

$functions = array(
    'local_edusharing_getcategories' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.
            'classname'   => 'local_edusharing_external', // create this class in local/PLUGINNAME/externallib.php
            'methodname'  => 'getcategories', // implement this function into the above class
            'classpath'   => 'local/edusharing/externallib.php',
            'description' => 'tba',
            'type'        => 'read', // the value is 'write' if your function does any database change, otherwise it is 'read'.
            'capabilities'  => 'moodle/xxx:yyy, addon/xxx:yyy',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
    ),
    'local_edusharing_restore' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.
            'classname'   => 'local_edusharing_external', // create this class in local/PLUGINNAME/externallib.php
            'methodname'  => 'restore', // implement this function into the above class
            'classpath'   => 'local/edusharing/externallib.php',
            'description' => 'tba',
            'type'        => 'write', // the value is 'write' if your function does any database change, otherwise it is 'read'.
            'capabilities'  => 'moodle/xxx:yyy, addon/xxx:yyy',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
    ),
    'local_edusharing_gettoken' => array( // local_PLUGINNAME_FUNCTIONNAME is the name of the web service function that the client will call.
        'classname'   => 'local_edusharing_external', // create this class in local/PLUGINNAME/externallib.php
        'methodname'  => 'gettoken', // implement this function into the above class
        'classpath'   => 'local/edusharing/externallib.php',
        'description' => 'tba',
        'type'        => 'write', // the value is 'write' if your function does any database change, otherwise it is 'read'.
        'capabilities'  => 'moodle/xxx:yyy, addon/xxx:yyy',  // List the capabilities used in the function (missing capabilities are displayed for authorised users and also for manually created tokens in the web interface, this is just informative).
    )
);