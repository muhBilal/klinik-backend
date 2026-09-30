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
- **Hak akses**: selalu lewat izin (`middleware('izin:x')`, `$user->punyaIzin(Izin::X)`). Jangan membandingkan `$user->role`
  atau menambah logika khusus kode peran — peran bisa dibuat/diubah admin.
- **Data per cabang**: model transaksi baru yang milik satu cabang memakai trait `DalamCabang` + kolom `cabang_id`.
  Baca lintas cabang yang disengaja: `withoutGlobalScope('cabang')`.
- **Audit**: model yang datanya perlu diaudit memakai trait `Auditable` (+ `auditLabel()`, `auditPasienId()` bila terkait pasien).
  Ubah/hapus data yang diaudit **per model** (`$relasi()->get()->each->delete()`, `$model->update()`), bukan query massal.
  Akses baca data sensitif → `AuditService::catat('lihat', ...)`.
- **Retensi**: data yang dirujuk rekam medis/transaksi memakai `SoftDeletes`; relasi ke data yang bisa terhapus memakai `->withTrashed()`.
- **Pengaturan**: nilai yang bisa diubah admin → definisi di `config/eklinik.php` (`pengaturan`), baca `PengaturanService::get()`.
- **Formatting**: Laravel Pint (preset default). Jalankan sebelum selesai.

## Menambah fitur baru (checklist)

1. Migration baru di `database/migrations` (jangan ubah migration lama yang sudah dipakai data nyata). Tabel transaksi per
   cabang → kolom `cabang_id`; data yang tidak boleh hilang → `softDeletes()`.
2. Model dengan `#[Table]`, `#[Fillable]`, `casts()`, relasi, trait `Auditable` / `DalamCabang` / `SoftDeletes` sesuai kebutuhan.
3. Izin baru (bila perlu) → case di `App\Enums\Izin` (+ `label()`, `grup()`), lalu berikan ke peran bawaan lewat **migration
   baru** (insert `peran_izins`), jangan mengubah migration `100002`.
4. Logika bisnis di `app/Services/...` bila menyentuh >1 tabel atau punya aturan.
5. Controller di `app/Http/Controllers/Api`, route di `routes/api.php` dalam grup `izin:` yang tepat.
6. Tambah pesan/atribut ke `lang/id/validation.php` bila memakai rule/field baru.
7. Seeder bila butuh data master.
8. Feature test di `tests/Feature` (pola: `Sanctum::actingAs($user)`, seed `DatabaseSeeder`, assert status + JSON path).
9. Dokumentasi: file fitur baru di `AI-Context/modul/`, perbarui `03`, `04`, `05`, `07-roadmap-progress.md`, dan `AI-Context`
   di repo `klinik-frontend` bila kontrak API berubah.
10. `docker compose -f docker-compose.dev.yml run --rm --no-deps app php artisan test` dan `... vendor/bin/pint`.

## Testing

- `phpunit.xml` memakai **SQLite in-memory**, bukan PostgreSQL. Maka:
  - Hindari fungsi SQL khusus PostgreSQL (`ilike` manual, `jsonb`, `ANY(...)`) — pakai query builder.
  - `lockForUpdate` diabaikan SQLite (tetap aman ditulis).
- Setiap test `RefreshDatabase` + `$this->seed(DatabaseSeeder::class)`; akun demo tersedia (`dokter@eklinik.test`, dst).
- Test utama: `tests/Feature/AlurKlinikTest.php` — cakup alur penuh; perluas di sana untuk aturan bisnis baru.
  Test per fitur Fase 0: `PeranIzinTest`, `MultiCabangTest`, `AuditLogTest`, `KeamananTest`, `PengaturanTest`, `BerkasTest`.
- `Sanctum::actingAs()` **melewati** validasi token (idle, expiry). Untuk menguji token asli: login lewat `/api/login`,
  `withToken($token)`, dan panggil `$this->app['auth']->forgetGuards()` sebelum tiap request (lihat `KeamananTest::api()`).
- Cabang aktif di test: user terikat cabang otomatis; user lintas cabang → `withHeaders(['X-Cabang-Id' => ...])`
  (header menetap untuk request berikutnya dalam test yang sama — set `''` untuk menghapus).
