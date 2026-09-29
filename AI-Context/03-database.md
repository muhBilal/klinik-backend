# 03 — Database

PostgreSQL 17. Semua nominal uang disimpan sebagai **integer rupiah** (tanpa desimal).
Status disimpan sebagai string dan di-cast ke Enum pada model.

## Diagram relasi

```
polis 1─* users (dokter.poli_id)
polis 1─* kunjungans *─1 pasiens
users 1─* kunjungans (dokter_id, created_by)

kunjungans 1─1 pemeriksaans 1─* pemeriksaan_diagnosas *─1 icd10s
kunjungans 1─* kunjungan_tindakans *─1 tindakans
kunjungans 1─1 reseps 1─* resep_items *─1 obats
kunjungans 1─1 tagihans 1─* tagihan_items
obats 1─* stok_mutasis
```

## Tabel

### Master
| Tabel | Kolom penting |
|-------|---------------|
| `users` | name, email (unik), password, **role**, poli_id (dokter), sip, is_active |
| `polis` | kode (unik), nama, tarif_konsultasi, is_active |
| `icd10s` | kode (unik), nama |
| `tindakans` | kode (unik), nama, tarif, is_active |
| `obats` | kode (unik), nama, satuan, harga, **stok** (int, hanya diubah via FarmasiService), stok_minimum, is_active |
| `pasiens` | **no_rm** (unik, auto), nik (unik, 16 digit, nullable), no_bpjs, nama, jenis_kelamin (`L`/`P`), tempat_lahir, tanggal_lahir, golongan_darah, alamat, no_hp, pekerjaan, alergi. Appends: `umur` ("34 th"/"8 bln") |

### Transaksi
| Tabel | Kolom penting |
|-------|---------------|
| `kunjungans` | no_registrasi (unik), pasien_id, poli_id, dokter_id, tanggal, no_antrian, penjamin, no_penjamin, keluhan, **status**, dipanggil_at, selesai_at, created_by. Unik `(poli_id, tanggal, no_antrian)` |
| `pemeriksaans` | kunjungan_id (unik), tekanan_darah (`"120/80"`), nadi, suhu, respirasi, berat_badan, tinggi_badan, subjektif, objektif, asesmen, plan, perawat_id, dokter_id |
| `pemeriksaan_diagnosas` | pemeriksaan_id, icd10_id, jenis (`primer`/`sekunder`) |
| `kunjungan_tindakans` | kunjungan_id, tindakan_id, jumlah, **tarif (snapshot)**, keterangan |
| `reseps` | no_resep, kunjungan_id (unik), dokter_id, **status**, catatan, apoteker_id, diserahkan_at |
| `resep_items` | resep_id, obat_id, jumlah, aturan_pakai, **harga (snapshot)** |
| `tagihans` | no_tagihan, kunjungan_id (unik), total, diskon, grand_total, **status**, metode_bayar, dibayar, kembalian, kasir_id, dibayar_at |
| `tagihan_items` | tagihan_id, kategori (`konsultasi`/`tindakan`/`obat`), deskripsi, jumlah, harga, subtotal |
| `stok_mutasis` | obat_id, jenis, jumlah (**bertanda**: + masuk, − keluar), stok_akhir, referensi (mis. no_resep), keterangan, user_id |

### Sistem
`counters` (penomoran), `personal_access_tokens` (Sanctum), `cache`, `jobs`, `sessions`, `password_reset_tokens`.

## Model ↔ tabel

Nama tabel diset eksplisit dengan `#[Table('...')]` karena pluralisasi Inggris tidak cocok untuk kata Indonesia.
Selalu lakukan hal yang sama untuk model baru.

| Model | Tabel |
|-------|-------|
| Poli | polis |
| Pasien | pasiens |
| Icd10 | icd10s |
| Tindakan | tindakans |
| Obat | obats |
| StokMutasi | stok_mutasis |
| Kunjungan | kunjungans |
| Pemeriksaan | pemeriksaans |
| PemeriksaanDiagnosa | pemeriksaan_diagnosas |
| KunjunganTindakan | kunjungan_tindakans |
| Resep / ResepItem | reseps / resep_items |
| Tagihan / TagihanItem | tagihans / tagihan_items |

`Kunjungan::loadDetail()` memuat seluruh relasi untuk halaman detail/pemeriksaan — pakai ini agar
bentuk respons konsisten.

## Aturan hapus

Data yang sudah dipakai transaksi **tidak boleh dihapus** (controller `abort_if(..., 422)`): pasien dengan kunjungan,
poli dengan kunjungan, obat yang pernah diresepkan, tindakan yang pernah dipakai, ICD-10 yang dipakai diagnosa.
Solusinya menonaktifkan (`is_active=false`).
