# F0-06 — Pengaturan Klinik

**PRD:** AD-04 (jam operasional, template dokumen, printer, pajak) · **Fase:** 0 · **Status:** selesai untuk identitas klinik,
kop & lebar struk, prefix nomor, keamanan. Jam operasional ada di data cabang (F0-02). **Pajak** ditunda ke modul Billing
(Fase 1) agar tidak ada pengaturan yang belum dipakai perhitungan.

## Desain

- Definisi (kunci, default, aturan validasi, `publik`) di `config/eklinik.php` → `pengaturan`. Aturan validasi hanya string
  (tanpa closure/objek) agar `config:cache` di image produksi tetap jalan.
- Tabel `pengaturans` hanya menyimpan kunci yang pernah diubah (`nilai` JSON). Nilai efektif = tersimpan ?? default.
- `App\Services\PengaturanService` (scoped): `get('klinik.nama')`, `semua($hanyaPublik)` (bertingkat), `simpan($input, $user)`,
  `aturanValidasi()`. Nilai tersimpan di-cache `eklinik:pengaturan` sampai disimpan ulang.
- Setiap simpan → satu baris audit (`aksi=ubah`, `tipe=pengaturan`, `perubahan` per kunci yang berubah).

## Daftar pengaturan

| Kunci | Default | Validasi | Publik (`/info`) | Dipakai di |
|-------|---------|----------|------------------|------------|
| `klinik.nama` | `APP_NAME` | wajib, ≤100 | ya | login, header, kop struk/etiket/tiket |
| `klinik.alamat`, `klinik.telepon`, `klinik.email`, `klinik.npwp` | null | opsional | ya | kop struk (fallback bila cabang kosong) |
| `struk.catatan_kaki` | "Terima kasih atas kunjungan Anda." | ≤255 | ya | kaki struk kasir |
| `cetak.lebar_struk` | `80mm` | `58mm` / `80mm` | ya | `printElement(..., { lebar })` → `@page size` |
| `penomoran.prefix_registrasi` / `_resep` / `_tagihan` | REG / RSP / INV | 2–5 huruf kapital | tidak | `NomorUrutService` |
| `keamanan.idle_timeout_menit` | 15 | 5–480 | tidak | validasi token + idle logout frontend (F0-04) |
| `keamanan.wajib_2fa` | `[]` | array kode peran yang ada | tidak | middleware `wajib2fa` (F0-04) |

Menambah pengaturan: tambah entri di `config/eklinik.php`, baca dengan `PengaturanService::get()`, tambahkan field di
`PengaturanView.vue` dan atribut validasi di `lang/id/validation.php` bila perlu. Tanpa migration.

## API

- `GET /info` (publik) → pengaturan `publik: true`, bertingkat.
- `GET /pengaturan`, `PUT /pengaturan` (izin `pengaturan.kelola`). Payload PUT bertingkat & parsial:
  ```json
  { "klinik": { "nama": "Klinik Cantik Sehat" }, "penomoran": { "prefix_registrasi": "RJ" }, "keamanan": { "wajib_2fa": ["admin", "dokter"] } }
  ```
  Error validasi memakai kunci bertitik (`penomoran.prefix_resep`, `keamanan.wajib_2fa.0`).

## Frontend

- `stores/klinik.js` memuat `/info` sekali (`muat()`, `muat(true)` setelah simpan) → `klinik.nama`, `klinik.info`.
- `views/admin/PengaturanView.vue` (menu Administrasi → Pengaturan): Identitas, Struk & Cetak, Penomoran, Keamanan (checkbox peran wajib 2FA).
- Semua teks "E-KLINIK" yang di-hard-code di struk (TagihanDetail), etiket (ResepDetail), tiket antrian (PendaftaranView),
  dan halaman login diganti nama klinik dari pengaturan.

## Test

`tests/Feature/PengaturanTest.php` — `/info` publik tanpa kunci rahasia, simpan & prefix nomor langsung dipakai, audit perubahan,
validasi tiap jenis pengaturan, hanya pemegang `pengaturan.kelola`.
