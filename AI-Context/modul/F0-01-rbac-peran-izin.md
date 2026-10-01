# F0-01 — Peran & Izin (RBAC dinamis)

**PRD:** AD-02 (RBAC granular; data klinis terpisah dari data komersial) · **Fase:** 0 · **Status:** selesai (backend + frontend)

## Ringkasan

Hak akses tidak lagi ditentukan kode peran (`role:dokter`), melainkan **izin** yang dipegang peran. Admin dapat membuat peran
baru (mis. "Dokter Estetika", "Kasir Farmasi") dan mengatur izinnya dari menu **Administrasi → Peran & Izin** tanpa mengubah kode.

## Model data

| Tabel | Isi |
|-------|-----|
| `perans` | `kode` (disimpan di `users.role`), `nama`, `deskripsi`, `is_sistem`, `akses_penuh` |
| `peran_izins` | `peran_id`, `izin` (nilai `App\Enums\Izin`) |

- `akses_penuh = true` (administrator) → `izin` efektif = semua case `Izin`, daftar di `peran_izins` diabaikan.
- Peran sistem (`is_sistem`): admin, pendaftaran, perawat, dokter, apoteker, kasir — tidak bisa dihapus, kode tetap; izinnya
  **boleh** diubah (kecuali admin).
- Peran bawaan non-sistem: `terapis`, `manajer`, `marketing` (boleh diubah/dihapus bila tidak dipakai).

## Katalog izin (`App\Enums\Izin`)

| Grup | Izin | Dipakai untuk |
|------|------|---------------|
| Pasien & Pendaftaran | `pasien.lihat` | list/detail identitas pasien, pencarian pasien |
| | `pasien.kelola` | tambah/ubah pasien |
| | `pasien.hapus` | soft delete pasien |
| | `kunjungan.daftar` | daftar & batalkan kunjungan |
| Pelayanan | `pemeriksaan.panggil` | panggil pasien dari antrian |
| | `pemeriksaan.vital` | tanda vital + anamnesis (S) |
| | `pemeriksaan.dokter` | SOAP lengkap, diagnosa, tindakan, resep, selesai pemeriksaan; **penanda dokter** (`/dokters`, `poli_id` wajib) |
| Rekam Medis | `rme.lihat` | isi rekam medis (SOAP, diagnosa, tindakan, resep), riwayat, daftar & buka berkas, buka informed consent, verifikasi tanda tangan |
| | `rme.tindakan` | catatan tindakan (area, face chart, parameter alat), ambil & cabut informed consent (F1-05) |
| | `rme.terbatas` | buka rekam medis kunjungan berakses terbatas (mis. IMS) yang tidak ditangani sendiri (F1-05) |
| | `berkas.kelola` | unggah & hapus lampiran klinis |
| Farmasi | `farmasi.resep`, `farmasi.obat` | resep & penyerahan; obat & stok |
| Keuangan | `kasir.tagihan`, `laporan.keuangan` | tagihan & bayar (+ jual paket, pasang kode promo); pendapatan di dashboard |
| | `promo.kelola` | kelola voucher & kode promo (F1-08) |
| | `komisi.kelola`, `komisi.setujui` | komisi per treatment (di master treatment, bersama `master.kelola`), hitung rekap & penyesuaian; setujui & kunci rekap (F1-09) |
| Administrasi | `master.kelola`, `cabang.kelola`, `pengguna.kelola`, `peran.kelola`, `pengaturan.kelola`, `audit.lihat` | master data, cabang, pengguna, peran, pengaturan, audit log |

## Peta peran bawaan → izin (migration `2026_09_30_100002`)

