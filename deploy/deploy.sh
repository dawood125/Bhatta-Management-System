#!/usr/bin/env bash
# Update Bhatta Manager on the server to the newest code (docs/07-deployment.md, "Updating").
# Run as the deploy user from the app folder:  ./deploy/deploy.sh
# The site shows "Being updated" while this runs. A backup of the database is made first.
set -euo pipefail

cd "$(dirname "$0")/.."

on_error() {
    echo
    echo "The update stopped at the step above. The site is still in maintenance mode."
    echo "Fix the problem and run ./deploy/deploy.sh again, or open the site with: php artisan up"
    echo "The database as it was before the update is in the newest backup (Backups page, or storage/app/backups)."
}
trap on_error ERR

echo "== Backing up the database"
php artisan backup:run --only-db --no-interaction

echo "== Maintenance mode"
php artisan down --retry=60

echo "== New code"
git pull --ff-only
composer install --no-dev --optimize-autoloader --no-interaction
npm ci --no-audit --no-fund
npm run build

echo "== Database and settings"
php artisan migrate --force
php artisan permissions:sync
php artisan kiln:defaults

echo "== Caches"
php artisan optimize
php artisan queue:restart

php artisan up
trap - ERR

echo "== Check"
php artisan kiln:check
