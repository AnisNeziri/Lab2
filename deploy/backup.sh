#!/usr/bin/env bash
set -euo pipefail
exec 9>/run/lock/aims-backup.lock
flock -n 9 || exit 0
cd /srv/aims/current/backend
# Preserve an operator's existing maintenance window.
test ! -e storage/framework/down || { echo 'Existing maintenance window; scheduled backup deferred.'; exit 1; }
runuser -u aims -- /usr/bin/php artisan down --retry=60
systemctl stop aims-scheduler.timer aims-scheduler.service
systemctl stop aims-worker@default aims-worker@supply-optimizer aims-worker@strategic-simulation
runuser -u aims -- /usr/bin/php -d memory_limit=2048M artisan aims:installation-backup create --scheduled
# An unsuccessful backup leaves maintenance active for operator attention.
runuser -u aims -- /usr/bin/php artisan up
systemctl start aims-worker@default aims-worker@supply-optimizer aims-worker@strategic-simulation aims-scheduler.timer
