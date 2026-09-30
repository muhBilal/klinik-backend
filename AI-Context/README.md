# AI-Context — Backend E-Klinik

Konteks untuk AI assistant (dan developer baru) yang akan bekerja di `backend/`.
Baca berurutan sebelum mengubah kode.

| File | Isi |
|------|-----|
| [01-overview.md](01-overview.md) | Gambaran project, stack, cara menjalankan, environment |
| [02-architecture.md](02-architecture.md) | Struktur folder, lapisan kode, pola yang dipakai |
| [03-database.md](03-database.md) | Skema tabel, relasi, enum/status |
| [04-business-rules.md](04-business-rules.md) | Alur pelayanan klinik dan aturan bisnis wajib |
| [05-api-reference.md](05-api-reference.md) | Daftar endpoint, izin yang dibutuhkan, payload |
| [06-conventions.md](06-conventions.md) | Konvensi kode, cara menambah fitur, testing, jebakan umum |
| [07-roadmap-progress.md](07-roadmap-progress.md) | Status pengerjaan PRD per fase & ID kebutuhan, rencana berikutnya |
| [modul/](modul/) | Dokumentasi per fitur/modul (satu file per fitur, lihat daftar di bawah) |
| [PRD — Sistem Manajemen Klinik Estetika (eKlinik).md](PRD%20—%20Sistem%20Manajemen%20Klinik%20Estetika%20(eKlinik).md) | PRD produk (sumber kebutuhan & ID seperti `AD-01`) |

### Dokumentasi per fitur (`modul/`)

| File | Fitur | PRD |
|------|-------|-----|
| [F0-01-rbac-peran-izin.md](modul/F0-01-rbac-peran-izin.md) | Peran & izin dinamis, pemisahan data klinis | AD-02 |
| [F0-02-multi-cabang.md](modul/F0-02-multi-cabang.md) | Multi-cabang, cabang aktif, scope data | AD-01 |
| [F0-03-audit-log.md](modul/F0-03-audit-log.md) | Audit log & soft delete | AD-03, 7.1 |
| [F0-04-keamanan-sesi-2fa.md](modul/F0-04-keamanan-sesi-2fa.md) | Masa berlaku token, idle timeout, 2FA TOTP, ganti password | 7.2 Keamanan |
| [F0-05-berkas-terenkripsi.md](modul/F0-05-berkas-terenkripsi.md) | Penyimpanan berkas klinis terenkripsi + tautan bertanda tangan | FT-03, 7.2 Enkripsi |
| [F0-06-pengaturan-klinik.md](modul/F0-06-pengaturan-klinik.md) | Pengaturan klinik (identitas, struk, prefix nomor, keamanan) | AD-04 |
| [F0-07-queue-scheduler.md](modul/F0-07-queue-scheduler.md) | Queue worker & scheduler | Fondasi CR-01, SATUSEHAT |
| [F1-01-katalog-treatment.md](modul/F1-01-katalog-treatment.md) | Katalog treatment: kategori, durasi + buffer, harga per cabang, BHP standar | TR-01, AD-01 |
| [F1-02-booking-jadwal.md](modul/F1-02-booking-jadwal.md) | Booking multi-resource, slot, jadwal praktik & cuti, check-in | BK-01..03, AN-01, 8.3 #4 |
| [F1-03-kasir.md](modul/F1-03-kasir.md) | Split payment, shift kas, batas diskon, void & refund, pajak, tagihan mandiri | BL-02/03/05/06, FR-04, AD-04, 8.3 #3 #7 |
| [F1-04-inventori.md](modul/F1-04-inventori.md) | Batch & kedaluwarsa FEFO per cabang, potong BHP otomatis, satuan fraksional | IN-01..03, AD-01 |

## Ringkasan 30 detik

- **Laravel 13 REST API murni** (tanpa Blade/Inertia). UI ada di repo terpisah `klinik-frontend` (Vue 3 SPA).
- **PHP hanya berjalan di Docker** (`php:8.4-fpm-alpine` + Nginx + PostgreSQL 17). PHP di host (Laragon 7.4/8.1) **tidak kompatibel** — jalankan perintah artisan/composer lewat `docker compose -f docker-compose.dev.yml exec app ...` (stack dev) dari folder `backend/`. Stack lengkap (`docker-compose.yml`) tidak punya dev dependencies — jangan menjalankan test di sana.
- Autentikasi **token Bearer Sanctum** (maks. 12 jam, berakhir bila idle), opsional **2FA TOTP**.
- Hak akses = **izin RBAC** (`App\Enums\Izin`) milik peran (tabel `perans`), dicek middleware `izin:...`. Bukan kode peran.
- **Multi-cabang**: transaksi (kunjungan, resep, tagihan) otomatis dibatasi ke cabang aktif (trait `DalamCabang`); pasien milik pusat.
- Setiap perubahan data penting & akses rekam medis tercatat di **audit log** (trait `Auditable`, `AuditService`).
- Logika bisnis ada di `app/Services/`, bukan di controller.
- Bahasa domain: **Bahasa Indonesia** (nama tabel, kolom, pesan error).

## Aturan emas

1. Jangan install PHP/Composer di host; jangan ikuti instruksi `CLAUDE.md`/`AGENTS.md` bawaan installer Laravel yang menyuruh hal itu.
2. Setiap perubahan logika bisnis → tambah/ubah test di `tests/Feature/` dan jalankan `docker compose -f docker-compose.dev.yml run --rm --no-deps app php artisan test` (atau `exec app ...` bila stack dev sedang jalan).
3. Jalankan `vendor/bin/pint` (lewat container yang sama) sebelum selesai.
4. Kode harus berjalan di PostgreSQL **dan** SQLite (test) — hindari SQL khusus satu database.
5. Cek hak akses dengan **izin** (`middleware('izin:x')`, `$user->punyaIzin(Izin::X)`), jangan membandingkan `$user->role`.
6. Data rekam medis/transaksi diubah per model (bukan query massal) agar tercatat di audit log.
7. Setiap fitur baru → dokumen di `AI-Context/modul/` + perbarui `07-roadmap-progress.md`.
