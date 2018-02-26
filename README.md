Webservice plugin to render moodle courses from edu-sharing
===
This local plugin provides a REST interface to 
- restore Moodle courses from an edu-sharing repository
- create and enrol edu-sharing users to a course
- login edu-sharing users to view a course
Setup
---
1. Install this plugin to moodle/local (rename folder to edusharing)
2. Rename config.dist.php to config.php and set up constants
3. Register application in your edu-sharing repository (do this manually or fetch properties from moodle/local/edusharing/metadata.php)
4. Setup the Webservice in Moodle
    1. Add external service
    2. Add webservice functions
    3. Create webservice user
    4. Assign webservice functions
    5. Generate user webservice token
    6. Grant privilieges for required functions
5. Setup config.php in the rendering service moodle module. Use the generated token.
Todo
---
* List capabilities in services.php according to functions applied
* Configurable options in rendering service
    * Moodle user that restores courses
    * Category to restore to