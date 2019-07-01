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
1. Install this plugin to moodle/local (rename folder to edusharing)
2. Rename config.dist.php to config.php and set up constants
3. Register application in your edu-sharing repository (do this manually or fetch properties from moodle/local/edusharing/metadata.php)
4. Setup the Webservice in Moodle
    - Add external service
    - Add webservice functions
    - Create webservice user
    - Assign webservice functions
    - Generate user webservice token
    - Grant privilieges for required functions
5. Setup config.php in the rendering service moodle module. Use the generated token.
6. Eventually increase Moodle DB 'max_allowed_packet' to restore big courses.

Todo
-----
* List capabilities in services.php according to functions applied
* Configurable options in rendering service
    * Moodle user that restores courses