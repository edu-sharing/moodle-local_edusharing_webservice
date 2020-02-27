Webservice plugin to render moodle courses & SCORM packages from edu-sharing
=====
This local plugin provides a REST interface to 
- restore Moodle courses from an edu-sharing repository
- add single activity course and add SCORM package
- create and enrol edu-sharing users to a course
- login edu-sharing users to view a course

HINT: Disable "Include enrolled users" in Moodle backup dialog. It can cause problems while restoring and it brings no advantage in our context.

Setup
-----
1. This plugin requires the edu-sharing plugin (https://moodle.org/plugins/mod_edusharing)
2. Install this plugin to moodle/local (rename folder to edusharing_webservices)
3. Setup the edu-sharing-webservice in moodle
    - Activate webservices:
        - Access Administration > Site administration > Advanced features
        - Check 'Enable web services' then click 'Save Changes'
    - Enable REST-protocol:
        - Access Administration > Site administration > Plugins > Web services > Manage protocols
        - Enable REST-protocol
    - Create a webservice user
    - Generate user webservice token:
        - Access Administration > Site administration > Plugins > Web services > Manage tokens
        - Select User: the created webservice-user
        - Select Service: edusharing-webservice
        - Save Changes
5. Setup config.php in the rendering service moodle-module. Use the generated token.
6. Eventually increase Moodle DB 'max_allowed_packet' to restore big courses.

Todo
-----
* List capabilities in services.php according to functions applied
* Configurable options in rendering service
    * Moodle user that restores courses