| Peran | Izin |
|-------|------|
| admin | semua (akses penuh) |
| pendaftaran | pasien.lihat, pasien.kelola, kunjungan.daftar |
| perawat | pasien.lihat, pemeriksaan.panggil, pemeriksaan.vital, rme.lihat, berkas.kelola |
| dokter | pasien.lihat, pemeriksaan.panggil, pemeriksaan.vital, pemeriksaan.dokter, rme.lihat, berkas.kelola |
| apoteker | farmasi.resep, farmasi.obat |
| kasir | kasir.tagihan, laporan.keuangan |
| terapis | pasien.lihat, pemeriksaan.panggil, pemeriksaan.vital, rme.lihat, berkas.kelola |
| manajer | pasien.lihat, laporan.keuangan, audit.lihat |
| marketing | pasien.lihat |

Tambahan per modul (migration `*_beri_izin_*_ke_peran`): booking/jadwal (F1-02), kasir.void/kasir.shift (F1-03),
inventori.kelola (F1-04), **`rme.tindakan` → perawat, dokter, terapis** (F1-05), **`promo.kelola` → manajer, marketing** dan
**`pasien.lihat` → kasir** (menjual paket ke pasien; identitas saja, F1-08), **`komisi.kelola` → manajer** (F1-09; `komisi.setujui`
sengaja hanya administrator — pemisahan tugas). `rme.terbatas` sengaja tidak diberikan ke peran
bawaan: tim yang menangani kunjungan tetap bisa membukanya.

Peta ini mempertahankan perilaku sebelum RBAC (semua test lama tetap lulus), dengan dua perubahan sesuai PRD:
`GET /pasiens` kini butuh `pasien.lihat` (sebelumnya semua role), dan detail kunjungan/pasien tanpa `rme.lihat` tidak memuat isi rekam medis.

## Cara kerja di kode

- `User::peran()` → `belongsTo(Peran::class, 'role', 'kode')`; `User::izin()` (memo per instance), `punyaIzin(...$izin)` (salah satu),
  `tercatatSebagaiDokter()` (punya `pemeriksaan.dokter` dan bukan akses penuh), `lupakanIzin()`.
- Middleware `izin` (`EnsurePermission`) → 403 "Anda tidak memiliki akses ke fitur ini."
- `Gate::before` di AppServiceProvider: `$user->can('rme.lihat')` memakai izin (kemampuan lain tetap lewat Gate/policy biasa).
- `User::scopeDokter()` = aktif + peran memegang `pemeriksaan.dokter` (administrator tidak termasuk).
- `PemeriksaanService::simpan`: tanpa `pemeriksaan.dokter` → hanya vital + subjektif.
- Pemisahan data klinis: `KunjunganController::show` & `PasienController::show` memuat relasi rekam medis hanya bila `rme.lihat`
  (`Kunjungan::loadDetail(bool $rekamMedis)`).
- Perubahan izin peran dicatat satu baris audit `ubah_izin` (`Peran::aturIzin`).

## API

`GET /perans`, `GET /izins` (peran.kelola / pengguna.kelola), `POST/PUT/DELETE /perans` (peran.kelola). Detail di
[05-api-reference.md](../05-api-reference.md#administrasi). `/me` mengembalikan `izin`, `role_label` (nama peran), `tercatat_dokter`.

## Frontend

- `useAuthStore().can(...izin)` menggantikan `hasRole()`. Router `meta.izin`, menu `izin` (lib/menu.js), Command Palette `izin`.
- Halaman `views/admin/PeranView.vue` (list + form izin berkelompok, tombol "semua/kosongkan" per grup).
- `views/master/UserView.vue`: pilihan peran dari `/perans`; field Poli & SIP muncul bila peran memegang `pemeriksaan.dokter`.

## Menambah izin baru

1. Case baru di `App\Enums\Izin` + `label()` + `grup()`.
2. Migration baru yang menambahkan baris `peran_izins` ke peran bawaan yang pantas.
3. Pakai di route (`izin:...`) dan frontend (`meta.izin`, `can()`).
4. Test di `PeranIzinTest` atau test fitur terkait.

## Test

`tests/Feature/PeranIzinTest.php` — payload `me`, peran kustom mengatur akses & pencabutan izin, perlindungan peran sistem/terpakai,
dokter ditentukan izin, terapis hanya tanda vital, pemisahan rekam medis.
