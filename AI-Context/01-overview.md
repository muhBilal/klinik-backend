# 01 — Overview

## Tentang aplikasi

E-Klinik adalah sistem informasi klinik rawat jalan: pendaftaran pasien & antrian poli, rekam medis
(tanda vital, SOAP, diagnosa ICD-10, tindakan), resep elektronik, farmasi (stok obat), dan kasir.

## Stack

| Komponen | Versi / Keterangan |
|----------|--------------------|
| Framework | Laravel 13 (`laravel/framework ^13`) |
| PHP | 8.4 (di Docker, image dibangun dari `docker/php/Dockerfile`) |
| Database | PostgreSQL 17 (container `db`) |
| Auth | Laravel Sanctum — personal access token (Bearer) |
| Test | PHPUnit, SQLite in-memory (lihat `phpunit.xml`) |
| Formatter | Laravel Pint |
| Timezone | `Asia/Jakarta` (`APP_TIMEZONE`), locale `id` |

## Layout repository

Repo ini (`klinik-backend`) berdiri sendiri; frontend ada di repo terpisah `klinik-frontend`.

```
klinik-backend/
├── docker-compose.yml      service: app (php-fpm), nginx (port 8000), db (postgres, port 5432)
├── docker/php/             Dockerfile + php.ini
├── docker/nginx/           default.conf
├── app/, routes/, database/, tests/, ...   aplikasi Laravel
└── AI-Context/             dokumen ini
```

## Menjalankan (dari root repo ini)

```bash
cp .env.example .env                                     # sekali saja
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate        # sekali saja
docker compose exec app php artisan migrate --seed
```

API tersedia di `http://localhost:8000/api`. `GET /` mengembalikan JSON info aplikasi.

Perintah sehari-hari:

```bash
docker compose exec app php artisan test
docker compose exec app vendor/bin/pint
docker compose exec app php artisan migrate:fresh --seed     # reset data demo
docker compose exec app php artisan route:list --path=api
docker compose exec app php artisan tinker
```

## Environment penting (`.env`)

| Key | Nilai default | Catatan |
|-----|---------------|---------|
| `DB_CONNECTION` | `pgsql` | |
| `DB_HOST` | `db` | nama service Docker, bukan `127.0.0.1` |
| `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | `eklinik` / `eklinik` / `secret` | |
| `APP_TIMEZONE` | `Asia/Jakarta` | memengaruhi `today()` untuk antrian harian |
| `FRONTEND_URL` | `http://localhost:5173` | dipakai CORS (`config/cors.php`), boleh dipisah koma |

## Akun demo (seeder)

Password semua `password`: `admin@`, `pendaftaran@`, `perawat@`, `dokter@` (Poli Umum), `dokter.gigi@`,
`dokter.kia@`, `apoteker@`, `kasir@` — domain `eklinik.test`.

Seeder juga membuat 3 poli (UMUM, GIGI, KIA), 27 kode ICD-10, 13 tindakan, 20 obat (dengan stok awal
tercatat di kartu stok), dan 25 pasien acak.
