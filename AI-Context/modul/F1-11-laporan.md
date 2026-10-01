# F1-11 — Laporan Penjualan, Paket & Dashboard Harian

**PRD:** LP-01 (dashboard harian: kunjungan, omzet, no-show, top treatment per cabang), LP-02 (penjualan per treatment, dokter, cabang,
metode bayar), LP-03 (laporan paket: terjual, terpakai, sisa kewajiban / deferred revenue) · **Fase:** 1 (roadmap #13) ·
**Status:** selesai (backend + frontend, diuji E2E di browser dengan data demo).

Belum: ekspor Excel/PDF & jurnal akuntansi (LP-06, Fase 2 — kini cetak lewat browser), BHP aktual vs standar (LP-04), retensi/kohort
(LP-05), sisa kewajiban per tanggal historis (kini per hari ini), alokasi potongan promo khusus treatment ke baris yang eligible
(kini proporsional ke semua baris tagihan), komisi/HPP sebagai margin.

## Aturan hitung

- **Cakupan cabang:** cabang aktif; user lintas cabang tanpa pilihan cabang = semua cabang (sama seperti scope `DalamCabang`). Paket
  milik cabang penjualnya (`paket_pasiens.cabang_id`) walau sesi dipakai di cabang lain.
- **Penjualan = tagihan yang dibayar dalam periode** (`dibayar_at`), **termasuk yang kemudian direfund**. Refund dicatat sebagai pengurang di
  periode refund-nya (`dibatalkan_at` tagihan berstatus batal yang pernah dibayar) + pengembalian sisa paket (`paket_pasiens.refund_metode`
  terisi, `direfund_at`). Dengan begitu laporan periode lalu tidak berubah ketika ada refund belakangan.
- **Penjualan bersih** = total − diskon manual − potongan promo (sebelum pajak). **Total** = grand total (termasuk pajak).
- **Per treatment / kategori:** potongan tagihan dialokasikan **proporsional** ke tiap baris (`subtotal × (diskon + promo) / total`).
  Jumlah berbayar = baris bernilai > 0; sesi paket (ditagih Rp 0) dihitung terpisah dari `kunjungan_tindakans` pada kunjungan tuntas
  (`menunggu_pembayaran`/`selesai`) di periode, bernilai **nilai per sesi** paket. Jasa konsultasi = treatment (F1-09) ikut di tabel ini.
- **Per dokter:** dokter kunjungan tagihan; tagihan tanpa kunjungan = "Penjualan langsung" (produk, paket di kasir).
- **Per metode bayar:** diterima = pembayaran tagihan yang dibayar dalam periode, **tunai dikurangi kembalian** (`tagihans.kembalian`);
  dikembalikan = pembayaran yang direfund dalam periode (tunai dikurangi kembalian) + pengembalian sisa paket per metode.
- **Paket:** terjual = paket aktif (`aktif_at`) dalam periode, bukan hasil pengalihan, nilai bersih; pendapatan diakui = sesi pada
  kunjungan tuntas dalam periode × nilai per sesi; refund = paket direfund dalam periode (`refund_nominal`, penuh maupun sisa); **hangus**
  = paket aktif yang `berlaku_sampai` jatuh dalam periode (dan sudah lewat) dengan sisa sesi × nilai per sesi; **sisa kewajiban per hari
  ini** = sisa sesi paket aktif yang masih berlaku × nilai per sesi (sesi pada kunjungan yang masih terbuka belum dianggap terpakai);
  **segera kedaluwarsa** = paket bersisa yang berakhir ≤ 30 hari lagi (maks 50 baris).
- **Dashboard harian (LP-01):** + booking hari ini (tanpa yang batal) & no-show (`tidak_hadir`), top 5 treatment hari ini (jumlah tindakan
  kunjungan non-batal), dan bila melihat semua cabang: per cabang kunjungan, no-show, pendapatan (pendapatan hanya untuk `laporan.keuangan`).
- **Periode:** `mulai`/`selesai` `Y-m-d`, default awal bulan s.d. hari ini, `selesai ≥ mulai`, paling lama 366 hari (422 `selesai`).

## Perbaikan terkait: rekap shift kasir

Baris pembayaran tunai menyimpan **uang yang diserahkan pasien**; kembalian ada di `tagihans.kembalian`. Rekap shift sebelumnya
menjumlahkan baris mentah sehingga total, kas seharusnya, dan total refund **kelebihan sebesar kembalian** (selisih kas tampak minus).
`KasirService::rekapShift` kini mengurangkan kembalian tagihan tunai yang berlaku (dan yang direfund untuk `total_refund`). Data lama tidak
perlu diubah — rekap dihitung ulang setiap dibaca.

## API

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/laporan/penjualan` | laporan.keuangan | `?mulai=&selesai=` → `{ periode, cabang, ringkasan{transaksi, bruto, diskon, promo, penjualan_bersih, pajak, total, refund{transaksi, total, paket_sisa}, total_setelah_refund}, per_hari[], per_kategori[], per_treatment[{tindakan_id, kode, nama, kategori, jumlah, bruto, neto, sesi_paket, nilai_sesi_paket}], per_dokter[], per_cabang[], per_metode[{metode, diterima, dikembalikan, bersih}] }` |
| GET | `/laporan/paket` | laporan.keuangan | `?mulai=&selesai=` → `{ periode, cabang, ringkasan{terjual, nilai_terjual, sesi_dipakai, nilai_dipakai, refund, hangus, paket_aktif, sisa_sesi, sisa_kewajiban}, per_paket[], segera_kedaluwarsa[{id, no_paket, nama, pasien_id, pasien_nama, no_rm, berlaku_sampai, sisa_sesi, sisa_nilai}] }` |
| GET | `/dashboard` | login | + `booking{total, tidak_hadir}`, `top_treatment[{tindakan_id, nama, jumlah}]`, `per_cabang[{cabang_id, kode, nama, kunjungan, tidak_hadir, pendapatan}] \| null` |

Service `LaporanService` (penjualan, paket); controller `LaporanController` (validasi periode). Tanpa tabel baru.

Revisi 1 Okt 2026: neto per kategori/treatment memakai `tagihan_items.neto` (fallback proporsional untuk tagihan lama), sehingga cocok
dengan nilai paket. Sesi paket diakui sebagai pendapatan hanya dari paket berbayar (bukan `menunggu_bayar`, `dibatalkan`, atau direfund
penuh lewat tagihan — `refund_metode` kosong).

## Frontend

Detail: `frontend/AI-Context/08-fitur-fase-1.md` bagian F1-11. Keuangan → **Laporan Penjualan** (`/laporan/penjualan`) & **Laporan Paket**
(`/laporan/paket`), komponen `laporan/PeriodeFilter` (tanggal + pintasan Hari ini / 7 hari / Bulan ini / Bulan lalu), tombol Cetak;
dashboard + kartu Booking/no-show, Top treatment, Per cabang, pintasan Laporan penjualan.

## Data demo

`DemoSeeder` (lihat `01-overview.md` → Data demo) mengisi ±6 minggu transaksi sehingga dashboard & laporan langsung terisi.

## Test

`tests/Feature/LaporanTest.php` (3 test): penjualan per treatment (alokasi diskon proporsional), dokter (termasuk penjualan langsung),
cabang, kategori, per hari, metode bayar (tunai tanpa kembalian, refund per metode), refund di periode refund & periode lain kosong; rekap
shift tanpa kembalian (kas seharusnya, total refund); paket (terjual, sesi terpakai & nilai, sisa kewajiban, hangus, segera kedaluwarsa,
sesi paket di laporan treatment); hak akses (dokter 403), validasi periode (terbalik, > 1 tahun), dashboard (no-show, top treatment,
per cabang hanya untuk lintas cabang, pendapatan null tanpa izin). `tests/Feature/DemoSeederTest.php`: DemoSeeder versi 4 hari berjalan,
waktu simulasi dilepas, laporan & dashboard dua cabang terbaca, tidak menggandakan data bila dijalankan ulang.

E2E browser (data demo, Chrome headless): dashboard admin semua cabang (booking & no-show, top treatment, per cabang), laporan penjualan
bulan lalu (ringkasan, per hari, per treatment, metode, cabang), laporan paket (sisa kewajiban, hangus, segera kedaluwarsa), kasir punya
pintasan laporan, dokter dialihkan dari `/laporan/penjualan`; halaman komisi, pasien, kasir, antrian, farmasi tampil dengan data demo; mobile
390 px tanpa scroll horizontal. Temuan & perbaikan: **race** saat periode diganti sebelum permintaan pertama selesai (data periode lama
menimpa) — kini hanya respons permintaan terakhir yang dipakai (diuji dengan permintaan pertama diperlambat 3 detik).
