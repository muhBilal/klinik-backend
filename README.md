# E-Klinik — Backend (REST API)

Backend sistem informasi klinik: Laravel 13 REST API, autentikasi token Sanctum, PostgreSQL 17.
Frontend (Vue 3 SPA) ada di repo terpisah: [klinik-frontend](https://github.com/muhBilal/klinik-frontend).

PHP 8.4, Nginx, dan PostgreSQL berjalan di Docker — tidak perlu memasang PHP/Composer di komputer.

## Menjalankan

Prasyarat: Docker Desktop.

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate --seed
```

- API: http://localhost:8000/api
- PostgreSQL: `localhost:5432` — db/user `eklinik`, password `secret`
- Port dapat diubah dengan variabel `APP_PORT` / `DB_FORWARD_PORT`.
- Origin frontend untuk CORS diatur di `FRONTEND_URL` (default `http://localhost:5173`).

### Akun demo (password `password`)

`admin@eklinik.test`, `pendaftaran@eklinik.test`, `perawat@eklinik.test`, `dokter@eklinik.test`,
`apoteker@eklinik.test`, `kasir@eklinik.test`

## Modul

- Auth & role: admin, pendaftaran, perawat, dokter, apoteker, kasir
- Pasien (No. RM otomatis) & pendaftaran kunjungan dengan nomor antrian per poli
- Pemeriksaan: tanda vital, SOAP, diagnosa ICD-10, tindakan, resep
- Kasir: tagihan otomatis, diskon, pembayaran
- Farmasi: penyerahan resep (setelah lunas), stok & kartu stok
- Master: poli, tindakan, ICD-10, pengguna

## Perintah

```bash
docker compose exec app php artisan test                  # feature test alur klinik
docker compose exec app vendor/bin/pint                   # format kode
docker compose exec app php artisan migrate:fresh --seed  # reset data demo
docker compose exec app php artisan route:list --path=api
```

## Dokumentasi

Arsitektur, skema database, aturan bisnis, dan referensi API lengkap ada di [`AI-Context/`](AI-Context/README.md).
