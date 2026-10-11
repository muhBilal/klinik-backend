#!/bin/sh
# Pulihkan Lefaklinik dari backup (PRD v2 7.2: RTO ≤ 4 jam; uji restore tiap kuartal).
#   docker/backup/restore.sh <db-YYYYmmdd-HHMMSS.dump> [berkas-YYYYmmdd-HHMMSS.tar.gz]
# Variabel sama dengan backup.sh, plus TARGET_DB (default = DB_DATABASE) untuk memulihkan ke database lain saat uji restore.
# Database tujuan dibersihkan (--clean). Hentikan container app lebih dulu saat memulihkan produksi.
set -eu

DUMP=${1:?Pakai: restore.sh <file.dump> [berkas.tar.gz]}
ARSIP=${2:-}
DB_CONTAINER=${DB_CONTAINER:-eklinik-db}
APP_CONTAINER=${APP_CONTAINER:-eklinik}
DB_USERNAME=${DB_USERNAME:-eklinik}
TARGET_DB=${TARGET_DB:-${DB_DATABASE:-eklinik}}
BERKAS_PATH=${BERKAS_PATH:-/var/www/html/storage/app/berkas}

DIR=$(dirname "$DUMP")
STAMP=$(basename "$DUMP" .dump | sed 's/^db-//')
if [ -f "$DIR/SHA256SUMS-$STAMP" ]; then
  (cd "$DIR" && sha256sum -c "SHA256SUMS-$STAMP" --ignore-missing)
fi

echo "[$(date -Is)] Pulihkan database ke $TARGET_DB"
docker exec "$DB_CONTAINER" psql -U "$DB_USERNAME" -d postgres -tc "SELECT 1 FROM pg_database WHERE datname = '$TARGET_DB'" | grep -q 1 \
  || docker exec "$DB_CONTAINER" createdb -U "$DB_USERNAME" "$TARGET_DB"
docker exec -i "$DB_CONTAINER" pg_restore -U "$DB_USERNAME" -d "$TARGET_DB" --clean --if-exists --no-owner < "$DUMP"

if [ -n "$ARSIP" ]; then
  echo "[$(date -Is)] Pulihkan berkas klinis ke $BERKAS_PATH di $APP_CONTAINER"
  docker exec -i "$APP_CONTAINER" tar -C "$(dirname "$BERKAS_PATH")" -xzf - < "$ARSIP"
fi

echo "[$(date -Is)] Selesai. Pastikan APP_KEY sama dengan saat backup, lalu jalankan: docker exec $APP_CONTAINER php artisan migrate --force"
