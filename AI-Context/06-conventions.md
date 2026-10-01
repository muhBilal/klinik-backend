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
- **Stok**: desimal (12,3) karena ada satuan fraksional. Jangan ubah `obats.stok` langsung — lewat `InventoriService`
  agar `stok_batches`, total, dan kartu stok konsisten. Pengeluaran selalu FEFO.
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
  Fase 1: `KatalogTreatmentTest`, `BookingTest`, `KasirTest`, `InventoriTest`, `RmeEstetikaTest`, `FotoKlinisTest`.
- Unggah `foto_klinis` di test butuh persetujuan foto (`POST /pasiens/{id}/persetujuan-foto`) atau matikan `foto.wajib_consent`.
- Kunjungan kedua pasien di poli yang sama pada hari yang sama ditolak ("sudah terdaftar") selama kunjungan pertama belum `selesai` —
  test multi-kunjungan memajukan waktu (`$this->travel(1)->days()`, lihat `OdontogramTest::kunjunganGigi`).
- Nama helper test jangan bentrok dengan method final PHPUnit (`status()`, `name()`, dll.).
  Data biner (JPEG 1x1, PNG tanda tangan) ada di trait `tests/Concerns/BuatBerkasUji`.
- Menutup pemeriksaan di test: penutup harus dokter ber-SIP (`dokter@eklinik.test` punya SIP; user factory tidak — isi `sip`), dan
  treatment ber-template consent (TRT-001/002/011/012/022, TND-006/102) butuh consent. Test yang fokusnya bukan consent mematikan
  `rme.wajib_informed_consent` lewat `PengaturanService::simpan(['rme' => ['wajib_informed_consent' => false]])`.
- Tanda tangan consent di test: PNG sah dibuat manual (tanpa GD) — lihat `RmeEstetikaTest::ttd()`.
- Jangan menulis ID tetap (`/api/obats/1`, `'poli_id' => 1`): di PostgreSQL sequence tidak di-reset antar-test. Ambil dari data
  (`Obat::value('id')`).
