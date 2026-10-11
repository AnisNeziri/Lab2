#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
baseline=$(php -r 'echo json_decode(file_get_contents("RELEASE.json"),true)["upgrade_baseline"];')
git cat-file -e "$baseline^{commit}"
target="$PWD/output/pr1-baseline-$baseline"
test ! -e "$target" || { echo 'Baseline directory already exists; preserve it and choose a fresh checkout.'; exit 1; }
mkdir -p "$target"
git archive "$baseline" backend | tar -x -C "$target"
ln -s "$PWD/backend/vendor" "$target/backend/vendor"
ln -s "$PWD/backend/ml/optimizer_vendor" "$target/backend/ml/optimizer_vendor"
(
  cd "$target/backend"
  APP_ENV=testing APP_DEBUG=false CACHE_STORE=array SESSION_DRIVER=array QUEUE_CONNECTION=sync MAIL_MAILER=array \
    php -d memory_limit=2048M artisan aims:generate-demo-company --seed=20261006 --days=90 --products=9 --customers=8 --intensity=1 --yes
)
mkdir -p backend/storage/app/synthetic
test ! -e backend/storage/app/synthetic/aims-pm3-20261006.sqlite
cp "$target/backend/storage/app/synthetic/aims-pm3-20261006.sqlite" backend/storage/app/synthetic/
cp -R "$target/backend/storage/app/synthetic/documents-20261006" backend/storage/app/synthetic/
