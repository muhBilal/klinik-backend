# 02 — Arsitektur

## Struktur folder

```
app/
├── Enums/                      Backed enum string, dipakai sebagai cast model & validasi Rule::enum
│   ├── Izin.php                Katalog izin RBAC (pasien.lihat, rme.lihat, kasir.tagihan, ...) + label() + grup()
│   ├── Role.php                Kode peran SISTEM (admin, pendaftaran, perawat, dokter, apoteker, kasir) — untuk seeder/test saja
│   ├── KategoriBerkas.php      foto_klinis, informed_consent, radiologi, hasil_penunjang, lainnya
│   ├── StatusKunjungan.php     menunggu, diperiksa, menunggu_pembayaran, selesai, batal
│   ├── StatusResep.php         menunggu, diserahkan, batal
│   ├── StatusTagihan.php       belum_bayar, lunas, batal
│   ├── Penjamin.php            umum, bpjs, asuransi
│   ├── MetodeBayar.php         tunai, debit, qris, transfer, penjamin
│   └── JenisMutasi.php         masuk, keluar, penyesuaian
├── Http/
│   ├── Controllers/Api/        Satu controller per resource (lihat 05-api-reference.md)
│   └── Middleware/
│       ├── EnsurePermission.php     alias `izin`     — izin:pasien.kelola (salah satu izin)
│       ├── ResolveCabang.php        alias `cabang`   — isi CabangAktif dari user / header X-Cabang-Id
│       └── EnsureTwoFactorEnabled.php alias `wajib2fa` — peran wajib 2FA harus mengaktifkannya dulu
├── Models/
│   ├── Concerns/Auditable.php  Trait: catat buat/ubah/hapus/pulihkan ke audit_logs
│   ├── Concerns/DalamCabang.php Trait: global scope `cabang` + isi cabang_id otomatis
│   └── ...                     Eloquent model (lihat 03-database.md)
├── Providers/AppServiceProvider.php  Binding scoped, Gate::before (izin), validasi token Sanctum (idle, user aktif)
├── Services/                   Logika bisnis + transaksi DB
│   ├── NomorUrutService.php    Penomoran berurutan aman-konkurensi (tabel counters), prefix dari pengaturan
│   ├── PemeriksaanService.php  panggil, simpan (upsert pemeriksaan/diagnosa/tindakan/resep), selesai
│   ├── TagihanService.php      buatDariKunjungan, bayar
│   ├── FarmasiService.php      serahkan resep, mutasiManual stok
│   ├── AuditService.php        penulis tunggal audit_logs (catat, catatModel)
│   ├── PengaturanService.php   baca/simpan pengaturan klinik (cache)
│   ├── TwoFactorService.php    TOTP RFC 6238, kode pemulihan
│   └── BerkasService.php       simpan/baca berkas terenkripsi, tautan bertanda tangan
└── Support/CabangAktif.php     Cabang aktif request ini (scoped singleton)
config/eklinik.php              Definisi pengaturan klinik (default + aturan validasi), konfigurasi berkas & 2FA
bootstrap/app.php               Routing api/web, alias middleware, render JSON untuk api/*
routes/api.php                  Semua endpoint + grouping izin
routes/console.php              Jadwal scheduler (prune token, prune failed jobs)
routes/web.php                  Hanya `GET /` (info JSON)
lang/id/validation.php          Pesan validasi Bahasa Indonesia (fallback ke en)
database/migrations/            2026_09_29_1000xx_* = skema awal, 2026_09_30_1000xx_* = Fase 0 (lihat 03-database.md)
database/seeders/DatabaseSeeder.php   Data master + akun demo (peran dibuat migration)
database/factories/             UserFactory, PasienFactory
tests/Feature/                  AlurKlinikTest, FilterTest, PeranIzinTest, MultiCabangTest, AuditLogTest,
                                KeamananTest, PengaturanTest, BerkasTest
tests/Unit/TwoFactorServiceTest.php   Vektor uji RFC 6238
```

## Lapisan & tanggung jawab

```
Request ─► routes/api.php (auth:sanctum + cabang + wajib2fa + izin:...) ─► Controller ─► Service ─► Model/DB
                                                                            │                 │
                                                              validasi ($request->validate)   └─ event model ─► AuditService
```

- **Controller**: validasi input (`$request->validate([...])` inline, tidak memakai FormRequest), query list
  (filter, search, paginate), memanggil service, mengembalikan `response()->json(...)`.
- **Service**: semua operasi yang mengubah beberapa tabel atau punya aturan bisnis. Dibungkus
  `DB::transaction` dan memakai `lockForUpdate()` untuk baris yang rawan race condition
  (counter, tagihan, resep, obat). Pelanggaran aturan dilempar sebagai
  `ValidationException::withMessages([...])` → HTTP 422 dengan format error Laravel standar.
- **Model**: atribut PHP 8 `#[Table]`, `#[Fillable]`, `#[Appends]`, `#[Hidden]`; `casts()` untuk enum/tanggal/integer;
  relasi; scope kecil (`User::dokter()`, `Obat::stokMenipis()`); trait `Auditable`, `DalamCabang`, `SoftDeletes`.

