# 04 — Alur & Aturan Bisnis

## Alur pelayanan

```
[Pendaftaran]           [Perawat/Dokter]                     [Kasir]            [Apoteker]
 buat kunjungan  ──►  panggil ──► simpan pemeriksaan ──► selesai ──► bayar tagihan ──► serahkan resep
 status: menunggu     diperiksa                          menunggu_pembayaran ─► selesai
```

### Status kunjungan (`StatusKunjungan`)

| Dari | Aksi | Ke | Izin | Kode |
|------|------|----|------|------|
| — | `POST /kunjungans` | `menunggu` | kunjungan.daftar | `KunjunganController::store` |
| `menunggu` | `POST /kunjungans/{id}/batal` | `batal` | kunjungan.daftar | `KunjunganController::batal` |
| `menunggu` | `POST /kunjungans/{id}/panggil` | `diperiksa` | pemeriksaan.panggil | `PemeriksaanService::panggil` |
| `diperiksa` | `POST /kunjungans/{id}/selesai` | `menunggu_pembayaran` | pemeriksaan.dokter | `PemeriksaanService::selesai` |
| `menunggu_pembayaran` | `POST /tagihans/{id}/bayar` | `selesai` | kasir.tagihan | `TagihanService::bayar` |

Semua transaksi di atas hanya bisa dilakukan pada data **cabang aktif** (data cabang lain → 404).

Status resep: `menunggu` → `diserahkan`. Status tagihan: `belum_bayar` → `lunas`.

## Aturan wajib (dijaga backend, sudah ditest)

### Cabang (detail: [modul/F0-02-multi-cabang.md](modul/F0-02-multi-cabang.md))
- Kunjungan dibuat di cabang aktif. Staf terikat cabang (`users.cabang_id`) selalu memakai cabangnya; header `X-Cabang-Id` diabaikan.
- User lintas cabang tanpa cabang aktif: hanya boleh membuat data bila klinik punya **tepat satu** cabang aktif; selain itu 422 `cabang`.
- Resep & tagihan mewarisi `cabang_id` kunjungannya.
- Pasien, riwayat rekam medis (`/pasiens/{id}`, `/pasiens/{id}/riwayat`, `GET /kunjungans/{id}`) dan berkas bisa dibaca lintas
  cabang; mengubah data kunjungan cabang lain tidak bisa.

### Pendaftaran
- Pasien tidak boleh didaftarkan dua kali ke poli yang sama **di cabang yang sama** pada hari yang sama selama kunjungan sebelumnya belum `selesai`/`batal`.
- `no_antrian` berurutan **per cabang per poli per hari**; `tanggal` selalu `today()` (tidak bisa daftar untuk hari lain — booking dikerjakan di Fase 1).
- `no_penjamin` wajib bila penjamin bukan `umum`.
- `dokter_id` opsional (null = dokter jaga) dan harus dokter yang bertugas di cabang itu atau dokter lintas cabang. Saat pasien dipanggil
  oleh user yang `tercatatSebagaiDokter()`, `dokter_id` diisi otomatis.

### Pemeriksaan (`PemeriksaanService::simpan`)
- Hanya bisa disimpan saat status `menunggu` atau `diperiksa`; setelah itu terkunci.
- Tanpa izin **pemeriksaan.dokter** (perawat, terapis) hanya tanda vital + `subjektif` yang disimpan; field lain di payload **diabaikan** (bukan error). Mengisi `perawat_id`.
- Pemegang **pemeriksaan.dokter** (dokter, administrator) menyimpan semua field; mengisi `dokter_id`.
- Penggantian diagnosa/tindakan/item resep (replace-all) menghapus baris lama **per model** agar tercatat di audit log.
- `diagnosas`, `tindakans`, `resep` bersifat **replace-all** bila key ada di payload (hapus lalu buat ulang). Key tidak dikirim = tidak diubah.
- Diagnosa pertama tanpa `jenis` otomatis `primer`, sisanya `sekunder`.
- Tarif tindakan di-**snapshot** dari **harga cabang kunjungan** (harga khusus cabang, atau harga dasar) dan harga obat dari master saat disimpan.
- Treatment yang ditandai **tidak dilayani** di cabang kunjungan → 422 `tindakans.{i}.tindakan_id` (tindakan lama tidak dihapus). Treatment nonaktif/terhapus → 422.
- `resep: []` menghapus resep (jika masih `menunggu`). Resep yang sudah diproses farmasi tidak bisa diubah.

### Selesai pemeriksaan (`PemeriksaanService::selesai`)
- Status harus `diperiksa` dan minimal **satu diagnosa ICD-10**.
- Membuat tagihan otomatis (`TagihanService::buatDariKunjungan`):
  konsultasi (`polis.tarif_konsultasi`) + tiap tindakan (`tarif × jumlah`) + tiap item resep (`harga × jumlah`).

