# F1-01 — Katalog Treatment

**PRD:** TR-01 (master treatment: kategori, durasi, harga per cabang, BHP standar, aturan komisi), bagian AD-01 (harga per
cabang) · **Fase:** 1 · **Status:** selesai untuk kategori, durasi + buffer, harga dasar & harga per cabang, BHP standar.
**Aturan komisi** dikerjakan di modul Komisi (KM-01, roadmap Fase 1 #10) karena butuh mesin aturan per peran & split
dokter–terapis–asisten; katalog belum menyimpan apa pun tentang komisi.

## Keputusan desain

- **Tetap memakai tabel `tindakans`** (bukan tabel `treatments` baru): tindakan umum (injeksi, scaling) dan treatment estetika
  (botox, laser) adalah entitas yang sama dan sudah dirujuk `kunjungan_tindakans`. UI menyebutnya "Treatment".
- **Harga dasar + penimpa per cabang.** `tindakans.tarif` = harga dasar (pusat). `tindakan_hargas` (unik `tindakan_id, cabang_id`)
  menimpa harga untuk satu cabang, atau menandai treatment **tidak dilayani** di cabang itu (`tersedia = false`, mis. cabang
  tanpa mesin laser). Cabang tanpa baris = harga dasar & tersedia.
- **Durasi + buffer** (`durasi_menit`, `buffer_menit`) disimpan sekarang agar Booking (BK-02) tinggal memakai
  `durasi + buffer` sebagai panjang slot.
- **BHP standar** (`tindakan_bhps`) merujuk `obats` — satu-satunya master barang saat ini. `jumlah` desimal (3 digit) dalam
  **satuan stok obat** (mis. 0,3 vial = 30 U botulinum). Konversi satuan pakai (U, ml) dan pemotongan stok otomatis menyusul di
  Inventori (IN-02, IN-03). BHP hanya data standar; belum mengubah stok.
- **Kategori** (`kategori_tindakans`) master pusat sederhana (nama unik, deskripsi, aktif), soft delete.
- Semua perubahan tercatat di audit log per baris: `tindakan`, `kategori_tindakan`, `tindakan_harga` (dengan `cabang_id` harga itu),
  `tindakan_bhp`.

## Skema

| Tabel | Kolom |
|-------|-------|
| `kategori_tindakans` | nama (unik), deskripsi, is_active, deleted_at |
| `tindakans` (+) | **kategori_id** (nullable, FK null on delete), **durasi_menit** (default 15), **buffer_menit** (default 0) |
| `tindakan_hargas` | tindakan_id, cabang_id, tarif, tersedia. Unik `(tindakan_id, cabang_id)` |
| `tindakan_bhps` | tindakan_id, obat_id (restrict), jumlah `decimal(10,3)`. Unik `(tindakan_id, obat_id)` |

Migration `2026_09_30_110001_create_katalog_treatment_tables` (diuji migrate → rollback → migrate di PostgreSQL 17).
Data lama: treatment yang sudah ada tanpa kategori, durasi 15 menit, buffer 0 — lengkapi di menu Master Data → Treatment.

## Harga efektif

`Tindakan::scopeDenganHargaCabang(?int $cabangId)` menambah dua kolom lewat subquery `COALESCE` (jalan di PostgreSQL & SQLite):

- `tarif_cabang` = tarif `tindakan_hargas` cabang itu, atau `tindakans.tarif`;
- `tersedia` = `tindakan_hargas.tersedia`, atau `true`.

`scopeTersediaDi(?int)` membuang treatment yang ditandai tidak dilayani di cabang itu. Tanpa cabang (admin melihat semua cabang)
= harga dasar & semua tersedia.

Dipakai di:
- `GET /tindakans` — cabang dari `?cabang_id=` atau cabang aktif; `aktif=1` juga memakai `tersediaDi`.
- `PemeriksaanService::syncTindakan` — tarif di-snapshot ke `kunjungan_tindakans.tarif` dari **harga cabang kunjungan**
  (bukan cabang aktif user); treatment yang tidak dilayani di cabang kunjungan → 422 `tindakans.{i}.tindakan_id`
  (dicek sebelum tindakan lama dihapus). Tagihan tetap memakai snapshot itu.

## Aturan

- `durasi_menit` wajib 1–720; `buffer_menit` 0–240 (kosong = 0); `tarif` ≥ 0.
- `hargas` / `bhps` **replace-all** bila key dikirim, tidak diubah bila key tidak dikirim. Sinkronisasi per model
  (`TindakanService`): baris yang hilang dihapus, yang ada diperbarui (tanpa audit bila nilainya sama), yang baru dibuat.
- `hargas.*.cabang_id` unik dalam payload & cabang belum dihapus; `bhps.*.obat_id` unik & obat belum dihapus;
  `bhps.*.jumlah` > 0, maks. 3 desimal, maks. 50 bahan.
- `kategori_id` harus kategori yang belum dihapus (kategori nonaktif tetap boleh dipakai treatment lama).
- Hapus kategori ditolak bila masih dipakai treatment (yang belum dihapus) → nonaktifkan. Hapus treatment tetap ditolak bila pernah
  dipakai kunjungan; harga & BHP-nya ikut tersimpan (soft delete).
- Hapus obat ditolak bila menjadi BHP standar treatment yang belum dihapus.
- Detail treatment tidak menampilkan harga untuk cabang yang sudah dihapus; menyimpan ulang (replace-all) membuang baris itu.

## API

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/tindakans` | login | `q`, `aktif=1`, `status`, `kategori_id`, `cabang_id` (default cabang aktif), paginated. + `kategori`, `tarif_cabang`, `tersedia`, `hargas_count`, `bhps_count` |
| GET | `/tindakans/{id}` | master.kelola | + `kategori`, `hargas[].cabang {id,kode,nama,is_active}`, `bhps[].obat {id,kode,nama,satuan,is_active}` |
| POST / PUT | `/tindakans`, `/tindakans/{id}` | master.kelola | lihat payload; respons = bentuk detail |
| DELETE | `/tindakans/{id}` | master.kelola | soft delete; ditolak bila pernah dipakai kunjungan |
| GET | `/kategori-tindakans` | login | **array**. `aktif=1`: `{id, nama}` aktif; tanpa filter: lengkap + `tindakans_count`. `status`, `q` |
| POST / PUT / DELETE | `/kategori-tindakans`, `/kategori-tindakans/{id}` | master.kelola | `{ nama*, deskripsi, is_active }` |

Payload treatment:
```json
{
  "kode": "TRT-001", "nama": "Botulinum toxin dahi & glabella", "kategori_id": 5,
  "durasi_menit": 30, "buffer_menit": 10, "tarif": 3500000, "is_active": true,
  "hargas": [{ "cabang_id": 2, "tarif": 3250000, "tersedia": true }, { "cabang_id": 3, "tarif": 0, "tersedia": false }],
  "bhps": [{ "obat_id": 21, "jumlah": 0.3 }, { "obat_id": 24, "jumlah": 2 }]
}
```

Bentuk list: `{ id, kode, nama, kategori_id, durasi_menit, buffer_menit, tarif, is_active, tarif_cabang, tersedia, hargas_count,
bhps_count, kategori: {id, nama} | null }`.

## Seeder demo

7 kategori (Pemeriksaan Penunjang, Tindakan Umum, Perawatan Gigi, Kesehatan Ibu & Anak, Injeksi Estetika, Laser & Energy Device,
Facial & Peeling), 13 tindakan lama + 6 treatment estetika (`TRT-001` botox, `TRT-002` filler, `TRT-011` laser toning, `TRT-012` IPL,
`TRT-021` facial acne, `TRT-022` chemical peeling) dengan durasi/buffer, 4 bahan BHP baru (`OBT-021` botulinum toxin 100U vial,
`OBT-022` filler HA 1 ml, `OBT-023` krim anestesi, `OBT-024` spuit 1 ml; stok minimum khusus). Seeder hanya berjalan di
database kosong — database yang sudah berisi data tidak mendapat kategori & treatment demo.

## Frontend

- `views/master/TindakanView.vue` (menu Master Data → **Treatment**, `/master/tindakan`): filter kategori & status, kolom durasi
  (tindakan + buffer), harga dasar, harga di cabang aktif (bila ada), jumlah harga khusus & BHP. Form: info utama, grid harga per
  cabang (Harga dasar / Harga khusus / Tidak dilayani), BHP standar (cari obat, jumlah desimal).
- `views/master/KategoriTindakanView.vue` (`/master/kategori-treatment`, MasterCrud).
- `PemeriksaanView`: pilihan tindakan `GET /tindakans?aktif=1&cabang_id={kunjungan.cabang_id}`, estimasi memakai `tarif_cabang`,
  menampilkan durasi & kategori.
- Audit log: label jenis data `kategori_tindakan`, `tindakan_harga`, `tindakan_bhp`.

## Belum dikerjakan (modul berikutnya)

- Aturan komisi per treatment (KM-01), kebutuhan resource/ruang/alat per treatment untuk kalender (BK-01), template SOAP &
  informed consent per treatment (RM-01, RM-03), kode ICD-9-CM (RM-02), instruksi pasca tindakan (ES-04), HPP (IN-06).
- Tarif konsultasi poli masih satu harga untuk semua cabang.

## Test

`tests/Feature/KatalogTreatmentTest.php` — CRUD kategori & larangan hapus, simpan treatment dengan harga cabang & BHP (replace-all,
audit per baris), validasi, list dengan harga cabang aktif / `cabang_id` / `aktif=1`, snapshot tarif cabang di pemeriksaan & tagihan
serta penolakan treatment yang tidak dilayani, hapus treatment & obat BHP. Suite lulus di SQLite dan PostgreSQL 17.
