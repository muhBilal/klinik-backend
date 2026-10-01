# F1-08 — Paket Multi-sesi, Voucher & Promo

**PRD:** TR-02 (paket multi-sesi: bayar di muka, sisa sesi terlacak, masa berlaku, transfer/refund sesuai kebijakan), TR-06 (voucher &
promo: kode, periode, kuota, syarat minimum, per cabang/treatment), BL-01 (pemakaian sesi paket di tagihan kunjungan), sebagian BL-03
(voucher saat bayar) & LP-03 (data nilai paket untuk laporan) · **Fase:** 1 (roadmap #8) · **Status:** selesai (backend + frontend,
diuji E2E di browser).

Belum: BL-04 cicilan paket (Fase 2), TR-03 bundling produk, TR-04 membership, TR-05 poin, TR-07 deposit & gift card (Fase 3), PS-07
berbagi paket keluarga (Fase 3), RM-06 konversi rencana perawatan ke paket (Fase 2), harga paket per cabang, generator kode voucher
unik massal, laporan paket LP-03 (roadmap #13 — datanya sudah tersedia), komisi penjualan paket KM-02 (Fase 2).

## Keputusan desain

- **Paket = katalog + instance.** `pakets` (+ `paket_items`: treatment × jumlah sesi) adalah katalog; pembelian membuat `paket_pasiens`
  (+ `paket_pasien_items`) yang men-*snapshot* nama, harga, isi, masa berlaku & aturan cabang. Mengubah katalog tidak mengubah paket
  yang sudah dibeli.
- **Dua jalur penjualan (keputusan user, 1 Okt 2026):**
  - **Dipesan dokter/terapis dari pemeriksaan** (jalur utama): `POST /kunjungans/{id}/pakets` (izin `pemeriksaan.dokter` / `rme.tindakan`,
    kunjungan masih `menunggu`/`diperiksa`) → paket `menunggu_bayar` ber-`kunjungan_id`, tanpa tagihan sendiri. Saat dokter menutup
    pemeriksaan, `TagihanService::buatDariKunjungan` menambahkan baris `kategori=paket` ke tagihan kunjungan dan mengisi `tagihan_id` →
    pasien membayar **sekali** di kasir. Sesi pertama boleh dipakai di kunjungan yang sama (Rp 0; harganya ada di tagihan yang sama).
    Satu paket katalog sekali per kunjungan. Pesanan bisa dibatalkan selama kunjungan terbuka (frontend melepas & menyimpan dulu
    pemakaian sesinya); kunjungan dibatalkan → pesanan ikut batal; pasien tidak jadi di kasir → **Batalkan paket** di tagihan
    (`DELETE /tagihans/{t}/pakets/{p}`): paket batal, sesi hari itu kembali ke tarif normal, tagihan disusun ulang
    (`TagihanService::susunUlang`, kode promo dihitung ulang / dilepas). Kolom sesi paket tidak termasuk hash RME → tanda tangan tetap sah.
    Pesan & batal mengunci baris kunjungan (`Kunjungan::kunci`) sehingga berurutan dengan `selesai` (tidak ada pesanan yatim).
  - **Dijual langsung di kasir** (walk-in, F1-03): `POST /pasiens/{id}/pakets` → paket + tagihan mandiri berisi satu baris paket.
  Keduanya **aktif saat tagihan lunas** (`KasirService::bayar` → `PaketService::aktifkanDariTagihan`); masa berlaku sejak lunas. Tagihan
  dibatalkan → paket `dibatalkan`.
- **Pencatat sesi:** dokter atau perawat/terapis pemegang `rme.tindakan` (keputusan user); aturan kolaborasi di F1-05.
- **Sisa sesi dihitung, bukan counter.** Pemakaian = `kunjungan_tindakans.paket_pasien_item_id`. Sisa = jumlah sesi − Σ `jumlah` tindakan
  kunjungan yang memakainya, kecuali kunjungan `batal`. Tindakan di kunjungan yang masih terbuka ikut mengurangi sisa ("sedang dipakai")
  agar satu sesi tidak dipakai dua kunjungan; batal kunjungan / hapus tindakan otomatis melepas sesi. Validasi pemakaian mengunci baris
  paket (`lockForUpdate`).
- **Status turunan tanpa scheduler.** Yang disimpan: `menunggu_bayar`, `aktif`, `dibatalkan`, `direfund`, `dialihkan`. `habis` (sisa 0) dan
  `kedaluwarsa` (`berlaku_sampai` < hari ini) dihitung (`status_efektif`). Kedaluwarsa dicek terhadap **tanggal kunjungan**.
- **Tagihan kunjungan (BL-01):** tindakan bersesi paket → `harga 0`, deskripsi `"… · paket PKT… sesi 3/6"` (urutan dari pemakaian
  sebelumnya; dua sesi sekaligus = `sesi 3–4/6`). `tagihan_items.tindakan_id` kini diisi untuk semua baris tindakan.
- **Nilai paket untuk pendapatan diterima di muka (LP-03):** saat lunas `KasirService` menghitung neto tiap baris tagihan
  (`tagihan_items.neto`, sebelum pajak): potongan promo dibagi ke baris yang memenuhi syarat promo (sebanding subtotal), lalu diskon manual
  ke semua baris sebanding sisanya; pembulatan sisa terbesar (Σ neto = total − diskon − promo). `nilai` paket = neto baris paketnya, lalu
  dialokasikan ke tiap treatment sebanding tarif dasar × sesi → `nilai_per_sesi` (dibulatkan ke bawah). Nilai terpakai = sesi terpakai ×
  nilai per sesi. Tagihan gabungan (konsultasi + tindakan + paket) dengan promo khusus paket → seluruh potongan jatuh ke paket.
- **Kebijakan klinik (pertanyaan terbuka PRD) jadi pengaturan:**
  - paket belum dipakai → selalu bisa direfund penuh lewat refund tagihan (BL-06); sudah dipakai di kunjungan **lain** → refund tagihan
    ditolak (sesi yang dipakai di kunjungan milik tagihan itu sendiri ikut direfund);
  - `paket.refund_sisa` (default **tidak**): refund prorata sisa = Σ sisa × nilai per sesi − `paket.potongan_refund_persen`; dicatat di paket
    (metode tunai/transfer, shift kasir yang terbuka) dan mengurangi kas seharusnya shift bila tunai;
  - `paket.boleh_transfer` (default **tidak**): sisa sesi dialihkan ke pasien lain sebagai paket baru (harga 0, `nilai` = nilai sisa, masa
    berlaku sama, `dialihkan_dari_id`); paket asal `dialihkan`;
  - perpanjang masa berlaku: selalu boleh oleh pemegang izin, alasan tercatat audit (`perpanjang_paket`).
  Tindakan kebijakan butuh `kasir.void` (persetujuan manajer, seperti void/refund BL-06) dan ditolak bila paket sedang dipakai kunjungan
  terbuka.
- **Promo = satu kode, banyak pemakaian.** Voucher sekali pakai = kuota 1. Potongan persen (+ batas) atau nominal, dihitung dari nilai
  **item yang memenuhi syarat** (semua item, atau hanya treatment/paket tertentu). Dipasang ke tagihan belum bayar (`promo_id`,
  `diskon_promo`), **dihitung ulang & dikunci saat bayar** (kuota bisa habis sejak dipasang → 422 `kode`), dicatat di `promo_pemakaians`
  saat lunas; refund tagihan membatalkan pemakaian (kuota kembali). Potongan promo terpisah dari diskon manual → **tidak** terkena batas
  diskon per peran (BL-02); total keduanya ≤ total tagihan. Pajak dihitung dari nilai setelah diskon + promo.
- **Kasir butuh identitas pasien** untuk menjual paket → migration memberi `pasien.lihat` ke peran `kasir` (hanya identitas, bukan RME).
  Izin baru `promo.kelola` → manajer & marketing.

## Skema

Migration `2026_10_01_120001_create_paket_promo_tables` (data izin di migration yang sama). Diuji migrate → rollback → migrate di
PostgreSQL 17 dengan data demo. Revisi: `2026_10_01_160001_add_kunjungan_id_to_paket_pasiens_table` (pesanan dari pemeriksaan) &
`2026_10_01_160002_add_neto_to_tagihan_items_table` (neto per baris saat lunas) — diuji migrate → rollback → migrate di PostgreSQL 17.

| Tabel | Kolom |
|-------|-------|
| `pakets` | kode (unik), nama, deskripsi, harga, masa_berlaku_hari (null = tanpa batas), lintas_cabang, is_active, deleted_at |
| `paket_items` | paket_id, tindakan_id, jumlah_sesi. Unik `(paket_id, tindakan_id)` |
| `paket_pasiens` | no_paket (unik, prefix `penomoran.prefix_paket`), pasien_id, paket_id, cabang_id (pembelian), tagihan_id, **kunjungan_id** (dipesan dari pemeriksaan, null = jual langsung), nama/harga (snapshot), nilai (bersih, saat lunas), status, lintas_cabang, masa_berlaku_hari, aktif_at, berlaku_sampai, catatan, dibuat_oleh, dialihkan_dari_id/_at/_oleh, alasan_alih, refund_nominal/_metode/_referensi/_shift_id, direfund_at/_oleh, alasan_refund |
| `paket_pasien_items` | paket_pasien_id, tindakan_id, jumlah_sesi, nilai_per_sesi |
| `kunjungan_tindakans` (+) | **paket_pasien_item_id** (null on delete) |
| `promos` | kode (unik, `^[A-Z0-9_-]{3,30}$`), nama, deskripsi, jenis (`persen`/`nominal`), nilai, maks_potongan, min_transaksi, mulai, berakhir, kuota, kuota_per_pasien, cabang_ids / tindakan_ids / paket_ids (JSON, null = semua), is_active, created_by, deleted_at |
| `promo_pemakaians` | promo_id, tagihan_id, pasien_id, cabang_id, potongan, dipakai_at, dibatalkan_at |
| `tagihans` (+) | **promo_id**, **diskon_promo** |
| `tagihan_items` (+) | **tindakan_id**, **paket_id** |

Model baru: `Paket`, `PaketItem`, `PaketPasien` (`statusEfektif()`), `PaketPasienItem`, `Promo` (`semuaItem()`), `PromoPemakaian` (semua
Auditable). Enum: `StatusPaketPasien` (+ konstanta `HABIS`, `KEDALUWARSA`), `JenisPotongan`. Izin: `promo.kelola`.
Service: `PaketService` (jual, aktifkan/batal/refund penuh dari tagihan, `pastikanBisaDipakai`, `pemakaian`, `ringkas`, perpanjang,
alihkan, refundSisa, simulasi), `PromoService` (terapkan, lepas, hitung, catat/batalkan pemakaian). `KasirService` memanggil keduanya
secara *lazy* (`app(...)`) — `PaketService` sendiri bergantung pada `TagihanService` → `KasirService` (hindari dependensi melingkar).

## Aturan

### Paket
- Jual: izin `kasir.tagihan`; paket katalog aktif & berisi treatment. Tagihan dibuat di cabang aktif.
- Pesan dari pemeriksaan: `pemeriksaan.dokter` atau `rme.tindakan`; kunjungan cabang aktif berstatus `menunggu`/`diperiksa` (422 `kunjungan`);
  paket katalog aktif berisi treatment & belum dipesan di kunjungan itu (422 `paket_id`). Batal pesanan: hanya pesanan kunjungan itu (404),
  kunjungan terbuka (422 `kunjungan`), belum ditagihkan (422 `status` — lepas lewat kasir), sesinya tidak sedang dipakai (422 `status`).
- Lepas di kasir (`kasir.tagihan`): tagihan kunjungan `belum_bayar` yang memuat paket `menunggu_bayar` itu (404 bila paket bukan milik
  tagihan; 422 `status` untuk tagihan lunas, penjualan langsung, atau paket yang sudah batal).
- Pakai (`PUT /kunjungans/{id}/pemeriksaan` → `tindakans[].paket_pasien_item_id`): milik pasien kunjungan, status `aktif` (atau `menunggu_bayar` yang dipesan di kunjungan ini & belum ditagihkan), `berlaku_sampai`
  ≥ tanggal kunjungan, `lintas_cabang` atau cabang kunjungan = cabang pembelian, treatment sama, sisa ≥ `jumlah` (baris itu sendiri tidak
  dihitung). Gagal → 422 `tindakans.{i}.paket_pasien_item_id`.
- Refund tagihan penjualan/kunjungan: hanya bila belum ada sesi dipakai/dipesan di kunjungan lain → paket `direfund` (`refund_nominal` =
  nilai). Selain itu 422 `status`.
- Refund sisa / alihkan: paket aktif, tidak kedaluwarsa, masih bersisa, tidak sedang dipakai kunjungan terbuka; mengikuti pengaturan
  (422 `status` / `pasien_id`). Metode refund hanya `tunai`/`transfer`.
- Rekap shift (`GET /shift-kas/{id}`): `rekap.refund_paket`, `total_refund` termasuk refund paket, `kas_seharusnya` dikurangi refund tunai;
  `selisih` saat tutup shift juga memperhitungkannya.
- Katalog: hapus ditolak bila sudah pernah terjual (nonaktifkan). Treatment yang sama hanya satu baris per paket.

### Promo
- Kelola: `promo.kelola`. Kode dinormalkan huruf besar (`welcome10` → `WELCOME10`); daftar kosong (cabang/treatment/paket) = tidak dibatasi.
- Pasang/lepas: `kasir.tagihan`, tagihan `belum_bayar`. Syarat (422 `kode`): aktif & tidak terhapus, `mulai` ≤ hari ini ≤ `berakhir`,
  cabang tagihan termasuk, total ≥ `min_transaksi`, kuota total & per pasien (tagihan wajib ber-pasien), ada item memenuhi syarat.
- Hapus kode yang pernah dipakai ditolak (nonaktifkan).

## API

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/pakets` | login | **array**; `aktif=1`, `q`, `status`; + `items.tindakan`, `nilai_normal` (Σ tarif × sesi), `terjual_count` |
| POST / GET / PUT / DELETE | `/pakets`, `/{id}` | master.kelola | `{ kode*, nama*, deskripsi, harga*, masa_berlaku_hari (1–3650), lintas_cabang, is_active, items*[]{tindakan_id*, jumlah_sesi* (1–100)} }` |
| GET | `/pasiens/{id}/pakets` | pasien.lihat, kasir.tagihan, rme.tindakan, pemeriksaan.dokter | **array** terbaru dulu; `aktif=1` = bisa dipakai sekarang (+ `kunjungan_id=` → pesanan kunjungan itu); + `kunjungan`, `pembuat`. Item: `terpakai`, `dipesan`, `sisa`; paket: `total_sesi`, `sisa_sesi`, `nilai_terpakai`, `status_efektif` |
| POST | `/pasiens/{id}/pakets` | kasir.tagihan | `{ paket_id*, catatan }` → 201 paket + `tagihan_id` |
| POST | `/kunjungans/{id}/pakets` | pemeriksaan.dokter, rme.tindakan | `{ paket_id*, catatan }` → 201 paket `menunggu_bayar` + `kunjungan_id` (tanpa tagihan; ditagihkan saat pemeriksaan ditutup) |
| DELETE | `/kunjungans/{id}/pakets/{paketPasien}` | pemeriksaan.dokter, rme.tindakan | batalkan pesanan → paket `dibatalkan` |
| DELETE | `/tagihans/{id}/pakets/{paketPasien}` | kasir.tagihan | pasien tidak jadi: paket batal, sesi kembali tarif normal, tagihan disusun ulang → tagihan detail |
| GET | `/paket-pasiens/{id}` | sama dengan daftar | + `pemakaian[]` (tindakan kunjungan bukan batal), `refund_sisa` (simulasi) |
| POST | `/paket-pasiens/{id}/perpanjang` | kasir.void | `{ berlaku_sampai* (≥ hari ini, > lama), alasan* }` |
| POST | `/paket-pasiens/{id}/alihkan` | kasir.void | `{ pasien_id*, alasan* }` → 201 paket baru |
| POST | `/paket-pasiens/{id}/refund` | kasir.void | `{ metode*: tunai/transfer, referensi, alasan* }` |
| GET / POST / GET / PUT / DELETE | `/promos`, `/{id}` | promo.kelola | paginated; `q`, `status`, `berlaku=1`; + `dipakai`, `tindakans`, `pakets`, `cabangs` (nama). Payload: `{ kode*, nama*, deskripsi, jenis*, nilai*, maks_potongan, min_transaksi, mulai*, berakhir, kuota, kuota_per_pasien, cabang_ids[], tindakan_ids[], paket_ids[], is_active }` |
| POST / DELETE | `/tagihans/{id}/promo` | kasir.tagihan | POST `{ kode* }` → tagihan detail (perkiraan `grand_total`) |

Perubahan endpoint lama: detail tagihan + `promo`, `paket_pasiens`, `items[].tindakan_id/paket_id`, `diskon_promo`; daftar tagihan +
`diskon_promo`; detail kunjungan `tindakans[].paket_pasien_item_id` + `paket_item.paket_pasien.no_paket`; bayar memakai `diskon_promo`;
`POST /tagihans` (mandiri) tidak lagi untuk paket — pakai `/pasiens/{id}/pakets`.

## Pengaturan & izin

`paket.boleh_transfer` (false), `paket.refund_sisa` (false), `paket.potongan_refund_persen` (0), `penomoran.prefix_paket` (`PKT`) — menu
Pengaturan → Paket Treatment. Izin `promo.kelola` (manajer, marketing); `pasien.lihat` ditambahkan ke kasir.

## Frontend

Detail: `frontend/AI-Context/08-fitur-fase-1.md` bagian F1-08. Ringkas: Master Data → **Paket Treatment** (`/master/paket`), Keuangan →
**Voucher & Promo** (`/promo`), kartu Paket Treatment di detail pasien (jual, sisa, riwayat, perpanjang/alihkan/refund) & di samping
pemeriksaan (ringkas), pilihan "Pakai paket" per tindakan (otomatis bila ada sisa), kasir: "+ Jual paket", kode promo, tagihan tanpa
kunjungan, pajak di layar bayar. Revisi: tombol **Pesan paket** di kartu Tindakan pemeriksaan (dokter/terapis) + daftar pesanan (Batalkan),
estimasi memuat harga pesanan, kartu paket samping memuat pesanan kunjungan; kasir **Batalkan paket** pada tagihan kunjungan.

## Data demo

Paket: PKT-LSR6 Laser toning 6x (Rp 6 jt, 180 hari), PKT-GLOW facial 4x + peeling 2x (Rp 2 jt, 120 hari), PKT-SCL2 scaling 2x (Rp 450 rb,
365 hari). Promo: WELCOME10 (10% maks Rp 100 rb, min Rp 200 rb, 1×/pasien, 3 bulan), LASER200 (Rp 200 rb untuk treatment & paket laser,
kuota 50).

`DemoSeeder`: dokter/terapis memesan paket saat pemeriksaan (treatment laser/facial/peeling/scaling ±16% → sesi pertama hari itu juga;
sesekali paket untuk dimulai lain kali), sebagian kecil dibeli langsung di kasir; terapis mencatat tindakannya sendiri pada kunjungan
walk-in. Pada jam praktik, pasien yang sedang diperiksa di antrian hari ini punya pesanan paket.

## Test

`tests/Feature/PaketPromoTest.php` (14 test): katalog & nilai normal; aktif saat lunas + alokasi nilai per sesi; pemakaian di pemeriksaan
(treatment salah, melebihi sisa, simpan ulang, Rp 0 + urutan sesi, batal kunjungan melepas sesi, kedaluwarsa per tanggal kunjungan,
perpanjang + audit); paket belum lunas / milik pasien lain / tagihan batal; refund penuh vs sisa sesuai kebijakan + rekap shift; alihkan;
promo (validasi, normalisasi, minimum, persen + batas, lepas, diskon manual + promo, kuota per pasien, refund mengembalikan kuota, kuota
habis saat bayar, periode, cabang, pajak); promo per treatment/paket & nilai paket bersih. `PeranIzinTest` kini mengharapkan
`pasien.lihat` pada kasir. Revisi: pesan dari pemeriksaan & ditagih bersama kunjungan (sekali bayar → aktif, sesi 1 terpakai); batal pesanan /
ikut batal bersama kunjungan; terapis mencatat sesi (ICD-9-CM tetap, tutup tetap dokter); tagihan gabungan promo + diskon + refund; kasir
melepas paket; laporan paket hanya sesi berbayar. Kolaborasi dokter–terapis: `PemeriksaanKolaborasiTest`; komisi neto per baris:
`KomisiTest`; binding rute per cabang: `MultiCabangTest`.

E2E browser (Chrome headless, stack dev terisolasi): kasir menjual paket dari detail pasien → kode salah (422) → LASER200 → bayar → paket
aktif; daftar kasir (tagihan tanpa kunjungan, tombol jual paket); dokter menambah laser → otomatis "Pakai paket … tersedia 6 sesi" →
Rp 0 → selesai → tagihan "sesi 1/6 = 0"; manajer melihat riwayat, memperpanjang, refund sisa ditolak kebijakan; membuat kode promo;
admin master paket & pengaturan; mobile 390 px tanpa scroll horizontal. Tanpa error konsol (selain 422 kode salah yang disengaja).

E2E revisi (1 Okt 2026, `pesan-paket`): dokter pesan & batalkan PKT-GLOW, pesan PKT-LSR6 → baris laser otomatis memakai sesi 1 ("pesanan
baru"), estimasi Rp 6.100.000, mobile 390 px tanpa scroll horizontal; terapis: baris dokter terkunci kecuali pilihan paket, menambah facial,
tanpa tombol tutup/diagnosa; dokter menekan Selesai dari form lama → diminta memeriksa dulu (tindakan terapis tidak terhapus) → tutup; kasir
satu tagihan (konsultasi + sesi Rp 0 + facial + paket Rp 6 jt) → bayar → paket aktif 5 sesi; kunjungan kedua: kasir **Batalkan paket** →
tagihan disusun ulang (facial tarif normal). Tanpa emoji di halaman yang dikunjungi.
