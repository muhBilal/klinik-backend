# 06 — Konvensi, Cara Menambah Fitur, Jebakan

## Konvensi kode

- **Bahasa**: nama domain Bahasa Indonesia (`Pasien`, `Kunjungan`, `tagihan`, `no_rm`); istilah framework tetap Inggris
  (`index`, `store`, `casts`). Pesan error/validasi untuk user dalam Bahasa Indonesia.
- **Model**: gunakan atribut `#[Table('nama_tabel')]` dan `#[Fillable([...])]` (gaya Laravel 13), `casts()` method.
  Kolom yang dikelola sistem (mis. `obats.stok`, `pasiens.no_rm`) **tidak** dimasukkan ke Fillable untuk input user.
- **Enum**: status/pilihan tetap → `app/Enums` (backed string). Validasi dengan `Rule::enum(X::class)`, bandingkan dengan `===` terhadap case enum (bukan string).
- **Controller**: tipis. Validasi inline `$request->validate`, helper `private function validated(Request, ?Model)` bila dipakai store+update. Selalu `JsonResponse`.
- **Service**: diinject lewat constructor/method injection. Operasi multi-tabel dalam `DB::transaction`; kunci baris dengan `lockForUpdate()`; lempar `ValidationException::withMessages(['field' => 'pesan'])`.
- **Uang**: integer rupiah. Harga/tarif di-snapshot ke tabel transaksi.
- **Pencarian teks**: pakai `whereLike('kolom', "%{$q}%")` (case-insensitive, otomatis `ilike` di PostgreSQL & kompatibel SQLite). Untuk kode/nomor pakai `like` prefix.
- **OR di dalam whereHas**: selalu bungkus `->where(fn ($w) => $w->...->orWhere(...))` agar tidak keluar dari constraint relasi.
- **Formatting**: Laravel Pint (preset default). Jalankan sebelum selesai.

## Menambah fitur baru (checklist)

1. Migration baru di `database/migrations` (jangan ubah migration lama yang sudah dipakai data nyata).
2. Model dengan `#[Table]`, `#[Fillable]`, `casts()`, relasi. Enum bila ada status.
3. Logika bisnis di `app/Services/...` bila menyentuh >1 tabel atau punya aturan.
4. Controller di `app/Http/Controllers/Api`, route di `routes/api.php` dalam grup `role:` yang tepat.
5. Tambah pesan/atribut ke `lang/id/validation.php` bila memakai rule/field baru.
6. Seeder bila butuh data master.
7. Feature test di `tests/Feature` (pola: `Sanctum::actingAs($user)`, seed `DatabaseSeeder`, assert status + JSON path).
8. Update dokumentasi: `AI-Context/03`, `04`, `05` dan `AI-Context` di repo `klinik-frontend` bila kontrak API berubah.
9. `docker compose exec app php artisan test` dan `vendor/bin/pint`.

## Testing

- `phpunit.xml` memakai **SQLite in-memory**, bukan PostgreSQL. Maka:
  - Hindari fungsi SQL khusus PostgreSQL (`ilike` manual, `jsonb`, `ANY(...)`) — pakai query builder.
  - `lockForUpdate` diabaikan SQLite (tetap aman ditulis).
- Setiap test `RefreshDatabase` + `$this->seed(DatabaseSeeder::class)`; akun demo tersedia (`dokter@eklinik.test`, dst).
- Test utama: `tests/Feature/AlurKlinikTest.php` — cakup alur penuh; perluas di sana untuk aturan bisnis baru.

## Jebakan yang sudah diketahui

| Masalah | Penjelasan |
|---------|-----------|
| PHP di host | Laragon hanya punya PHP 7.4/8.1; Laravel 13 butuh ≥ 8.3. Selalu `docker compose exec app ...`. |
| `CLAUDE.md` / `AGENTS.md` bawaan installer | Berisi instruksi memasang PHP di host & Laravel Boost — abaikan, tidak relevan dengan setup Docker. |
| Heredoc panjang lewat Bash tool Windows | Pernah gagal parse; tulis file dengan tool Write/Edit. |
| Pluralisasi tabel | `Poli` → Laravel bisa salah menebak; selalu set `#[Table]`. |
| Route parameter | `apiResource('icd10s')` → `{icd10}`; untuk nama Indonesia pakai route manual atau cek `route:list`. |
| Timezone | `today()` bergantung `APP_TIMEZONE=Asia/Jakarta`; antrian & counter harian memakai tanggal lokal. |
| Mengubah stok langsung | Jangan. Pakai `FarmasiService` agar kartu stok konsisten. |
| Nomor manual | Jangan `max()+1`; pakai `NomorUrutService` (aman konkurensi). |

## Keamanan

- Semua route kecuali `/login` di bawah `auth:sanctum`; tambahkan `role:` untuk endpoint yang mengubah data.
- Jangan kembalikan kolom sensitif (password/remember_token sudah `#[Hidden]`).
- Data pasien = data kesehatan pribadi; jangan log payload rekam medis.
