# F1-04 — Inventori: batch, kedaluwarsa, BHP & satuan fraksional

**PRD:** IN-01 (stok per gudang/cabang, per batch & kedaluwarsa, FEFO), IN-02 (potong stok otomatis dari BHP standar
dengan koreksi pemakaian aktual), IN-03 (satuan fraksional & vial terbuka), AD-01 (stok per cabang) ·
**Fase:** 1 · **Status:** selesai untuk IN-01, IN-02, IN-03 dan alert kedaluwarsa.
Belum: purchase order & retur supplier (IN-04, Fase 2), mutasi antar cabang (IN-05, Fase 2), HPP per treatment (IN-06, Fase 2).

## Konsep

Stok nyata sekarang disimpan **per cabang, per batch** di `stok_batches`. Kolom `obats.stok` tetap ada sebagai
**total lintas cabang** (dipakai dashboard & alert stok minimum) dan dijaga tetap sinkron oleh `InventoriService`.
Stok kini bertipe desimal (12,3) agar pemakaian fraksional tidak dibulatkan.

**FEFO** (*first expired, first out*): pengeluaran mengambil batch dengan kedaluwarsa terdekat lebih dulu; batch tanpa
tanggal kedaluwarsa dipakai paling belakang. Satu pengeluaran boleh terbagi ke beberapa batch.

## Tabel

| Tabel | Isi |
|-------|-----|
| `stok_batches` | stok satu obat, satu cabang, satu batch: `no_batch`, `kedaluwarsa`, `jumlah`, `jumlah_awal`, `dibuka_at`, `kedaluwarsa_dibuka_at` |
| `kunjungan_tindakan_bhps` | pemakaian BHP nyata per tindakan: `jumlah_standar` (pembanding), `jumlah` (aktual), `batch_id`, `stok_dipotong` |

Kolom baru `obats`: `fraksional`, `jam_pakai_setelah_buka`. Kolom baru `stok_mutasis`: `cabang_id`, `batch_id`.

Migrasi memindahkan stok lama (satu angka global) ke batch tanpa nomor di cabang pertama, sehingga kartu stok dan
total tetap konsisten.

## Satuan fraksional (IN-03)

- `obats.fraksional = true` → boleh dipakai sebagian (0,3 vial botulinum; 0,2 tube krim anestesi).
  Obat non-fraksional menolak jumlah desimal.
- `jam_pakai_setelah_buka` → saat batch fraksional pertama kali disentuh, `dibuka_at` diisi dan
  `kedaluwarsa_dibuka_at = sekarang + N jam`. Sisa vial yang lewat masa itu tidak lagi ikut FEFO meski
  tanggal kedaluwarsa batchnya masih jauh. Ini yang dimaksud PRD dengan "pelacakan vial terbuka".

## Alur BHP (IN-02) — dua tahap

1. **Tindakan dicatat** di pemeriksaan → `BhpService::siapkanDariStandar()` membuat baris draft dari BHP standar
   katalog (`jumlah_standar` = `jumlah` = standar × banyaknya tindakan). Stok **belum** berkurang.
2. **Petugas mengoreksi** pemakaian aktual bila perlu (`PUT /api/kunjungan-tindakans/{id}/bhps`, replace-all).
3. **Pemeriksaan diselesaikan** → `BhpService::potongStok()` memotong stok FEFO dan menandai `stok_dipotong`.
   Idempoten, jadi aman dipanggil ulang.

Dipisah dua tahap supaya koreksi pemakaian tidak perlu membatalkan mutasi stok yang sudah tercatat.
Setelah dipotong, koreksi lewat endpoint BHP ditolak — selisih diselesaikan lewat stok opname.

UI koreksi pemakaian: tab **Pemakaian BHP** di modal catatan tindakan pemeriksaan ([F1-05](F1-05-rme-estetika.md)).
Sejak F1-05 tindakan kunjungan di-upsert, sehingga koreksi BHP tidak hilang saat pemeriksaan disimpan ulang (kecuali `jumlah`
tindakan diubah — draft dihitung ulang dari standar). Check-in booking juga menyiapkan draft BHP.

**Stok kurang saat pemeriksaan ditutup**: perilakunya mengikuti pengaturan `inventori.blokir_bhp_stok_kurang`.
Default `false` — pemeriksaan tetap bisa ditutup, baris ditinggal `stok_dipotong = false`, dan peringatan
dikembalikan ke petugas. Alasannya rekam medis & tagihan tidak boleh tersandera data stok yang belum rapi.
Klinik yang disiplin stoknya bisa menyalakan `true` agar pemeriksaan ditolak.

## Izin

`inventori.kelola` — penerimaan barang, batch, stok opname, pemakaian BHP.
Peran bawaan: apoteker, perawat, dokter, terapis (merekalah yang mengoreksi pemakaian setelah tindakan).

## Endpoint

```
GET  /api/stok-batches                 ?obat_id=&tersedia=1&habis=1&q=
GET  /api/stok-batches/kedaluwarsa     ?hari=30
POST /api/stok-batches                 obat_id, jumlah, no_batch?, kedaluwarsa?, keterangan?
POST /api/stok-batches/{id}/sesuaikan  jumlah, keterangan?          (stok opname)
POST /api/stok-batches/{id}/buang      keterangan?                  (buang sisa kedaluwarsa)

GET  /api/kunjungan-tindakans/{id}/bhps
PUT  /api/kunjungan-tindakans/{id}/bhps   bhps[]{obat_id,jumlah,batch_id?}   replace-all
```

Endpoint lama `/api/obats/{id}/mutasi` tetap ada: kini masuk/keluar lewat batch "tanpa nomor" di cabang aktif,
dan `penyesuaian` berarti stok akhir yang diinginkan **di cabang itu**.

## Kode

- `app/Services/InventoriService.php` — terima, keluarkan (FEFO), sesuaikan, buang kedaluwarsa, alert, jaga total.
- `app/Services/BhpService.php` — draft dari standar, koreksi, potong stok.
- `app/Services/FarmasiService.php` — penyerahan resep kini lewat batch FEFO di cabang resep.
- `app/Http/Controllers/Api/{StokBatchController, BhpController}.php`

## Data demo

Stok awal tiap obat dibagi **dua batch** dengan kedaluwarsa berbeda (6 bulan & 18 bulan) supaya FEFO terlihat.
Obat fraksional: `OBT-021` botulinum (24 jam setelah dibuka), `OBT-022` filler, `OBT-023` krim anestesi.

## Jebakan

- Jangan mengubah `obats.stok` langsung — selalu lewat `InventoriService` agar batch, total, dan kartu stok konsisten.
- `obats.stok` kini **float**; assertion test yang membandingkan dengan `assertSame` harus ikut float
  (JSON tetap mengirim bilangan bulat tanpa desimal).
- `StokMutasi` punya `cabang_id` & `batch_id` di `#[Fillable]` — kolom yang lupa didaftarkan akan diam-diam null.

## Test

`tests/Feature/InventoriTest.php` — FEFO ambil kedaluwarsa terdekat, pengeluaran terbagi ke batch berikutnya,
batch kedaluwarsa dilewati, stok terpisah per cabang, non-fraksional menolak desimal, vial terbuka punya masa pakai,
BHP standar memotong stok saat pemeriksaan selesai, koreksi pemakaian sebelum dipotong, penerimaan & stok opname,
daftar batch akan kedaluwarsa.