- `CabangAktif` bertahan antar-request dalam satu test: query model `DalamCabang` langsung di kode test ikut ter-scope ke cabang
  request terakhir — pakai `withoutGlobalScopes()` bila perlu.
- Berkas: `Storage::fake('berkas')`. Image Docker tidak punya ekstensi GD, jadi jangan pakai `UploadedFile::fake()->image()`;
  pakai `createWithContent()` dengan byte JPEG (lihat `BerkasTest::JPEG`).
- Waktu: `$this->travel(16)->minutes()` untuk idle timeout / kedaluwarsa tautan.

## Jebakan yang sudah diketahui

| Masalah | Penjelasan |
|---------|-----------|
| PHP di host | Laragon hanya punya PHP 7.4/8.1; Laravel 13 butuh ≥ 8.3. Selalu `docker compose -f docker-compose.dev.yml exec app ...`. |
| `CLAUDE.md` / `AGENTS.md` bawaan installer | Berisi instruksi memasang PHP di host & Laravel Boost — abaikan, tidak relevan dengan setup Docker. |
| Folder `docker/app` | Build context image gabungan adalah folder induk (`..`), jadi path `COPY` diawali `backend/` / `frontend/`. Ignore file khusus: `docker/app/Dockerfile.dockerignore`. |
| Heredoc panjang lewat Bash tool Windows | Pernah gagal parse; tulis file dengan tool Write/Edit. |
| Pluralisasi tabel | `Poli` → Laravel bisa salah menebak; selalu set `#[Table]`. |
| Route parameter | `apiResource('icd10s')` → `{icd10}`; untuk nama Indonesia pakai route manual atau cek `route:list`. |
| Timezone | `today()` bergantung `APP_TIMEZONE=Asia/Jakarta`; antrian & counter harian memakai tanggal lokal. |
| Mengubah stok langsung | Jangan. Pakai `FarmasiService` agar kartu stok konsisten. |
| Nomor manual | Jangan `max()+1`; pakai `NomorUrutService` (aman konkurensi). |
| Query massal pada data yang diaudit | `Model::where()->update()` / `$relasi()->delete()` tidak memicu event → tidak tercatat audit. Ubah per model. |
| Route model binding data cabang lain | Model `DalamCabang` ter-scope → 404. Untuk tampilan read-only lintas cabang pakai parameter `int` + `withoutGlobalScope('cabang')` (contoh `KunjunganController::show`). |
| `users.role` tidak lagi di-cast enum | Nilainya string kode peran. `Role::X->value` saat membuat user di seeder/test. |
| Membuat user tanpa cabang | `cabang_id` null = akses semua cabang. Staf cabang wajib diisi cabangnya. |
| `APP_KEY` diganti | Berkas terenkripsi & secret 2FA tidak bisa dibuka. Rotasi pakai `APP_PREVIOUS_KEYS`. |
| Edit file lewat skrip Python di Windows | `open(p, 'w')` menulis CRLF. Pakai `newline=''` / mode biner; Pint menormalkan PHP, file lain tidak. |
| Queue worker dev gagal saat start pertama | Tabel `jobs`/`cache` belum ada sebelum `migrate`; container restart otomatis. |
| Stack dev lambat / 504 di Windows | Bind mount kode lambat; jangan nyalakan profile `worker` bila tidak perlu, dan jangan menjalankan banyak stack dev bersamaan. `artisan` yang butuh menit = VM Docker kewalahan I/O. |

## Keamanan

- Semua route kecuali `/info`, `/login`, `/login/2fa`, `/berkas/{uuid}/unduh` (signed URL) di bawah `auth:sanctum` + `cabang`;
  tambahkan `izin:` untuk setiap endpoint (baca maupun ubah) yang menyangkut data sensitif.
- Jangan kembalikan kolom sensitif (password, remember_token, two_factor_* sudah `#[Hidden]`; `berkas.path/checksum` juga).
- Data pasien = data kesehatan pribadi (UU PDP): jangan log payload rekam medis ke log aplikasi. Jejak akses yang sah
  dicatat di `audit_logs` (dibatasi izin `audit.lihat`).
- Isi rekam medis hanya untuk pemegang `rme.lihat`; front office/marketing/kasir tidak menerima SOAP/diagnosa.
