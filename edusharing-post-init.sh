#!/bin/bash

set -eu
echo "######################"
echo "#                    #"
echo "# INSTALL ES PLUGINS #"
echo "#                    #"
echo "######################"

mv /edusharing/edusharing_webservice /bitnami/moodle/local/edusharing_webservice
mv /edusharing/mod_edusharing /bitnami/moodle/mod/edusharing

chown -R bitnami:bitnami /bitnami/moodle/local/edusharing_webservice
chown -R bitnami:bitnami /bitnami/moodle/mod/edusharing

php /opt/bitnami/moodle/admin/cli/upgrade.php --non-interactive