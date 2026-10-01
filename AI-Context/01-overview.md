# 01 — Overview

## Tentang aplikasi

**Lefaklinik** (sebelumnya bernama E-Klinik) adalah sistem informasi klinik rawat jalan yang sedang dikembangkan menjadi sistem manajemen **klinik estetika**
(dermatologi, estetika medis, gigi) sesuai PRD di folder ini. Fitur saat ini: pendaftaran pasien & antrian poli, booking,
rekam medis estetika (tanda vital, SOAP dengan template, diagnosa ICD-10, tindakan ICD-9-CM + petugas, catatan tindakan: face
chart injeksi & parameter laser, informed consent bertanda tangan, tanda tangan RME + addendum, akses terbatas kasus IMS,
lampiran klinis terenkripsi, foto klinis before-after), kedokteran gigi (odontogram FDI, rencana perawatan berfase, tindakan &
tagihan per gigi), katalog treatment, paket multi-sesi & voucher/promo, komisi & jasa medis, data klinis pasien & persetujuan UU PDP (kategori, durasi, harga per cabang, BHP standar, consent wajib), resep
elektronik, farmasi & inventori batch FEFO, kasir (split payment, shift, void/refund), multi-cabang, peran & izin dinamis,
audit log, 2FA, dan pengaturan klinik. Status per fase: [07-roadmap-progress.md](07-roadmap-progress.md).

**Model tenant:** satu instalasi (satu database) = satu organisasi klinik dengan banyak cabang. Tidak ada multi-tenant
lintas organisasi dalam satu database.

## Stack

| Komponen | Versi / Keterangan |
|----------|--------------------|
| Framework | Laravel 13 (`laravel/framework ^13`) |
| PHP | 8.4 (di Docker: `docker/app/Dockerfile` untuk stack lengkap, `docker/php/Dockerfile` untuk dev) |
| Database | PostgreSQL 17 (container `db`) |
| Auth | Laravel Sanctum — personal access token (Bearer), maks. 12 jam + idle timeout; 2FA TOTP opsional |
| Queue & scheduler | `QUEUE_CONNECTION=database`; worker & `schedule:work` dijalankan supervisor (image produksi) / service `queue` & `scheduler` (dev) |
| Berkas | Disk `berkas` (`storage/app/berkas`), isi terenkripsi dengan `APP_KEY` |
| Test | PHPUnit, SQLite in-memory (lihat `phpunit.xml`) |
| Formatter | Laravel Pint |
| Timezone | `Asia/Jakarta` (`APP_TIMEZONE`), locale `id` |

## Layout repository

Frontend ada di repo terpisah `klinik-frontend`, tetapi **semua file Docker ada di repo ini**.
Kedua repo di-clone sejajar dalam satu folder induk; folder induk hanya berisi `backend/` dan `frontend/`.

```
lefaklinik/   (folder: eklinik)
├── backend/                        ← repo ini
│   ├── docker-compose.yml          STACK LENGKAP: app (nginx+php-fpm+queue+scheduler+build frontend, port 8000) + db, volume `berkas`
│   ├── docker-compose.dev.yml      STACK DEV API: app (php-fpm, kode di-mount) + nginx + db (+ queue, scheduler: profile `worker`)
│   ├── docker/app/                 Dockerfile gabungan (context = folder induk), Dockerfile.dockerignore,
│   │                               nginx.conf, supervisord.conf, entrypoint.sh, php.ini
│   ├── docker/php/, docker/nginx/  image & config mode dev
│   ├── app/, routes/, database/, tests/, ...
│   └── AI-Context/
└── frontend/                       repo klinik-frontend (tidak punya file Docker sendiri)
```

## Menjalankan stack lengkap (dari `backend/`)

```bash
cp .env.example .env            # isi APP_KEY
docker compose up -d --build    # buka http://localhost:8000
```

- Container `eklinik`: supervisor menjalankan php-fpm + nginx + `queue:work` + `schedule:work`. Nginx menyajikan build Vue di `/` (SPA fallback)
  dan meneruskan `/api/*` + `/up` ke Laravel. Frontend dibuild dengan `VITE_API_URL=/api` (satu origin, tanpa CORS).
- `entrypoint.sh`: cek `APP_KEY` → `config:cache` + `route:cache` → `migrate --force` → `db:seed` bila `SEED_DEMO=true`
  (seeder melewati dirinya sendiri bila tabel users sudah berisi).
- Image produksi memakai `composer install --no-dev`: **tidak ada Faker/PHPUnit/Pint** di container ini.
  Seeder melewati 25 pasien acak bila Faker tidak tersedia.
- `APP_ENV=production`, `APP_DEBUG=false` di-hardcode di compose; `APP_KEY`, `APP_URL`, `DB_*` diambil dari `backend/.env`.
- Kode tidak di-mount → setiap perubahan kode butuh `docker compose up -d --build`.
- Berkas klinis tersimpan di volume Docker `berkas` (`/var/www/html/storage/app/berkas`). **Cadangkan volume ini bersama
  `APP_KEY`** — tanpa `APP_KEY` yang sama, berkas tidak bisa didekripsi.
- Upgrade dari versi sebelum Fase 0: migration `2026_09_30_100003` otomatis membuat cabang `UTAMA` "Klinik Utama",
  memindahkan kunjungan/resep/tagihan lama & staf (selain admin) ke cabang itu, dan menyesuaikan counter antrian hari ini.

