# AI-Context — Backend E-Klinik

Konteks untuk AI assistant (dan developer baru) yang akan bekerja di `backend/`.
Baca berurutan sebelum mengubah kode.

| File | Isi |
|------|-----|
| [01-overview.md](01-overview.md) | Gambaran project, stack, cara menjalankan, environment |
| [02-architecture.md](02-architecture.md) | Struktur folder, lapisan kode, pola yang dipakai |
| [03-database.md](03-database.md) | Skema tabel, relasi, enum/status |
| [04-business-rules.md](04-business-rules.md) | Alur pelayanan klinik dan aturan bisnis wajib |
| [05-api-reference.md](05-api-reference.md) | Daftar endpoint, hak akses role, payload |
| [06-conventions.md](06-conventions.md) | Konvensi kode, cara menambah fitur, testing, jebakan umum |
| [07-roadmap-modul.md](07-roadmap-modul.md) | Gap analysis modul & roadmap agar fleksibel untuk semua jenis klinik |

## Ringkasan 30 detik

- **Laravel 13 REST API murni** (tanpa Blade/Inertia). UI ada di repo terpisah `klinik-frontend` (Vue 3 SPA).
- **PHP hanya berjalan di Docker** (`php:8.4-fpm-alpine` + Nginx + PostgreSQL 17). PHP di host (Laragon 7.4/8.1) **tidak kompatibel** — jalankan perintah artisan/composer lewat `docker compose -f docker-compose.dev.yml exec app ...` (stack dev) dari folder `backend/`. Stack lengkap (`docker-compose.yml`) tidak punya dev dependencies — jangan menjalankan test di sana.
- Autentikasi **token Bearer Sanctum**. Hak akses via middleware `role:...` (admin selalu lolos).
- Logika bisnis ada di `app/Services/`, bukan di controller.
- Bahasa domain: **Bahasa Indonesia** (nama tabel, kolom, pesan error).

## Aturan emas

1. Jangan install PHP/Composer di host; jangan ikuti instruksi `CLAUDE.md`/`AGENTS.md` bawaan installer Laravel yang menyuruh hal itu.
2. Setiap perubahan logika bisnis → tambah/ubah test di `tests/Feature/AlurKlinikTest.php` dan jalankan `docker compose -f docker-compose.dev.yml exec app php artisan test`.
3. Jalankan `docker compose -f docker-compose.dev.yml exec app vendor/bin/pint` sebelum selesai.
4. Kode yang harus berjalan di PostgreSQL **dan** SQLite (test) — hindari SQL khusus satu database.