- Menjalankan suite ke **PostgreSQL** (sebelum rilis / bila memakai SQL mentah): jalankan container `postgres:17-alpine` sementara
  di network `eklinik-dev_default` (mis. nama `eklinik-pgcek`, `--tmpfs /var/lib/postgresql/data`), lalu
  `docker compose -f docker-compose.dev.yml run --rm --no-deps -e DB_CONNECTION=pgsql -e DB_HOST=eklinik-pgcek -e DB_DATABASE=... -e DB_USERNAME=... -e DB_PASSWORD=... app php artisan test`
  (`<env>` di phpunit.xml tidak menimpa env yang sudah diset). Di Git Bash tambahkan `MSYS_NO_PATHCONV=1` pada `docker run`.
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
| Mengubah stok langsung | Jangan. Pakai `InventoriService` (batch + FEFO) agar kartu stok & total konsisten. |
| `latestOfMany()` pada relasi yang di-eager-load | Join subquery-nya membuat kolom tak ber-prefix di `select` jadi ambigu. Pakai `->orderByDesc('tabel.id')` pada `hasOne` (lihat `Kunjungan::tagihan()`). |
| Kolom baru lupa di `#[Fillable]` | `create()` diam-diam mengisi null tanpa error. Setelah menambah kolom, cek `#[Fillable]` modelnya. |
| Assertion stok di test | `obats.stok` float; `assertSame` harus float, tetapi `assertJsonPath` tetap int (JSON tidak mengirim `.0`). |
| Pengaturan di test | Payload `PUT /api/pengaturan` **bertingkat**: `['keuangan' => ['pajak_persen' => 10]]`, bukan `['keuangan.pajak_persen' => 10]`. |
| Nomor manual | Jangan `max()+1`; pakai `NomorUrutService` (aman konkurensi). |
| Query massal pada data yang diaudit | `Model::where()->update()` / `$relasi()->delete()` tidak memicu event → tidak tercatat audit. Ubah per model. |
| Route model binding data cabang lain | Model `DalamCabang` ter-scope → 404. Untuk tampilan read-only lintas cabang pakai parameter `int` + `withoutGlobalScope('cabang')` (contoh `KunjunganController::show`). |
| `users.role` tidak lagi di-cast enum | Nilainya string kode peran. `Role::X->value` saat membuat user di seeder/test. |
| Membuat user tanpa cabang | `cabang_id` null = akses semua cabang. Staf cabang wajib diisi cabangnya. |
| `APP_KEY` diganti | Berkas terenkripsi & secret 2FA tidak bisa dibuka. Rotasi pakai `APP_PREVIOUS_KEYS`. |
| `except()` / `only()` pada Eloquent Collection | Memakai **primary key model**, bukan key koleksi. Setelah `keyBy('cabang_id')` pakai `diffKeys()` / `reject()` (lihat `TindakanService`). |
| SQL mentah di select | Hanya fungsi standar (`COALESCE`, subquery) dan literal `TRUE`/`FALSE` — contoh `Tindakan::scopeDenganHargaCabang`. Uji juga di PostgreSQL. |
| Edit file lewat skrip Python di Windows | `open(p, 'w')` menulis CRLF. Pakai `newline=''` / mode biner; Pint menormalkan PHP, file lain tidak. |
| Queue worker dev gagal saat start pertama | Tabel `jobs`/`cache` belum ada sebelum `migrate`; container restart otomatis. |
| Stack dev lambat / 504 di Windows | Bind mount kode lambat; jangan nyalakan profile `worker` bila tidak perlu, dan jangan menjalankan banyak stack dev bersamaan. `artisan` yang butuh menit = VM Docker kewalahan I/O. |
| Setiap request stack dev 5–10 detik (uji E2E) | PHP men-stat ribuan file lewat bind mount. Di kontainer uji saja: tulis `opcache.validate_timestamps=0` ke `/usr/local/etc/php/conf.d/zz-e2e.ini` lalu `kill -USR2 1` (turun ke < 1 detik). Setelah itu perubahan kode PHP baru terbaca setelah `kill -USR2 1` lagi. Jangan `config:cache` di stack dev — file cache tertulis ke repo host. |
| Mengubah pemeriksaan yang sudah ditandatangani | Model `Pemeriksaan` melempar `LogicException` (juga `PemeriksaanAddendum` untuk ubah/hapus). Koreksi = addendum. Untuk mensimulasikan manipulasi di test pakai `DB::table(...)->update()`. |
| Menyimpan tindakan pemeriksaan | Kirim `tindakans[].id` agar baris (beserta catatan tindakan, consent, koreksi BHP, kondisi odontogram turunan) dipertahankan. Tanpa `id` backend mencocokkan `tindakan_id` + `gigi`; tindakan yang sama dua baris tanpa `id` pada gigi yang sama bisa tertukar. |
| `Builder::value('kolom')` Eloquent | Menerapkan cast model (mis. `status` jadi enum). Untuk nilai mentah pakai `->toBase()->value(...)` (lihat `OdontogramKondisi::kunjunganTerbuka`). |
| Hash RME & kolom baru | `RekamMedisService::hash` dipakai memverifikasi RME lama. Kolom/relasi baru ditambahkan ke isi hash **hanya bila terisi** agar hash tanda tangan lama tetap cocok (contoh gigi tindakan & odontogram). |
| Stack dev 500 "laravel.log could not be opened" | `docker compose run` (root) membuat `storage/logs/laravel.log` milik root saat test gagal; php-fpm (www-data) lalu tidak bisa menulis. `docker exec <app> chmod 666 storage/logs/laravel.log`. |
| Detail consent tanpa tanda tangan | `InformedConsent` menyembunyikan `ttd_*` & `checksum`; hanya `InformedConsentController::show` yang memanggil `makeVisible`. Jangan menambah tanda tangan ke `relasiRekamMedis` (ukuran & audit). |
| Kamera di E2E | Chrome headless: `--use-fake-ui-for-media-stream --use-fake-device-for-media-stream` + `context.grantPermissions(['camera'])`. Encode frame 1920 px di headless bisa beberapa detik — tunggu tombol jepret muncul lagi, jangan `waitForTimeout`. |
| Isi RME kunjungan berakses terbatas bocor | Endpoint baru yang membaca RME/berkas per kunjungan wajib memanggil `RekamMedisService::bolehLihat()` (atau `sembunyikanTerbatas()` untuk daftar). |

## Keamanan

- Semua route kecuali `/info`, `/login`, `/login/2fa`, `/berkas/{uuid}/unduh` (signed URL) di bawah `auth:sanctum` + `cabang`;
  tambahkan `izin:` untuk setiap endpoint (baca maupun ubah) yang menyangkut data sensitif.
- Jangan kembalikan kolom sensitif (password, remember_token, two_factor_* sudah `#[Hidden]`; `berkas.path/checksum` juga).
- Data pasien = data kesehatan pribadi (UU PDP): jangan log payload rekam medis ke log aplikasi. Jejak akses yang sah
  dicatat di `audit_logs` (dibatasi izin `audit.lihat`).
- Isi rekam medis hanya untuk pemegang `rme.lihat`; front office/marketing/kasir tidak menerima SOAP/diagnosa.
