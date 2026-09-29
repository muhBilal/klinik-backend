# 04 — Alur & Aturan Bisnis

## Alur pelayanan

```
[Pendaftaran]           [Perawat/Dokter]                     [Kasir]            [Apoteker]
 buat kunjungan  ──►  panggil ──► simpan pemeriksaan ──► selesai ──► bayar tagihan ──► serahkan resep
 status: menunggu     diperiksa                          menunggu_pembayaran ─► selesai
```

### Status kunjungan (`StatusKunjungan`)

| Dari | Aksi | Ke | Siapa | Kode |
|------|------|----|-------|------|
| — | `POST /kunjungans` | `menunggu` | pendaftaran | `KunjunganController::store` |
| `menunggu` | `POST /kunjungans/{id}/batal` | `batal` | pendaftaran | `KunjunganController::batal` |
| `menunggu` | `POST /kunjungans/{id}/panggil` | `diperiksa` | dokter, perawat | `PemeriksaanService::panggil` |
| `diperiksa` | `POST /kunjungans/{id}/selesai` | `menunggu_pembayaran` | dokter | `PemeriksaanService::selesai` |
| `menunggu_pembayaran` | `POST /tagihans/{id}/bayar` | `selesai` | kasir | `TagihanService::bayar` |

Status resep: `menunggu` → `diserahkan`. Status tagihan: `belum_bayar` → `lunas`.

## Aturan wajib (dijaga backend, sudah ditest)

### Pendaftaran
- Pasien tidak boleh didaftarkan dua kali ke poli yang sama pada hari yang sama selama kunjungan sebelumnya belum `selesai`/`batal`.
- `no_antrian` berurutan per poli per hari; `tanggal` selalu `today()` (tidak bisa daftar untuk hari lain).
- `no_penjamin` wajib bila penjamin bukan `umum`.
- `dokter_id` opsional (null = dokter jaga); saat dipanggil oleh user berrole dokter, `dokter_id` diisi otomatis.

### Pemeriksaan (`PemeriksaanService::simpan`)
- Hanya bisa disimpan saat status `menunggu` atau `diperiksa`; setelah itu terkunci.
- **Perawat** hanya menyimpan tanda vital + `subjektif`; field lain di payload **diabaikan** (bukan error). Mengisi `perawat_id`.
- **Dokter/admin** menyimpan semua field; mengisi `dokter_id`.
- `diagnosas`, `tindakans`, `resep` bersifat **replace-all** bila key ada di payload (hapus lalu buat ulang). Key tidak dikirim = tidak diubah.
- Diagnosa pertama tanpa `jenis` otomatis `primer`, sisanya `sekunder`.
- Tarif tindakan dan harga obat di-**snapshot** dari master saat disimpan.
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
- Setelah lunas, status kunjungan → `selesai`.

### Farmasi (`FarmasiService`)
- **Obat hanya diserahkan setelah tagihan lunas** (alur: poli → kasir → farmasi).
- Stok setiap item divalidasi (dengan lock) sebelum dikurangi; bila kurang → 422 menyebut obat yang kurang.
- Setiap perubahan stok **wajib** lewat `FarmasiService` agar tercatat di `stok_mutasis` (kartu stok). Jangan pernah `update(['stok' => ...])` langsung.
- Mutasi manual: `masuk` (+jumlah), `keluar` (−jumlah), `penyesuaian` (stok opname: `jumlah` = stok fisik absolut; delta dihitung otomatis). Stok tidak boleh negatif.
- Obat baru dengan `stok_awal > 0` dicatat sebagai mutasi `masuk` "Stok awal".

### User
- Admin tidak bisa menghapus, menonaktifkan, atau menurunkan role akun sendiri.
- `poli_id` wajib untuk role dokter.
- Login ditolak untuk user `is_active = false`.
