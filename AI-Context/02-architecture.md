# 02 — Arsitektur

## Struktur folder

```
app/
├── Enums/                      Backed enum string, dipakai sebagai cast model & validasi Rule::enum
│   ├── Role.php                admin, pendaftaran, perawat, dokter, apoteker, kasir (+ label())
│   ├── StatusKunjungan.php     menunggu, diperiksa, menunggu_pembayaran, selesai, batal
│   ├── StatusResep.php         menunggu, diserahkan, batal
│   ├── StatusTagihan.php       belum_bayar, lunas, batal
│   ├── Penjamin.php            umum, bpjs, asuransi
│   ├── MetodeBayar.php         tunai, debit, qris, transfer, penjamin
│   └── JenisMutasi.php         masuk, keluar, penyesuaian
├── Http/
│   ├── Controllers/Api/        Satu controller per resource (lihat 05-api-reference.md)
│   └── Middleware/EnsureRole.php   alias `role` (didaftarkan di bootstrap/app.php)
├── Models/                     Eloquent model (lihat 03-database.md)
└── Services/                   Logika bisnis + transaksi DB
    ├── NomorUrutService.php    Penomoran berurutan aman-konkurensi (tabel counters)
    ├── PemeriksaanService.php  panggil, simpan (upsert pemeriksaan/diagnosa/tindakan/resep), selesai
    ├── TagihanService.php      buatDariKunjungan, bayar
    └── FarmasiService.php      serahkan resep, mutasiManual stok
bootstrap/app.php               Routing api/web, alias middleware, render JSON untuk api/*
routes/api.php                  Semua endpoint + grouping role
routes/web.php                  Hanya `GET /` (info JSON)
lang/id/validation.php          Pesan validasi Bahasa Indonesia (fallback ke en)
database/migrations/            2026_09_29_1000xx_* = skema domain
database/seeders/DatabaseSeeder.php   Data master + akun demo
database/factories/             UserFactory, PasienFactory
tests/Feature/AlurKlinikTest.php      Test end-to-end alur klinik
```

## Lapisan & tanggung jawab

```
Request ─► routes/api.php (auth:sanctum + role:...) ─► Controller ─► Service ─► Model/DB
                                                          │
                                        validasi ($request->validate)
```

- **Controller**: validasi input (`$request->validate([...])` inline, tidak memakai FormRequest), query list
  (filter, search, paginate), memanggil service, mengembalikan `response()->json(...)`.
- **Service**: semua operasi yang mengubah beberapa tabel atau punya aturan bisnis. Dibungkus
  `DB::transaction` dan memakai `lockForUpdate()` untuk baris yang rawan race condition
  (counter, tagihan, resep, obat). Pelanggaran aturan dilempar sebagai
  `ValidationException::withMessages([...])` → HTTP 422 dengan format error Laravel standar.
- **Model**: atribut PHP 8 `#[Table]`, `#[Fillable]`, `#[Appends]`, `#[Hidden]`; `casts()` untuk enum/tanggal/integer;
  relasi; scope kecil (`User::dokter()`, `Obat::stokMenipis()`).

## Respons API

- Tidak memakai API Resource; model diserialisasi langsung (`toArray`). Enum → nilai string, tanggal `date:Y-m-d`,
  timestamp ISO-8601 UTC.
- List memakai `paginate()` Laravel → `{ data, current_page, last_page, per_page, total, from, to, links, ... }`.
  Pengecualian: `GET /polis` dan `GET /dokters` mengembalikan array biasa.
- `per_page` dibatasi lewat helper `Controller::paginate($query, $request, default, max)`. `?simple=1` memakai
  `simplePaginate` (tanpa `COUNT(*)`, tanpa `total`/`last_page`) — dipakai autocomplete frontend.
- **Select seperlunya**: list memakai `->select([...])` dan eager load dengan kolom (`'poli:id,nama'`); FK relasi wajib ikut
  di-select. Relasi detail kunjungan terpusat di `Kunjungan::loadDetail()` / `Kunjungan::relasiRekamMedis()`;
  detail resep/tagihan di konstanta `DETAIL` controller (dipakai juga respons `serahkan`/`bayar`). Kolom yang tidak
  di-select tidak muncul di JSON — cek pemakaian di frontend sebelum menghapus kolom dari select.
- Error: 401 (token), 403 (`EnsureRole`), 404 (route model binding), 422 (validasi/aturan bisnis,
  `{ message, errors: { field: [..] } }`), `abort_if(..., 422, 'pesan')` untuk larangan hapus data terpakai.

## Autentikasi & otorisasi

- `POST /api/login` → `{ token, user }`. Token dikirim sebagai `Authorization: Bearer <token>`.
- `User::hasRole(...$roles)` → admin selalu `true`. Middleware `role:dokter,perawat` memakai ini.
- Pembatasan tambahan di level service: perawat hanya boleh mengubah tanda vital + `subjektif`
  (lihat `PemeriksaanService::simpan`).
- CORS: `config/cors.php`, origin dari `FRONTEND_URL`, `supports_credentials=false` (tidak pakai cookie).

## Penomoran

`NomorUrutService` memakai tabel `counters (key, value)`:
`insertOrIgnore` → `SELECT ... FOR UPDATE` → `update`. Key:

| Nomor | Key counter | Format contoh |
|-------|-------------|---------------|
| No. RM | `rm` | `000123` |
| Antrian | `antrian:{poli_id}:{Ymd}` | `7` (per poli per hari) |
| Registrasi | `reg:{Ymd}` | `REG202609290001` |
| Resep | `rsp:{Ymd}` | `RSP202609290001` |
| Tagihan | `inv:{Ymd}` | `INV202609290001` |

No. RM di-generate otomatis di event `Pasien::creating`.