## Respons API

- Tidak memakai API Resource; model diserialisasi langsung (`toArray`). Enum → nilai string, tanggal `date:Y-m-d`,
  timestamp ISO-8601 UTC.
- List memakai `paginate()` Laravel → `{ data, current_page, last_page, per_page, total, from, to, links, ... }`.
  Pengecualian (array biasa): `GET /polis`, `/dokters`, `/cabangs`, `/perans`, `/izins`, `/berkas`.
- `per_page` dibatasi lewat helper `Controller::paginate($query, $request, default, max)`. `?simple=1` memakai
  `simplePaginate` (tanpa `COUNT(*)`, tanpa `total`/`last_page`) — dipakai autocomplete frontend.
- **Select seperlunya**: list memakai `->select([...])` dan eager load dengan kolom (`'poli:id,nama'`); FK relasi wajib ikut
  di-select. Relasi detail kunjungan terpusat di `Kunjungan::loadDetail($rekamMedis)` / `Kunjungan::relasiRekamMedis()`;
  detail resep/tagihan di konstanta `DETAIL` controller (dipakai juga respons `serahkan`/`bayar`). Kolom yang tidak
  di-select tidak muncul di JSON — cek pemakaian di frontend sebelum menghapus kolom dari select.
- Error: 401 (token tidak ada/kedaluwarsa/idle), 403 (izin; `{kode: 'wajib_2fa'}` bila 2FA wajib belum aktif),
  404 (route model binding — termasuk data cabang lain), 422 (validasi/aturan bisnis, `{ message, errors: { field: [..] } }`),
  `abort_if(..., 422, 'pesan')` untuk larangan hapus data terpakai.

## Autentikasi & otorisasi

- `POST /api/login` → `{ token, user }`, atau `{ two_factor: true, tantangan }` bila akun ber-2FA lalu
  `POST /api/login/2fa {tantangan, kode}` → `{ token, user }`. Token dikirim sebagai `Authorization: Bearer <token>`.
- Token: `expires_at` = login + `SANCTUM_EXPIRATION` (720 menit); ditolak bila tidak dipakai lebih dari
  `keamanan.idle_timeout_menit` atau pemiliknya nonaktif/dihapus (`Sanctum::authenticateAccessTokensUsing` di AppServiceProvider).
  Detail: [modul/F0-04-keamanan-sesi-2fa.md](modul/F0-04-keamanan-sesi-2fa.md).
- **Izin** (RBAC dinamis): `users.role` = `perans.kode`; peran memegang daftar izin (`peran_izins`) atau `akses_penuh`.
  - Route: `->middleware('izin:pemeriksaan.vital,pemeriksaan.dokter')` = lolos bila punya salah satu.
  - Kode: `$user->punyaIzin(Izin::RmeLihat)`, atau `$user->can('rme.lihat')` (Gate::before).
  - `$user->tercatatSebagaiDokter()` = punya `pemeriksaan.dokter` dan bukan akses penuh (dipakai `User::dokter()`, panggil pasien).
  - Pembatasan level service: tanpa `pemeriksaan.dokter` hanya tanda vital + `subjektif` yang disimpan (`PemeriksaanService::simpan`).
  - Data klinis vs komersial: tanpa `rme.lihat` detail kunjungan/pasien tidak memuat SOAP/diagnosa/tindakan/resep.
  Detail: [modul/F0-01-rbac-peran-izin.md](modul/F0-01-rbac-peran-izin.md).
- **Cabang aktif**: middleware `cabang` mengisi `App\Support\CabangAktif`; model `DalamCabang` (Kunjungan, Resep, Tagihan)
  otomatis difilter. Detail: [modul/F0-02-multi-cabang.md](modul/F0-02-multi-cabang.md).
- CORS: `config/cors.php`, origin dari `FRONTEND_URL`, `supports_credentials=false` (tidak pakai cookie), semua header
  diizinkan (termasuk `X-Cabang-Id`).

## Penomoran

`NomorUrutService` memakai tabel `counters (key, value)`:
`insertOrIgnore` → `SELECT ... FOR UPDATE` → `update`. Prefix dokumen dari pengaturan `penomoran.prefix_*`
(default REG/RSP/INV); key counter tetap per jenis dokumen sehingga mengganti prefix tidak mereset urutan.

| Nomor | Key counter | Format contoh |
|-------|-------------|---------------|
| No. RM | `rm` | `000123` (lintas cabang) |
| Antrian | `antrian:{cabang_id}:{poli_id}:{Ymd}` | `7` (per cabang per poli per hari) |
| Registrasi | `reg:{Ymd}` | `REG202609290001` |
| Resep | `rsp:{Ymd}` | `RSP202609290001` |
| Tagihan | `inv:{Ymd}` | `INV202609290001` |

No. RM di-generate otomatis di event `Pasien::creating`.

## Binding per request

`AppServiceProvider::register` mendaftarkan `CabangAktif`, `PengaturanService`, `AuditService` sebagai `scoped`
(satu instance per request/job). Di test, instance bertahan antar-request dalam satu method test — middleware `cabang`
selalu menimpa cabang aktif di setiap request.
