#!/usr/bin/env bash
set -euo pipefail
test "$(id -u)" = 0 || { echo 'Run as the deployment administrator.'; exit 1; }
test "$#" = 1 || { echo 'Usage: deploy/upgrade.sh /srv/aims/releases/<reviewed-release>'; exit 1; }
next=$(realpath "$1")
case "$next" in /srv/aims/releases/*) ;; *) echo 'Release must be inside /srv/aims/releases.'; exit 1 ;; esac
test -f "$next/backend/artisan" && test -f "$next/frontend/dist/index.html" && test -f "$next/RELEASE.json"
previous=$(readlink -f /srv/aims/current)
test "$next" != "$previous"
exec 9>/run/lock/aims-backup.lock
flock -n 9 || { echo 'Backup or upgrade already running.'; exit 1; }
test ! -e /srv/aims/shared/storage/framework/down || { echo 'Existing maintenance window: resolve it first.'; exit 1; }
# Prepare these links as part of reviewed release staging, never overwrite data.
test "$(readlink -f "$next/backend/.env")" = /srv/aims/shared/production.env
test "$(readlink -f "$next/backend/storage")" = /srv/aims/shared/storage
cd "$previous/backend"
runuser -u aims -- /usr/bin/php artisan down --retry=60
systemctl stop aims-backup.timer aims-scheduler.timer aims-scheduler.service
systemctl stop aims-worker@default aims-worker@supply-optimizer aims-worker@strategic-simulation
# PR1's backup helper reads the previous schema before any forward migration.
cd "$next/backend"
runuser -u aims -- /usr/bin/php -d memory_limit=2048M artisan aims:installation-backup create
runuser -u aims -- /usr/bin/php artisan migrate --force
runuser -u aims -- /usr/bin/php artisan optimize:clear
runuser -u aims -- /usr/bin/php artisan config:cache
runuser -u aims -- /usr/bin/php artisan route:cache
runuser -u aims -- /usr/bin/php artisan view:cache
runuser -u aims -- /usr/bin/php artisan aims:production-check --before-start
ln -s "$next" /srv/aims/current.next
mv -Tf /srv/aims/current.next /srv/aims/current
systemctl reload php8.3-fpm nginx
systemctl start aims-worker@default aims-worker@supply-optimizer aims-worker@strategic-simulation
runuser -u aims -- /usr/bin/php artisan aims:heartbeat scheduler
runuser -u aims -- /usr/bin/php artisan aims:production-check
runuser -u aims -- /usr/bin/php artisan up
systemctl start aims-scheduler.timer aims-backup.timer
echo "Upgrade completed. Previous application: $previous. Validate owner login and representative workflows before reopening access."
# Any failed command leaves maintenance active. Never migrate:rollback blindly.
