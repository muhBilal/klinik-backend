# F0-02 — Multi-cabang

**PRD:** AD-01 (master data pusat, harga & stok per cabang, laporan konsolidasi) · **Fase:** 0 · **Status:** fondasi selesai;
harga & stok per cabang menyusul di modul Katalog (TR-01) dan Inventori (IN-01, IN-05)

## Keputusan desain

- **Satu instalasi = satu organisasi klinik** dengan banyak cabang (bukan multi-tenant lintas organisasi dalam satu database).
- **Pusat (lintas cabang):** pasien & No. RM, poli, tindakan (harga dasar; harga per cabang di `tindakan_hargas`, F1-01), ICD-10,
  obat (stok masih global), peran, pengaturan.
- **Per cabang:** kunjungan, resep, tagihan (dan nantinya booking, stok, kas). Kolom `cabang_id`.
- **Pengguna:** `users.cabang_id` = cabang tempat bertugas; `null` = boleh mengakses semua cabang (pemilik, admin pusat).

## Cabang aktif

`App\Support\CabangAktif` (scoped singleton) diisi middleware `cabang` (`ResolveCabang`) pada semua route login:

| User | Cabang aktif |
|------|--------------|
| `cabang_id` terisi | selalu cabangnya — header `X-Cabang-Id` diabaikan |
| `cabang_id` null + header `X-Cabang-Id` cabang aktif | cabang itu |
| `cabang_id` null tanpa header (atau header tidak valid/nonaktif) | `null` = tampilan semua cabang |

`CabangAktif::untukDataBaru()` dipakai saat membuat data: cabang aktif, atau satu-satunya cabang aktif bila klinik baru punya satu;
selain itu 422 `{ cabang: 'Pilih cabang aktif terlebih dahulu.' }`.

## Trait `DalamCabang`

Dipakai `Kunjungan`, `Resep`, `Tagihan`:
- Global scope `cabang`: `where cabang_id = cabang aktif` bila ada.
- `creating`: isi `cabang_id` dari `untukDataBaru()` bila kosong. Resep & tagihan diisi eksplisit dari kunjungan.
- Relasi `cabang()`.

Akibat: route model binding (`{kunjungan}`, `{tagihan}`, `{resep}`) untuk data cabang lain → **404**; list otomatis per cabang;
`withCount('kunjungans')` di dashboard ikut per cabang.

## Pengecualian lintas cabang (disengaja)

| Endpoint | Alasan |
|----------|--------|
| `GET /pasiens/{id}` (riwayat 50 kunjungan) | master pasien pusat |
| `GET /pasiens/{id}/riwayat` | kesinambungan klinis: dokter cabang B melihat rekam medis dari cabang A |
| `GET /kunjungans/{id}` (read-only) | tautan dari riwayat pasien |
| `berkas` | tidak memakai `DalamCabang`; `cabang_id` hanya informasi |
| Hapus pasien/poli | cek kunjungan di semua cabang (`withoutGlobalScope('cabang')`) |

Mengubah data cabang lain (panggil, pemeriksaan, bayar, serahkan) tetap tidak bisa.

## Aturan terkait

- Nomor antrian per **cabang + poli + hari** (counter `antrian:{cabang}:{poli}:{Ymd}`); unik DB `(cabang_id, poli_id, tanggal, no_antrian)`.
- Cek "sudah terdaftar hari ini" per cabang.
- `dokter_id` saat pendaftaran harus dokter cabang itu atau dokter lintas cabang; `/dokters` mengikuti cabang aktif.
- No. registrasi/resep/tagihan tetap unik se-organisasi (counter harian global).
- Audit log mencatat `cabang_id` data yang diubah (bukan cabang yang sedang dipilih).

## Migrasi data lama

Migration `2026_09_30_100003`: bila database sudah berisi user/kunjungan → buat cabang `UTAMA` "Klinik Utama", isi `cabang_id`
kunjungan/resep/tagihan, pindahkan semua user **selain admin** ke cabang itu, ganti key counter antrian lama
`antrian:{poli}:{Ymd}` → `antrian:{cabang}:{poli}:{Ymd}`. Instalasi baru: cabang dibuat seeder (`UTAMA`) atau admin.

## API

`GET /cabangs` (login; pemegang `cabang.kelola` melihat semua + `users_count`), `POST/GET/PUT/DELETE /cabangs/{id}` (cabang.kelola).
`/me` → `cabang`, `cabangs` (pilihan). Kunjungan/tagihan/resep detail menyertakan `cabang`.

### Urutan middleware (perbaikan keamanan, 1 Okt 2026)
Middleware `cabang` (`ResolveCabang`) didaftarkan di prioritas **sebelum** `SubstituteBindings` (`bootstrap/app.php`), sehingga model
ber-scope cabang dari parameter rute (kunjungan, tagihan, paket pasien, …) tersaring cabang request. Sebelumnya binding berjalan saat cabang
aktif masih kosong → user cabang lain bisa membuka/mengubah data cabang lain lewat id (bug lama; test lolos karena state cabang terbawa
antar request). `tests/TestCase::call` kini mengosongkan instance scoped & controller tiap request agar perilaku test sama dengan produksi.

## Frontend

- `useAuthStore()`: `cabangAktif` (localStorage `eklinik_cabang`), `cabangs`, `cabang`, `lintasCabang`, `setCabang(id)`.
  `lib/api.js` mengirim header `X-Cabang-Id`. Nilai tersimpan divalidasi terhadap `me.cabangs`.
- Header layout: pemilih cabang (user lintas cabang, >1 cabang; ganti = muat ulang halaman) atau chip nama cabang (staf).
- `views/master/CabangView.vue` (MasterCrud, input jam `type="time"`), menu Master Data → Cabang.
- Kop struk/etiket/tiket menampilkan nama cabang; alamat & telepon struk memakai data cabang, fallback pengaturan klinik.

## Belum dikerjakan (fase berikutnya)

- ~~Harga tindakan per cabang (TR-01)~~ → selesai di [F1-01](F1-01-katalog-treatment.md). Stok obat/BHP per gudang cabang & mutasi
  antar cabang (IN-01, IN-05). ~~Tarif konsultasi poli per cabang~~ → jasa konsultasi kini treatment, ikut harga per cabang (F1-09).
- ~~Laporan konsolidasi lintas cabang (LP-02)~~ → laporan penjualan & paket semua cabang + rincian per cabang (F1-11).
- Modul spesialisasi aktif per cabang (PRD bagian 6).

## Test

`tests/Feature/MultiCabangTest.php` — antrian & daftar per cabang, header diabaikan untuk staf, admin konsolidasi/per cabang,
wajib pilih cabang, dokter harus di cabang yang sama, tagihan ikut cabang & riwayat lintas cabang, pemilih & master cabang.
