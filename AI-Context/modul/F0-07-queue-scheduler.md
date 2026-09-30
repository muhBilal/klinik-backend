# F0-07 — Queue Worker & Scheduler

**PRD:** fondasi untuk CR-01 (reminder WhatsApp), BK-06 (reminder booking), integrasi SATUSEHAT (kirim & kirim ulang),
temuan gap 8.3 #6 · **Fase:** 0 · **Status:** infrastruktur siap; belum ada job fitur.

## Proses

| Lingkungan | Queue worker | Scheduler |
|------------|--------------|-----------|
| Stack lengkap (`docker-compose.yml`) | supervisor `[program:queue]`: `php artisan queue:work --sleep=3 --tries=3 --backoff=30 --max-time=3600` (user www-data) | supervisor `[program:scheduler]`: `php artisan schedule:work` |
| Stack dev (`docker-compose.dev.yml`) | service `queue` — **opsional**, profile `worker` | service `scheduler` — **opsional**, profile `worker` |
| Test | `QUEUE_CONNECTION=sync` (phpunit.xml) | — |

Di dev, `queue` & `scheduler` tidak ikut `up` biasa karena bind mount kode di Docker Desktop Windows lambat: tiga container
PHP pada folder yang sama (dan `schedule:work` yang memuat framework tiap menit) pernah membuat PHP-FPM tertahan di I/O
sampai request 504. Nyalakan hanya saat mengerjakan job/jadwal:
`docker compose -f docker-compose.dev.yml --profile worker up -d`. Tanpa worker, job `dispatch()` menumpuk di tabel `jobs`
sampai worker dinyalakan (atau set `QUEUE_CONNECTION=sync` di `.env` dev).

Konfigurasi: `QUEUE_CONNECTION=database` (tabel `jobs`, `failed_jobs`), cache `database` (lock `onOneServer`).
`--max-time=3600` membuat worker restart berkala (kode baru & memori). Di dev, restart manual setelah mengubah kode job:
`docker compose -f docker-compose.dev.yml restart queue`.

Service `queue` dev bisa gagal sekali sebelum `migrate` pertama (tabel belum ada) lalu restart otomatis. Di image produksi
`entrypoint.sh` menjalankan migrate sebelum supervisor, jadi tidak terjadi.

## Jadwal (`routes/console.php`)

| Jadwal | Perintah |
|--------|----------|
| harian | `sanctum:prune-expired --hours=24` — hapus token kedaluwarsa |
| harian | `queue:prune-failed --hours=168` — bersihkan job gagal > 7 hari |

Cek: `php artisan schedule:list`.

## Menambah job / jadwal (pola untuk fase berikutnya)

1. `php artisan make:job KirimReminderBooking` (di container) — job harus idempoten (boleh dijalankan ulang), pakai `$tries`/`backoff`.
2. Dispatch dari service setelah transaksi DB commit: `KirimReminderBooking::dispatch($booking)->afterCommit()`.
3. Jadwal berulang → `Schedule::job(...)` / `Schedule::command(...)` di `routes/console.php` + `->onOneServer()`.
4. Jangan menaruh data rekam medis di payload job/log; kirim id saja.
5. Test dengan `Queue::fake()` / `Bus::fake()`.

## Belum dikerjakan

Monitoring antrian (Horizon tidak dipakai karena bukan Redis), notifikasi job gagal, job fitur (reminder WA, SATUSEHAT).
