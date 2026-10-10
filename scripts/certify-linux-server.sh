#!/usr/bin/env bash
# Runs ONLY on a disposable GitHub runner. Never execute on a customer's host.
set -euo pipefail
test "${CI:-}" = true && test "${AIMS_CERTIFICATION_SERVER:-}" = 1
root=$(cd "$(dirname "$0")/.." && pwd)
sudo apt-get update -qq
sudo apt-get install -y nginx mariadb-server redis-server php8.3-fpm php8.3-mysql php8.3-bcmath php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip gettext-base ripgrep
sudo systemctl start mariadb redis-server php8.3-fpm
sudo useradd --system --user-group --home /srv/aims aims
sudo mkdir -p /srv/aims/releases /srv/aims/shared/storage/{app/private,app/public,framework/cache/data,framework/sessions,framework/views,logs} /srv/aims/shared/{documents-private,encrypted-backups} /srv/aims/bin /etc/aims /var/lib/letsencrypt
sudo ln -s "$root" /srv/aims/releases/certification
sudo ln -s /srv/aims/releases/certification /srv/aims/current
# The runner checkout is disposable; move its generated runtime storage only.
sudo cp -a "$root/backend/storage/." /srv/aims/shared/storage/
mv "$root/backend/storage" "$root/backend/storage-ci-unused"
ln -s /srv/aims/shared/storage "$root/backend/storage"
db_password=$(openssl rand -hex 24)
backup_password=$(openssl rand -hex 32)
app_key="base64:$(openssl rand -base64 32)"
sudo mariadb -e "CREATE DATABASE aims_ci_server; CREATE USER 'aims_ci_service'@'127.0.0.1' IDENTIFIED BY '$db_password'; GRANT ALL PRIVILEGES ON aims_ci_server.* TO 'aims_ci_service'@'127.0.0.1';"
sudo cp "$root/deploy/production.env.example" /srv/aims/shared/production.env
sudo sed -i -e "s|^APP_KEY=.*|APP_KEY=$app_key|" -e 's|https://your-company-domain|https://aims-ci.internal|g' -e 's|DB_DATABASE=aims_company|DB_DATABASE=aims_ci_server|' -e 's|DB_USERNAME=aims_service|DB_USERNAME=aims_ci_service|' -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$db_password|" -e "s|^AIMS_ML_PYTHON=.*|AIMS_ML_PYTHON=$(command -v python)|" /srv/aims/shared/production.env
printf '%s\n' "$backup_password" | sudo tee /etc/aims/backup-passphrase >/dev/null
sudo chown -R aims:aims /srv/aims/shared /etc/aims "$root/backend/bootstrap/cache"
sudo chmod 0600 /etc/aims/backup-passphrase /srv/aims/shared/production.env
sudo chmod -R o+rX "$root" # Ephemeral CI source only; secrets remain outside checkout.
sudo chmod o+x /home/runner /home/runner/work /home/runner/work/Lab2
ln -s /srv/aims/shared/production.env "$root/backend/.env"
sudo sed -i 's/^user = .*/user = aims/; s/^group = .*/group = aims/' /etc/php/8.3/fpm/pool.d/www.conf
sudo systemctl restart php8.3-fpm
cd "$root/backend"
sudo -u aims /usr/bin/php artisan migrate --force
sudo -u aims env CI=true AIMS_CERTIFICATION_SERVER=1 DB_DATABASE=aims_ci_server /usr/bin/php "$root/scripts/certify-server-fixture.php"
sudo -u aims /usr/bin/php artisan config:cache
sudo -u aims /usr/bin/php artisan route:cache
sudo -u aims /usr/bin/php artisan aims:production-check --before-start
sudo mkdir -p /etc/letsencrypt/live/aims-ci.internal
sudo openssl req -x509 -newkey rsa:2048 -nodes -keyout /etc/letsencrypt/live/aims-ci.internal/privkey.pem -out /etc/letsencrypt/live/aims-ci.internal/fullchain.pem -days 1 -subj /CN=aims-ci.internal 2>/dev/null
sudo rm /etc/nginx/sites-enabled/default
AIMS_DOMAIN=aims-ci.internal AIMS_ROOT=/srv/aims/current PHP_FPM_SOCKET=/run/php/php8.3-fpm.sock envsubst '${AIMS_DOMAIN} ${AIMS_ROOT} ${PHP_FPM_SOCKET}' < "$root/deploy/nginx/aims.conf.template" | sudo tee /etc/nginx/sites-enabled/aims >/dev/null
sudo nginx -t
sudo systemctl restart nginx
sudo cp "$root/deploy/systemd/"aims-* /etc/systemd/system/
sudo install -m 0750 "$root/deploy/backup.sh" /srv/aims/bin/backup.sh
sudo systemctl daemon-reload
sudo systemctl enable --now aims-worker@default aims-worker@supply-optimizer aims-worker@strategic-simulation aims-scheduler.timer
for attempt in $(seq 1 30); do if sudo -u aims /usr/bin/php artisan aims:production-check --json > "$root/output/pr1-linux-readiness.json"; then break; fi; sleep 2; done
sudo -u aims /usr/bin/php artisan aims:production-check
curl -ksS --resolve aims-ci.internal:443:127.0.0.1 https://aims-ci.internal/api/readiness | python -c 'import json,sys; assert json.load(sys.stdin)["status"]=="ready"'
test "$(curl -s -o /dev/null -w '%{http_code}' --resolve aims-ci.internal:80:127.0.0.1 http://aims-ci.internal/dashboard)" = 301
test "$(curl -ks -o /dev/null -w '%{http_code}' --resolve aims-ci.internal:443:127.0.0.1 https://aims-ci.internal/dashboard)" = 200
test "$(curl -ks -o /dev/null -w '%{http_code}' --resolve aims-ci.internal:443:127.0.0.1 https://aims-ci.internal/storage/private-test)" = 404
test "$(curl -ks -o /dev/null -w '%{http_code}' --resolve aims-ci.internal:443:127.0.0.1 https://aims-ci.internal/.env)" = 403
curl -ksSI --resolve aims-ci.internal:443:127.0.0.1 https://aims-ci.internal/index.html | rg -i 'x-frame-options: DENY|strict-transport-security'
sudo systemctl stop redis-server
test "$(curl -ks -o /dev/null -w '%{http_code}' --resolve aims-ci.internal:443:127.0.0.1 https://aims-ci.internal/api/readiness)" = 503
sudo curl -ksS -H @/srv/aims/shared/certification-headers --resolve aims-ci.internal:443:127.0.0.1 https://aims-ci.internal/api/products | python -c 'import json,sys; assert isinstance(json.load(sys.stdin)["data"],list)'
sudo systemctl start redis-server
sudo systemctl stop mariadb
test "$(sudo curl -ks -H @/srv/aims/shared/certification-headers -o /dev/null -w '%{http_code}' --resolve aims-ci.internal:443:127.0.0.1 https://aims-ci.internal/api/products)" = 503
sudo systemctl start mariadb
previous_pid=$(sudo systemctl show -p MainPID --value aims-worker@default)
sudo systemctl kill --signal=SIGKILL aims-worker@default
sleep 5
next_pid=$(sudo systemctl show -p MainPID --value aims-worker@default)
test "$previous_pid" != "$next_pid" && test "$next_pid" != 0
# Service stop/start validates restart behavior; it does not claim a host reboot.
sudo systemctl stop aims-worker@default aims-worker@supply-optimizer aims-worker@strategic-simulation aims-scheduler.timer
sudo systemctl start aims-worker@default aims-worker@supply-optimizer aims-worker@strategic-simulation aims-scheduler.timer
sleep 5
sudo -u aims /usr/bin/php artisan aims:production-check
if ! sudo systemctl start aims-backup.service; then
  sudo journalctl -u aims-backup.service --no-pager -n 40
  exit 1
fi
sudo find /srv/aims/shared/encrypted-backups -maxdepth 1 -name '*.aimsinstall' | rg .
echo '{"status":"PASS","nginx":true,"https":true,"worker_crash_restart":true,"service_restart":true,"redis_outage":true,"database_outage":true,"host_reboot":false,"certificate":"self-signed isolated CI fixture"}' > "$root/output/pr1-linux-server.json"
