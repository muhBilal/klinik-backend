#!/bin/sh
# Backup harian Vertiqo (PRD v2 7.2: RPO ≤ 24 jam): database PostgreSQL (pg_dump format custom) + volume berkas klinis
# terenkripsi + checksum. Jalankan dari host, mis. cron: `15 1 * * * /opt/vertiqo/backend/docker/backup/backup.sh /backup/vertiqo`
#
# Variabel (opsional): DB_CONTAINER (eklinik-db), APP_CONTAINER (eklinik), DB_USERNAME (eklinik), DB_DATABASE (eklinik),
#                      BERKAS_PATH (/var/www/html/storage/app/berkas), RETENSI_HARI (30)
# PENTING: berkas terenkripsi hanya bisa dibuka dengan APP_KEY yang sama. Simpan APP_KEY di brankas terpisah dari backup ini.
set -eu

TUJUAN=${1:-./backup}
DB_CONTAINER=${DB_CONTAINER:-eklinik-db}
APP_CONTAINER=${APP_CONTAINER:-eklinik}
DB_USERNAME=${DB_USERNAME:-eklinik}
DB_DATABASE=${DB_DATABASE:-eklinik}
BERKAS_PATH=${BERKAS_PATH:-/var/www/html/storage/app/berkas}
RETENSI_HARI=${RETENSI_HARI:-30}
STAMP=$(date +%Y%m%d-%H%M%S)

mkdir -p "$TUJUAN"
cd "$TUJUAN"

echo "[$(date -Is)] Backup database $DB_DATABASE dari $DB_CONTAINER"
docker exec "$DB_CONTAINER" pg_dump -U "$DB_USERNAME" -d "$DB_DATABASE" -Fc -Z 6 > "db-$STAMP.dump"

echo "[$(date -Is)] Backup berkas klinis ($BERKAS_PATH) dari $APP_CONTAINER"
PARENT=$(dirname "$BERKAS_PATH")
NAMA=$(basename "$BERKAS_PATH")
if docker exec "$APP_CONTAINER" test -d "$BERKAS_PATH"; then
  docker exec "$APP_CONTAINER" tar -C "$PARENT" -czf - "$NAMA" > "berkas-$STAMP.tar.gz"
else
  echo "PERINGATAN: $BERKAS_PATH belum ada (belum ada unggahan); arsip berkas kosong dibuat."
  docker exec "$APP_CONTAINER" sh -c "mkdir -p /tmp/kosong/$NAMA && tar -C /tmp/kosong -czf - $NAMA" > "berkas-$STAMP.tar.gz"
fi

sha256sum "db-$STAMP.dump" "berkas-$STAMP.tar.gz" > "SHA256SUMS-$STAMP"

# Uji keterbacaan arsip segera (backup rusak lebih berbahaya daripada tidak ada backup)
docker exec -i "$DB_CONTAINER" pg_restore --list < "db-$STAMP.dump" > /dev/null
tar -tzf "berkas-$STAMP.tar.gz" > /dev/null

# Retensi
find . -maxdepth 1 \( -name 'db-*.dump' -o -name 'berkas-*.tar.gz' -o -name 'SHA256SUMS-*' \) -mtime +"$RETENSI_HARI" -delete

echo "[$(date -Is)] Selesai: $TUJUAN/db-$STAMP.dump, $TUJUAN/berkas-$STAMP.tar.gz"
