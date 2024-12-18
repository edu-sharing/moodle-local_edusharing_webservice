# Webservice plugin to render moodle courses & SCORM packages from edu-sharing

This local plugin provides a REST interface to 
- restore Moodle courses from an edu-sharing repository
- add single activity course and add SCORM package
- create and enrol edu-sharing users to a course
- login edu-sharing users to view a course

HINT: Disable "Include enrolled users" in Moodle backup dialog. It can cause problems while restoring and it brings no advantage in our context.

## Setup

### Manual setup
1. This plugin requires the edu-sharing plugin (https://moodle.org/plugins/mod_edusharing)
2. Install this plugin to moodle/local (rename folder to edusharing_webservice)
3. Setup the edu-sharing-webservice in moodle
    - Webservices and REST-protocol are automatically enabled during installation
    - Create a webservice user
      - You might need to create a system role with the appropriate permissions (rest protocol) and assign it to your webservice user
    - Generate user webservice token:
        - Access Administration > Site administration > Plugins > Web services > Manage tokens
        - Select User: the created webservice-user
        - Select Service: edusharing-webservice
        - Save Changes
5. Setup config.php in the rendering service moodle-module. Use the generated token.
6. You might want to increase Moodle DB 'max_allowed_packet' to restore big courses (MYSQL and MariaDB).

### Docker

> Docker deployment only works with Rendering Service 2

If you wish to setup a complete Moodle instance for rendering purposes, you can use our Docker image:

``` dockerio.mirror.docker.edu-sharing.com/bitnami/moodle:4.5.1 ```

It is based upon a [bitnami image](https://hub.docker.com/r/bitnami/moodle/). Please refer to the linked bitnami documentation for usage details.

When using the image, no manual configuration steps in the Moodle GUI are necessary. For this to work, the following environment variables must be set:

| Name                           |                           Description                           | Example value |
|:-------------------------------|:---------------------------------------------------------------:|--------------:|
| EDUSHARING_WEBSERVICE_USER     |            The username used to call the webservice             |     rendering |
| EDUSHARING_WEBSERVICE_PASSWORD |    The webservice user's password. Must match moodle policy.    |   Rendering#1 |
| EDUSHARING_REPOSITORY_PROT     |         Scheme used by the ES repository to connect to          |         https |
| EDUSHARING_REPOSITORY_PORT     |   Port used by the ES repository to connect to (**OPTIONAL**)   |          1234 |
| EDUSHARING_REPOSITORY_HOST     |             Host of the ES repository to connect to             |     myrepo.de |
| EDUSHARING_REPOSITORY_USERNAME |                 Admin user of the ES repository                 |         admin |
| EDUSHARING_REPOSITORY_PASSWORD |                       Admin user password                       |      password |
| EDUSHARING_MOODLE_HOST         |                    The Render Moodle's host                     |     localhost |
| EDUSHARING_MOODLE_HOST_ALIASES |         The Render Moodle's host aliases (**OPTIONAL**)         |             * |
| EDUSHARING_COURSE_MAX_SIZE     | Max size of uploaded course files (**OPTIONAL**, default: 512M) |         1024M |

For a complete example please refer to the [compose file](docker-compose.yml).

Don't forget to set up the credentials (webservice username and password) in your rendering service.

## Test
If you used the manual installation you should already know your token. When using the Docker image you can obtain your token by using the login endpoint:

``` http://YOUR_MOODLE_HOST/login/token.php?username=YOUR_WEBSERVICE_USER&password=YOUR_PASSWORD&service=es-webservice```

In order to test that the plugin can be reached, use the ping endpoint:

```http://YOUR_MOODLE_HOST/webservice/rest/server.php?wsfunction=local_edusharing_ping&moodlewsrestformat=json&wstoken=YOUR_TOKEN&repoId=YOUR_REPO_ID```

The endpoint tests the validity of your token, the right to access web services and if the correct repo has been registered with moodle. It returns an integer:

- 1: Success
- 2: No connected repo detected
- 3: Connected repo does not match repo provided in query param
- 4: Internal Moodle exception. Please Consult Moodle logs.

## ToDo
* List capabilities in services.php according to functions applied
* Configurable options in rendering service
    * Moodle user that restores courses
