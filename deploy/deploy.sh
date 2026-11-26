#!/usr/bin/env bash
# Update Bhatta Manager on the server to the newest code (docs/07-deployment.md, "Updating").
# Run as the deploy user from the app folder:  ./deploy/deploy.sh
# The site shows "Being updated" while this runs. A backup of the database is made first.
set -euo pipefail

cd "$(dirname "$0")/.."

# Artisan runs as the web server's user (www-data), like the site, the worker and the scheduler.
# Files it makes (compiled views, caches, backups) then belong to that user; files made by the
# deploy user broke pages ("touch(): Utime failed") and the nightly backup.
# On a server with several projects each app has its own user: it is the group of .env
# (www-data on a one-app server, e.g. "bhatta" on the shared server).
WEB_USER="${WEB_USER:-$(stat -c %G .env 2>/dev/null || echo www-data)}"
artisan() {
    if [ "$(id -un)" = "$WEB_USER" ]; then
        php artisan "$@"
    else
        sudo -u "$WEB_USER" php artisan "$@"
    fi
}

on_error() {
    echo
    echo "The update stopped at the step above. The site is still in maintenance mode."
    echo "Fix the problem and run ./deploy/deploy.sh again, or open the site with: sudo -u $WEB_USER php artisan up"
    echo "The database as it was before the update is in the newest backup (Backups page, or storage/app/backups)."
}
trap on_error ERR

# Checked before anything changes: artisan must be able to read the settings as the web user.
if ! sudo -u "$WEB_USER" test -r .env; then
    echo "$WEB_USER cannot read .env. Fix it with:  sudo chgrp $WEB_USER .env && chmod 640 .env"
    trap - ERR
    exit 1
fi

echo "== Backing up the database"
artisan backup:run --only-db --no-interaction

echo "== Maintenance mode"
artisan down --retry=60

echo "== New code"
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build

echo "== Database and settings"
artisan migrate --force
artisan permissions:sync
artisan kiln:defaults

echo "== Caches"
artisan optimize
artisan queue:restart

artisan up
trap - ERR

echo "== Check"
artisan kiln:check
