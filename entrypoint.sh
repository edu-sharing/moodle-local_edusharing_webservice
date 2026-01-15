#!/bin/bash
set -e

echo "Waiting for database to be ready..."
until php -r "new mysqli('db', '${MOODLE_DB_USER}', '${MOODLE_DB_PASS}', '${MOODLE_DB_NAME}', 3306);" &> /dev/null; do
  sleep 2
done

if [ ! -f /var/www/html/config.php ]; then
    echo "Starting Moodle CLI installation..."

    chown www-data:www-data /var/www/html

    su -s /bin/bash -c "php admin/cli/install.php \
        --lang=en \
        --chmod=2777 \
        --wwwroot='${MOODLE_WWWROOT}' \
        --dataroot='/var/www/moodledata' \
        --dbtype='mariadb' \
        --dbhost='db' \
        --dbname='${MOODLE_DB_NAME}' \
        --dbuser='${MOODLE_DB_USER}' \
        --dbpass='${MOODLE_DB_PASS}' \
        --fullname='Moodle Docker' \
        --shortname='moodle' \
        --adminuser='${MOODLE_ADMIN_USER}' \
        --adminpass='${MOODLE_ADMIN_PASS}' \
        --adminemail='${MOODLE_ADMIN_EMAIL}' \
        --non-interactive \
        --agree-license" www-data

    echo "Installing ES Plugins"
    mv /edusharing/edusharing /var/www/html/mod/edusharing
    mv /edusharing/edusharing_webservice /var/www/html/local/edusharing_webservice
    ## rm -rf /edusharing

    echo "Running update"
    su -s /bin/bash -c "php admin/cli/upgrade.php --non-interactive" www-data

    chown root:root /var/www/html
    chown www-data:www-data /var/www/html/config.php

    echo "Installation complete."
else
    echo "Moodle already installed. Checking for plugin updates/installations..."
    su -s /bin/bash -c "php admin/cli/upgrade.php --non-interactive" www-data
fi

chown -R www-data:www-data /var/www/html /var/www/moodledata
chmod -R 775 /var/www/html /var/www/moodledata

exec apache2-foreground
