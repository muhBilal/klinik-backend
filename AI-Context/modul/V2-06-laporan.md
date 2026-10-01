# V2-06 — Laporan Penjualan, Paket, Konsolidasi Cabang & Dashboard

**PRD v2:** LP-01 (dashboard harian: no-show, top treatment, per cabang), LP-02 (penjualan per treatment, dokter, cabang, metode bayar),
LP-03 (paket terjual, terpakai, sisa kewajiban / pendapatan diterima di muka), AD-01 (laporan konsolidasi), LP-06 sebagian (ekspor CSV)
· **Status:** selesai. Belum: ekspor Excel/PDF berformat & jurnal akuntansi (LP-06 sisa), LP-04/LP-05 (Fase 2).

## Aturan

- **Periode penjualan = tanggal bayar** (`tagihans.dibayar_at`). **Refund** mengurangi periode **refund terjadi** (`dibatalkan_at` pada tagihan
  yang pernah dibayar). Void tagihan yang belum dibayar tidak muncul.
- **Ringkasan**: transaksi, bruto (`total`), diskon, promo, pajak, neto (`grand_total`), refund (grand total tagihan yang direfund), bersih.
- **Per treatment / kategori**: nilai baris = subtotal × porsi setelah diskon & promo (sebelum pajak) — jumlah semua kategori = neto − pajak.
  Baris sesi paket (Rp 0) dihitung sebagai `sesi_paket`, bukan pendapatan (pendapatannya di laporan paket).
- **Per dokter**: dokter penanggung jawab kunjungan (`kunjungans.dokter_id`); tagihan mandiri = "Penjualan langsung".
- **Per metode**: `pembayarans.jumlah` per metode menurut `dibayar_at`; refund = baris yang `dikembalikan_at` di rentang.
- **Per cabang** (AD-01): pengguna lintas cabang tanpa pilihan cabang melihat semua cabang; selebihnya cabang aktif.
- **Paket** (LP-03): terjual = paket yang aktif (lunas) di rentang (paket hasil pengalihan tidak dihitung sebagai penjualan); terpakai = sesi
  di kunjungan (tidak batal) di rentang × `nilai_per_sesi`; **kewajiban** per tanggal `sampai` = Σ sisa sesi × nilai per sesi untuk paket
  aktif yang belum direfund/dialihkan & belum lewat masa berlaku; paket kedaluwarsa yang masih bersisa dilaporkan terpisah (potensi diakui).
- Rentang maksimal 1 tahun. Izin `laporan.keuangan` (kasir, manajer, admin).

## Perbaikan terkait (ditemukan saat menyusun laporan per metode)

Baris pembayaran tunai dulu menyimpan uang yang **diserahkan** (termasuk kembalian) → rekap per metode & `kas_seharusnya` shift berlebih
sebesar kembalian. Kini `pembayarans.jumlah` = nilai bersih yang masuk kas; kolom baru `diterima` = uang yang diserahkan (struk).
Migration `2026_10_01_160001` mengoreksi data lama (kembalian dikurangkan dari baris tunai, mulai dari yang terakhir).

## API

```
GET /api/laporan/penjualan?dari=&sampai=&kelompok=treatment|kategori|dokter|metode|cabang[&format=csv]
GET /api/laporan/paket?dari=&sampai=[&format=csv]        CSV = daftar paket pasien dengan sisa kewajiban
GET /api/dashboard                                         + booking{hari_ini, no_show_30_hari{hadir, tidak_hadir, persen}},
                                                             top_treatment[5], per_cabang[] (lintas cabang tanpa pilihan cabang)
```

CSV: pemisah `;`, BOM UTF-8 (langsung terbaca Excel berbahasa Indonesia).

## Frontend

`/laporan` (`kasir/LaporanView`, menu Keuangan → Laporan): tab Penjualan (preset rentang, kelompok, kartu ringkasan, tabel) & Paket
(ringkasan, per paket, daftar sisa kewajiban); tombol **Unduh CSV** (axios blob). Dashboard: kartu booking hari ini + no-show 30 hari
(merah ≥ 10%, target PRD), top treatment, tabel per cabang.

## Test

`tests/Feature/LaporanTest.php` (penjualan per treatment/kategori/metode/dokter/cabang + refund + CSV + izin; paket terjual/terpakai/
kewajiban & kedaluwarsa bersisa; dashboard no-show, top treatment, per cabang) dan `KasirTest::test_kembalian_tunai_tidak_ikut_rekap_kas`.
