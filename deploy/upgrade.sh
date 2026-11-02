#!/usr/bin/env bash
set -euo pipefail
test "$(id -u)" = 0 || { echo 'Run as the deployment administrator.'; exit 1; }
test "$#" = 1 || { echo 'Usage: deploy/upgrade.sh /srv/aims/releases/<reviewed-release>'; exit 1; }
stage=resolve_release
migration_started=false
migration_completed=false
code_changed=false
state_file=/srv/aims/shared/storage/framework/aims-update-state.json
write_state() {
  printf '{"stage":"%s","migration_started":%s,"migration_completed":%s,"code_changed":%s,"failed":%s}\n' "$stage" "$migration_started" "$migration_completed" "$code_changed" "${1:-false}" > "$state_file.next"
  mv -f "$state_file.next" "$state_file"
}
advance_stage() { stage="$1"; write_state; }
failure() {
  write_state true
  printf 'Update failed at stage=%s; migration_started=%s; migration_completed=%s; code_changed=%s. Keep maintenance active. Do not assume database rollback is safe; use the verified recovery runbook.\n' "$stage" "$migration_started" "$migration_completed" "$code_changed"
}
trap failure ERR
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
advance_stage verify_signed_release
# Execute the verifier from the current trusted release, never from unverified staged code.
# Bootstrap DC8 manually once; older installations lack this command and must stop here.
cd "$previous/backend"
test -f "$next/UPDATE.signed.json"
runuser -u aims -- /usr/bin/php artisan aims:verify-release --file="$next/UPDATE.signed.json" --artifacts-root="$next/artifacts" --release-root="$next"
advance_stage maintenance
runuser -u aims -- /usr/bin/php artisan down --retry=60
systemctl stop aims-backup.timer aims-scheduler.timer aims-scheduler.service
systemctl stop aims-worker@default aims-worker@supply-optimizer aims-worker@strategic-simulation
# The trusted current release backs up the old schema before any forward migration.
advance_stage verified_backup
runuser -u aims -- /usr/bin/php -d memory_limit=2048M artisan aims:installation-backup create
advance_stage migrations
cd "$next/backend"
migration_started=true
runuser -u aims -- /usr/bin/php artisan migrate --force
migration_completed=true
advance_stage configuration
runuser -u aims -- /usr/bin/php artisan optimize:clear
runuser -u aims -- /usr/bin/php artisan config:cache
runuser -u aims -- /usr/bin/php artisan route:cache
runuser -u aims -- /usr/bin/php artisan view:cache
runuser -u aims -- /usr/bin/php artisan aims:production-check --before-start
advance_stage activate_code
ln -s "$next" /srv/aims/current.next
mv -Tf /srv/aims/current.next /srv/aims/current
code_changed=true
advance_stage post_update_health
systemctl reload php8.3-fpm nginx
systemctl start aims-worker@default aims-worker@supply-optimizer aims-worker@strategic-simulation
runuser -u aims -- /usr/bin/php artisan aims:heartbeat scheduler
runuser -u aims -- /usr/bin/php artisan aims:production-check
runuser -u aims -- /usr/bin/php artisan up
systemctl start aims-scheduler.timer aims-backup.timer
advance_stage complete
trap - ERR
echo "Upgrade completed. Previous application: $previous. Validate owner login and representative workflows before reopening access."
# Any failed command leaves maintenance active. Never migrate:rollback blindly.
