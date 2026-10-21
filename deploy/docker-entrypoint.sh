#!/bin/sh
set -e
# First start: create the database and import address data.
if [ ! -s "${DB_PATH:-storage/database.sqlite}" ]; then
    su -s /bin/sh www-data -c "php bin/setup.php"
fi
exec "$@"
