# 01 — Overview

## Tentang aplikasi

E-Klinik adalah sistem informasi klinik rawat jalan: pendaftaran pasien & antrian poli, rekam medis
(tanda vital, SOAP, diagnosa ICD-10, tindakan), resep elektronik, farmasi (stok obat), dan kasir.

## Stack

| Komponen | Versi / Keterangan |
|----------|--------------------|
| Framework | Laravel 13 (`laravel/framework ^13`) |
| PHP | 8.4 (di Docker: `docker/app/Dockerfile` untuk stack lengkap, `docker/php/Dockerfile` untuk dev) |
| Database | PostgreSQL 17 (container `db`) |
| Auth | Laravel Sanctum — personal access token (Bearer) |
| Test | PHPUnit, SQLite in-memory (lihat `phpunit.xml`) |
| Formatter | Laravel Pint |
| Timezone | `Asia/Jakarta` (`APP_TIMEZONE`), locale `id` |

## Layout repository

Frontend ada di repo terpisah `klinik-frontend`, tetapi **semua file Docker ada di repo ini**.
Kedua repo di-clone sejajar dalam satu folder induk; folder induk hanya berisi `backend/` dan `frontend/`.

```
e-klinik/
├── backend/                        ← repo ini
│   ├── docker-compose.yml          STACK LENGKAP: app (nginx+php-fpm+build frontend, port 8000) + db
│   ├── docker-compose.dev.yml      STACK DEV API: app (php-fpm, kode di-mount) + nginx + db
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

- Container `eklinik`: supervisor menjalankan php-fpm + nginx. Nginx menyajikan build Vue di `/` (SPA fallback)
  dan meneruskan `/api/*` + `/up` ke Laravel. Frontend dibuild dengan `VITE_API_URL=/api` (satu origin, tanpa CORS).
- `entrypoint.sh`: cek `APP_KEY` → `config:cache` + `route:cache` → `migrate --force` → `db:seed` bila `SEED_DEMO=true`
  (seeder melewati dirinya sendiri bila tabel users sudah berisi).
- Image produksi memakai `composer install --no-dev`: **tidak ada Faker/PHPUnit/Pint** di container ini.
  Seeder melewati 25 pasien acak bila Faker tidak tersedia.
- `APP_ENV=production`, `APP_DEBUG=false` di-hardcode di compose; `APP_KEY`, `APP_URL`, `DB_*` diambil dari `backend/.env`.
- Kode tidak di-mount → setiap perubahan kode butuh `docker compose up -d --build`.

## Menjalankan mode development (dari `backend/`)

```bash
docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml exec app composer install
docker compose -f docker-compose.dev.yml exec app php artisan key:generate   # sekali saja
docker compose -f docker-compose.dev.yml exec app php artisan migrate --seed
```

API di `http://localhost:8000/api`; frontend terpisah dengan `npm run dev` (port 5173). Kedua stack memakai port
8000/5432 — jalankan salah satu saja.

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

## Akun demo (seeder)

Password semua `password`: `admin@`, `pendaftaran@`, `perawat@`, `dokter@` (Poli Umum), `dokter.gigi@`,
`dokter.kia@`, `apoteker@`, `kasir@` — domain `eklinik.test`.

Seeder juga membuat 3 poli (UMUM, GIGI, KIA), 27 kode ICD-10, 13 tindakan, 20 obat (dengan stok awal
tercatat di kartu stok), dan 25 pasien acak.
