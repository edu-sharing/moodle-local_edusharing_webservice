#!/bin/bash

set -eu
echo "######################"
echo "#                    #"
echo "# INSTALL ES PLUGINS #"
echo "#                    #"
echo "######################"

# Load Moodle environment
. /opt/bitnami/scripts/moodle-env.sh
. /opt/bitnami/scripts/php-env.sh
. /opt/bitnami/scripts/apache-env.sh

# Load bitnami libraries
. /opt/bitnami/scripts/libos.sh
. /opt/bitnami/scripts/libbitnami.sh
. /opt/bitnami/scripts/liblog.sh
. /opt/bitnami/scripts/libwebserver.sh

mv /edusharing/edusharing_webservice /bitnami/moodle/local/edusharing_webservice
mv /edusharing/mod_edusharing /bitnami/moodle/mod/edusharing

## Use for local testing with volume instead of the line above
##ln -s /edusharing/mod_edusharing /bitnami/moodle/mod/edusharing

chown -R daemon:root /bitnami/moodle/local/edusharing_webservice
chown -R daemon:root /bitnami/moodle/mod/edusharing

moodle_upgrade_args=(
    "${PHP_BIN_DIR}/php"
    "${MOODLE_BASE_DIR}/admin/cli/upgrade.php"
    "--non-interactive"
)

debug_execute run_as_user "$WEB_SERVER_DAEMON_USER" "${moodle_upgrade_args[@]}"
