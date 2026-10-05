# V2-10 — Kesiapan Operasional Pra Go-live (Non-fungsional)

**PRD v2:** 7.2 (backup, performa, observabilitas, keamanan) · **Status:** backup/restore, uji beban pencarian, observabilitas, dan audit
keamanan selesai; perintah enkripsi ulang saat rotasi `APP_KEY` tersedia. Belum (butuh keputusan/infrastruktur): mode offline (PRD 11.4),
penyimpanan berkas S3/MinIO + pemindaian malware, review keamanan formal oleh tim (gerbang Fase 0).

## Backup & restore (RPO ≤ 24 jam, RTO ≤ 4 jam)

- `backend/docker/backup/backup.sh [tujuan]` — `pg_dump -Fc` database + arsip volume berkas terenkripsi + `SHA256SUMS`, langsung diuji keterbacaannya
  (`pg_restore --list`, `tar -t`), retensi `RETENSI_HARI` (30). Folder berkas belum ada → arsip kosong + peringatan.
  Cron contoh: `15 1 * * * /opt/vertiqo/backend/docker/backup/backup.sh /backup/vertiqo`.
- `backend/docker/backup/restore.sh <db.dump> [berkas.tar.gz]` — verifikasi checksum, `pg_restore --clean --if-exists --no-owner` ke
  `TARGET_DB` (default DB produksi; isi nama lain untuk uji restore kuartalan), pulihkan berkas, lalu `php artisan migrate --force`.
- Variabel: `DB_CONTAINER` (eklinik-db), `APP_CONTAINER` (eklinik), `DB_USERNAME`, `DB_DATABASE`, `BERKAS_PATH`.
- **`APP_KEY` wajib disimpan terpisah** (brankas/secret manager). Tanpa kunci yang sama, foto, tanda tangan & secret 2FA di backup tidak bisa dibuka.

## Rotasi `APP_KEY`

Nilai terenkripsi (cast `encrypted`: tanda tangan consent `informed_consents`/`persetujuan_datas`/`persetujuan_fotos`, secret & kode 2FA
`users`; serta isi berkas di disk `berkas`) terikat ke `APP_KEY`. Alur rotasi tanpa kehilangan data:

1. Simpan kunci baru di `APP_KEY`, pindahkan kunci lama ke `APP_PREVIOUS_KEYS` (dekripsi otomatis mencoba kunci lama — fitur bawaan Laravel).
2. `php artisan eklinik:enkripsi-ulang` — dekripsi tiap nilai lalu enkripsi ulang dengan kunci baru (idempoten & lossless; `--dry` untuk menghitung saja). Baris yang gagal didekripsi dilewati dan dilaporkan, tidak menulis data rusak.
3. Setelah laporan `gagal 0`, kunci lama boleh dihapus dari `APP_PREVIOUS_KEYS`.
- **Uji 1 Okt 2026** (PostgreSQL 17): backup DB 100.025 pasien → 2,4 MB dalam 0,6 detik; restore ke database terpisah 1,1 detik; jumlah baris
  pasiens/users/obats/stok_batches/tindakans/audit_logs/migrations identik.

## Performa pencarian pasien (target < 1 detik pada 100.000 pasien)

- `php artisan eklinik:data-uji --pasien=100000` menambah pasien sintetis (No. RM berawalan `U`; ditolak di production).
- Pengukuran `PasienController@index` (PostgreSQL 17, 100.025 pasien, median 5 kali): daftar tanpa filter 12 ms; nama umum "siti" (6.250 hasil)
  93 ms; nama spesifik 98 ms; No. RM prefix 91 ms; NIK prefix 102 ms; autocomplete `simple=1` 48 ms; tanpa hasil 43 ms. **Target terpenuhi tanpa
  indeks tambahan.** Bila data > 500 ribu pasien, pertimbangkan indeks GIN `pg_trgm` pada `pasiens.nama` (khusus PostgreSQL).

## Observabilitas

- Detak scheduler tiap menit (`eklinik.scheduler.detak` di cache). Administrasi → Integrasi → **Sistem**: status scheduler (sehat bila ≤ 5 menit),
  antrean job (menunggu, dijeda, gagal), daftar job gagal (nama job & baris pertama galat saja — payload tidak ditampilkan karena bisa memuat
  data pasien), coba ulang (`queue:retry`) & hapus. API `GET /api/sistem/status`, `GET/POST/DELETE /api/sistem/job-gagal…` (izin `integrasi.kelola`).
- SATUSEHAT & WhatsApp punya layar antrean/galat sendiri ([V2-08](V2-08-satusehat.md), [V2-09](V2-09-whatsapp.md)).

## Keamanan (tinjauan 1 Okt 2026)

- `composer audit` dan `npm audit --omit=dev`: tidak ada kerentanan yang diketahui.
- Route tanpa login hanya: `GET /info`, `POST /login` (10/menit), `POST /login/2fa` (6/menit), `GET /berkas/{uuid}/unduh` (URL bertanda tangan),
  `GET|POST /webhook/whatsapp` (verify token / HMAC-SHA256 + throttle). Semua route lain di bawah `auth:sanctum` + izin.
- Diperbaiki: **CSV/formula injection** pada ekspor laporan (sel teks diawali `= + - @` diberi awalan `'`).
- Persetujuan diskon atasan dibatasi 5 percobaan/menit; rahasia integrasi hanya di `.env`; log WhatsApp menyamarkan nomor.
- Tidak ada `v-html` di frontend (token masih di `localStorage` — trade-off yang sudah tercatat di F0-04).

## Belum dikerjakan

- **Mode offline** untuk registrasi & kasir (PRD 7.2): menunggu keputusan PWA vs prosedur manual (PRD v2 11.4).
- Penyimpanan berkas di object storage terenkripsi & pemindaian malware unggahan (butuh pilihan provider).
- Uji beban konkuren (50 cabang / 500 pengguna aktif) & pemantauan uptime eksternal.
