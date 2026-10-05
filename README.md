# Vertiqo — Backend (REST API)

Backend sistem informasi klinik: Laravel 13 REST API, autentikasi token Sanctum, PostgreSQL 17.
Frontend (Vue 3 SPA) ada di repo terpisah: [klinik-frontend](https://github.com/muhBilal/klinik-frontend).

Semua konfigurasi Docker ada di repo ini — tidak perlu memasang PHP/Composer/Node di komputer.

## Struktur folder yang diharapkan

Clone kedua repo ke folder induk yang sama:

```
vertiqo/        (folder: eklinik)
├── backend/    ← repo ini (klinik-backend), berisi semua file Docker
└── frontend/   ← repo klinik-frontend
```

```bash
git clone https://github.com/muhBilal/klinik-backend.git backend
git clone https://github.com/muhBilal/klinik-frontend.git frontend
```

## Menjalankan semuanya (1 perintah)

```bash
cd backend
cp .env.example .env
# isi APP_KEY, contoh:
docker run --rm php:8.4-cli php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"
docker compose up -d --build
```

Buka **http://localhost:8000** — frontend dan API (`/api`) disajikan dari satu container.

| Container | Isi |
|-----------|-----|
| `eklinik` | Nginx + PHP-FPM (API Laravel) + hasil build frontend, dijalankan supervisor. Migrasi otomatis saat start. |
| `eklinik-db` | PostgreSQL 17, data di volume `pgdata`, port `5432` |

- Data demo (akun, poli, obat, ICD-10) otomatis dimasukkan bila database kosong (`SEED_DEMO=true`).
- Setelah mengubah kode backend/frontend: `docker compose up -d --build`.
- Port diubah lewat `APP_PORT` / `DB_FORWARD_PORT` di `.env`.

### Akun demo (password `password`)

`admin@eklinik.test`, `pendaftaran@eklinik.test`, `perawat@eklinik.test`, `dokter@eklinik.test`,
`apoteker@eklinik.test`, `kasir@eklinik.test`

## Mode development

Untuk mengembangkan API (kode di-mount, dev dependencies & test tersedia):

```bash
docker compose -f docker-compose.dev.yml up -d --build
docker compose -f docker-compose.dev.yml exec app composer install
docker compose -f docker-compose.dev.yml exec app php artisan migrate --seed
docker compose -f docker-compose.dev.yml exec app php artisan test
docker compose -f docker-compose.dev.yml exec app vendor/bin/pint
```

API di http://localhost:8000/api; frontend dijalankan terpisah dengan `npm run dev` (http://localhost:5173).
Jangan jalankan bersamaan dengan `docker-compose.yml` (port sama) — hentikan salah satu dengan `docker compose [-f ...] down`.

## File Docker

```
docker-compose.yml          stack lengkap (app + db)
docker-compose.dev.yml      stack development API (php-fpm + nginx + db, kode di-mount)
docker/app/                 image gabungan: Dockerfile, Dockerfile.dockerignore, nginx.conf, supervisord.conf, entrypoint.sh, php.ini
docker/php/, docker/nginx/  image & config untuk mode development
```

## Modul

Auth (token + idle timeout + 2FA TOTP) · Peran & izin (RBAC) · Multi-cabang · Pasien & pendaftaran (antrian per cabang/poli) ·
Pemeriksaan (SOAP, ICD-10, tindakan, resep, lampiran klinis terenkripsi) · Kasir · Farmasi & kartu stok · Master data ·
Pengaturan klinik · Audit log · Queue & scheduler. Progres PRD: [AI-Context/07-roadmap-progress.md](AI-Context/07-roadmap-progress.md).

## Dokumentasi

Arsitektur, skema database, aturan bisnis, dan referensi API ada di [`AI-Context/`](AI-Context/README.md).