## Menjalankan mode development (dari `backend/`)

```bash
docker compose -f docker-compose.dev.yml up -d --build                    # app, nginx, db
docker compose -f docker-compose.dev.yml --profile worker up -d           # + queue & scheduler (opsional)
docker compose -f docker-compose.dev.yml exec app composer install
docker compose -f docker-compose.dev.yml exec app php artisan key:generate   # sekali saja
docker compose -f docker-compose.dev.yml exec app php artisan migrate --seed
```

API di `http://localhost:8000/api`; frontend terpisah dengan `npm run dev` (port 5173). Kedua stack memakai port
8000/5432 — jalankan salah satu saja. `queue` & `scheduler` opsional (profile `worker`) karena bind mount di Windows lambat;
lihat [modul/F0-07-queue-scheduler.md](modul/F0-07-queue-scheduler.md). Service `queue` bisa gagal sekali sebelum `migrate`
pertama (tabel belum ada) lalu restart otomatis.

Menjalankan test **tanpa** menyalakan stack dev (tidak bentrok dengan stack lengkap yang sedang jalan; test memakai SQLite):

```bash
docker compose -f docker-compose.dev.yml run --rm --no-deps app php artisan test
docker compose -f docker-compose.dev.yml run --rm --no-deps app vendor/bin/pint
```

Stack dev terisolasi (mis. untuk uji end-to-end saat stack lengkap memakai port 8000). Sejak `docker-compose.dev.yml` memproxy API
lewat service `frontend` (nginx tidak membuka port ke host), nyalakan tanpa frontend lalu buka API lewat nginx sementara:
`DB_FORWARD_PORT=5433 docker compose -p eklinik-uji -f docker-compose.dev.yml up -d --no-deps db` → `... up -d --no-deps app nginx` →
`docker run -d --rm --name eklinik-uji-web --network eklinik-uji_default -p 8010:80 -v <backend>:/var/www/html
-v <backend>/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro nginx:alpine` (Git Bash: `MSYS_NO_PATHCONV=1`), Vite lokal
`VITE_API_URL=http://localhost:8010/api`. Bongkar: `docker stop eklinik-uji-web` lalu `... down -v`.

Perintah sehari-hari (mode dev; singkat `DC="docker compose -f docker-compose.dev.yml"`):

```bash
$DC exec app php artisan test
$DC exec app vendor/bin/pint
$DC exec app php artisan migrate:fresh --seed     # reset data demo
$DC exec app php artisan route:list --path=api
```

## Environment penting (`.env`)

| Key | Nilai default | Catatan |
|-----|---------------|---------|
| `DB_CONNECTION` | `pgsql` | |
| `DB_HOST` | `db` | nama service Docker, bukan `127.0.0.1` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `eklinik` / `eklinik` / `secret` | |
| `APP_TIMEZONE` | `Asia/Jakarta` | memengaruhi `today()` untuk antrian harian |
| `FRONTEND_URL` | `http://localhost:5173,http://localhost:3000` | dipakai CORS (`config/cors.php`), boleh dipisah koma |
| `SANCTUM_EXPIRATION` | `720` | masa berlaku token maksimal (menit) sejak login. Batas idle diatur di Pengaturan (`keamanan.idle_timeout_menit`) |
| `BERKAS_MAKS_KB` / `BERKAS_TAUTAN_MENIT` | `10240` / `5` | ukuran unggahan maksimal & masa berlaku tautan unduh berkas |
| `BERKAS_ROOT` | `storage/app/berkas` | lokasi disk berkas terenkripsi (opsional) |
| `TWO_FACTOR_ISSUER` | `APP_NAME` | nama penerbit di aplikasi authenticator |
| `APP_KEY` | — | juga kunci enkripsi berkas klinis & secret 2FA: **jangan diganti** tanpa rotasi (`APP_PREVIOUS_KEYS`) |

## Akun demo (seeder)

Password semua `password`: `admin@` (lintas cabang), `pendaftaran@`, `perawat@`, `dokter@` (dr. Andi, Poli Estetika Medis),
`dokter.kulit@` (dr. Lestari, Sp.D.V.E, Poli Kulit & Kelamin), `dokter.gigi@` (drg. Maya, Poli Gigi), `apoteker@`, `kasir@`,
`terapis@`, `manajer@` — domain `eklinik.test` (peran marketing tidak punya akun demo). Semua staf di cabang `UTAMA`.

Seeder juga membuat 1 cabang (UTAMA "Klinik Utama"), **3 poli sesuai cakupan PRD** (ESTETIKA "Poli Estetika Medis", KULIT "Poli Kulit &
Kelamin", GIGI "Poli Gigi & Estetika Gigi", masing-masing dengan `spesialisasi`; Poli Umum & KIA dihapus dari data demo sejak 1 Okt 2026),
54 kode ICD-10 (termasuk kulit, estetika, dan 9 kode IMS/HIV sensitif), 6 kategori treatment, 22 treatment/tindakan (treatment estetika
dengan BHP standar, tindakan gigi per gigi, kode ICD-9-CM default, bentuk catatan & consent wajib), 3 paket & 2 kode promo, 10 template SOAP, 5 naskah informed consent, 24 obat & bahan (dengan
stok awal tercatat di kartu stok), dan 25 pasien acak. Peran (9 peran bawaan) dan 62 kode ICD-9-CM dibuat oleh migration,
bukan seeder.