### Pembayaran (`TagihanService::bayar`)
- Hanya tagihan `belum_bayar`; baris dikunci.
- `diskon ≤ total`; `grand_total = total − diskon`.
- Tunai: `dibayar ≥ grand_total`, `kembalian = dibayar − grand_total`.
- Non-tunai & `penjamin`: `dibayar` dipaksa = `grand_total`, kembalian 0.
- Setelah lunas, status kunjungan → `selesai` (lewat model, tercatat di audit log).

### Katalog treatment (detail: [modul/F1-01-katalog-treatment.md](modul/F1-01-katalog-treatment.md))
- `tindakans.tarif` = harga dasar pusat; `tindakan_hargas` menimpa per cabang atau menandai tidak dilayani (`tersedia=false`).
- `durasi_menit` (1–720, wajib) + `buffer_menit` (0–240) = panjang slot untuk booking.
- `hargas` & `bhps` di payload treatment bersifat replace-all bila key dikirim; disinkron per model (`TindakanService`).
- BHP standar: jumlah > 0, maks. 3 desimal, dalam satuan stok obat. Belum memotong stok (Inventori IN-02).
- Kategori yang masih dipakai treatment dan obat yang menjadi BHP standar treatment tidak bisa dihapus.

### Farmasi (`FarmasiService`)
- **Obat hanya diserahkan setelah tagihan lunas** (alur: poli → kasir → farmasi).
- Stok setiap item divalidasi (dengan lock) sebelum dikurangi; bila kurang → 422 menyebut obat yang kurang.
- Setiap perubahan stok **wajib** lewat `FarmasiService` agar tercatat di `stok_mutasis` (kartu stok). Jangan pernah `update(['stok' => ...])` langsung.
- Mutasi manual: `masuk` (+jumlah), `keluar` (−jumlah), `penyesuaian` (stok opname: `jumlah` = stok fisik absolut; delta dihitung otomatis). Stok tidak boleh negatif.
- Obat baru dengan `stok_awal > 0` dicatat sebagai mutasi `masuk` "Stok awal".

### User, peran & izin (detail: [modul/F0-01-rbac-peran-izin.md](modul/F0-01-rbac-peran-izin.md))
- Pengguna tidak bisa menghapus, menonaktifkan, atau mengubah peran akun sendiri.
- `poli_id` wajib untuk peran yang memegang izin `pemeriksaan.dokter`.
- Bila admin mengubah peran/status/cabang/password pengguna lain, semua token pengguna itu dicabut (login ulang).
- Hapus pengguna = soft delete + cabut token. Login ditolak untuk user `is_active = false` atau terhapus.
- Peran sistem: tidak bisa dihapus, kode tetap; izin administrator (akses penuh) tidak bisa diubah. Peran yang masih dipakai tidak bisa dihapus.
- Tanpa izin `rme.lihat`: detail kunjungan tanpa SOAP/diagnosa/tindakan/resep, detail pasien tanpa diagnosa, riwayat & berkas 403.

### Audit log (detail: [modul/F0-03-audit-log.md](modul/F0-03-audit-log.md))
- Buat/ubah/hapus/pulihkan model bertrait `Auditable` otomatis tercatat dengan nilai lama & baru (password & secret 2FA tidak pernah dicatat).
- Akses tercatat: buka rekam medis (`lihat`), riwayat pasien, detail pasien, tautan & unduh berkas, login/login gagal/logout, 2FA, ganti password, ubah izin peran, ubah pengaturan.
- Audit log tidak bisa diubah atau dihapus (model menolak; tidak ada endpoint).

### Keamanan sesi (detail: [modul/F0-04-keamanan-sesi-2fa.md](modul/F0-04-keamanan-sesi-2fa.md))
- Token berlaku maks. `SANCTUM_EXPIRATION` menit (720) dan ditolak bila tidak dipakai > `keamanan.idle_timeout_menit` (default 15).
- Akun ber-2FA: login dua langkah; tantangan berlaku 5 menit & hangus setelah 5 kode salah; kode TOTP yang sama tidak bisa dipakai ulang; kode pemulihan sekali pakai.
- Peran di `keamanan.wajib_2fa` yang belum mengaktifkan 2FA hanya bisa mengakses route profil (`me`, `me/2fa*`, `me/password`, `logout`).
- Ganti password sendiri mencabut semua token lain milik user itu.

### Berkas klinis (detail: [modul/F0-05-berkas-terenkripsi.md](modul/F0-05-berkas-terenkripsi.md))
- Hanya jpg/jpeg/png/webp/pdf, maks. `BERKAS_MAKS_KB`. Kunjungan (bila diisi) harus milik pasien yang sama, di cabang aktif, dan tidak batal.
- Isi file dienkripsi sebelum disimpan; dibuka hanya lewat tautan bertanda tangan yang kedaluwarsa (`BERKAS_TAUTAN_MENIT`).
- Hapus = soft delete; file terenkripsi tetap disimpan (retensi rekam medis).
