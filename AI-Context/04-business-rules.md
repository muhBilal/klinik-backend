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
| `diperiksa` | `POST /kunjungans/{id}/selesai` (tutup + **tanda tangan RME**) | `menunggu_pembayaran` | pemeriksaan.dokter + SIP aktif | `PemeriksaanService::selesai` |
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
- Bila `pdp.wajib_persetujuan` aktif, pasien wajib punya persetujuan pemrosesan data (UU PDP) yang berlaku — juga saat check-in booking
  (422 `persetujuan_data`). Bawaan mati: pasien tetap bisa didaftarkan, ditandai di daftar pasien & form pendaftaran.
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
- `diagnosas` & `resep` bersifat **replace-all** bila key ada di payload (hapus lalu buat ulang). Key tidak dikirim = tidak diubah.
- `tindakans` di-**upsert**: baris lama dipertahankan bila cocok `tindakans[].id` atau (tanpa `id`) `tindakan_id` yang sama —
  catatan tindakan, consent, dan koreksi BHP-nya ikut bertahan; baris yang tidak dikirim dihapus per model beserta catatannya.
  Draft BHP dihitung ulang hanya bila `jumlah` berubah.
- Per tindakan: `icd9cm_id` (default dari katalog) dan `petugas_id` (default dokter yang mengisi / dokter kunjungan; harus petugas
  medis aktif di cabang kunjungan, 422 `tindakans.{i}.petugas_id`).
- `akses_terbatas` (dokter): kunjungan berakses terbatas. Otomatis `true` bila ada diagnosa ICD-10 `sensitif` (IMS/HIV), dan tidak
  bisa dilepas selama diagnosa itu ada.
- Diagnosa pertama tanpa `jenis` otomatis `primer`, sisanya `sekunder`.
- Tarif tindakan di-**snapshot** dari **harga cabang kunjungan** (harga khusus cabang, atau harga dasar) dan harga obat dari master saat disimpan.
- Treatment yang ditandai **tidak dilayani** di cabang kunjungan → 422 `tindakans.{i}.tindakan_id` (tindakan lama tidak dihapus). Treatment nonaktif/terhapus → 422.
- `resep: []` menghapus resep (jika masih `menunggu`). Resep yang sudah diproses farmasi tidak bisa diubah.

### Selesai pemeriksaan (`PemeriksaanService::selesai`)
- Status harus `diperiksa` dan minimal **satu diagnosa ICD-10**.
- Penutup harus dokter ber-**SIP aktif** (`users.sip` terisi, `sip_berlaku_sampai` kosong/≥ hari ini) → selain itu 422 `sip`
  (termasuk administrator).
- Treatment ber-template consent wajib punya **informed consent `disetujui`** (pengaturan `rme.wajib_informed_consent`,
  default aktif) → 422 `informed_consent` berisi daftar tindakan yang kurang / ditolak pasien.
- RME **ditandatangani** (`ditandatangani_at/_oleh`, `hash_ttd`) lalu terkunci; koreksi hanya lewat addendum.
- Membuat tagihan otomatis (`TagihanService::buatDariKunjungan`):
  jasa konsultasi (treatment `polis.tindakan_konsultasi_id`, harga cabang; dilewati bila poli tanpa jasa konsultasi, treatment nonaktif /
  tidak dilayani di cabang, atau sudah dicatat dokter sebagai tindakan) + tiap tindakan (`tarif × jumlah`) + tiap item resep (`harga × jumlah`).

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

### RME estetika (detail: [modul/F1-05-rme-estetika.md](modul/F1-05-rme-estetika.md))
- **Informed consent**: diambil selama pemeriksaan terbuka (izin `rme.tindakan`); naskah di-render server & di-snapshot, tanda tangan
  PNG terenkripsi; satu consent berlaku per tindakan; penarikan = `dicabut` (tidak dihapus).
- **Catatan tindakan**: area, catatan, petugas; face chart (titik + produk & batch milik cabang kunjungan) untuk treatment `injeksi`;
  parameter alat (kunci tertutup) + alat cabang untuk `energi`. Terkunci setelah pemeriksaan ditutup.
- **Addendum**: hanya setelah RME ditandatangani; dokter ber-SIP aktif; wajib `alasan`; tidak bisa diubah/dihapus; tidak mengubah hash.
- **Akses terbatas**: isi RME kunjungan terbatas hanya untuk `rme.terbatas`, tim yang tercatat menangani, atau tenaga pelayanan cabang
  itu selama pemeriksaan terbuka. Lainnya: tanpa RME (`rme_disembunyikan`), berkas kunjungan itu disembunyikan & tautannya 403.

### Foto klinis (detail: [modul/F1-06-foto-klinis.md](modul/F1-06-foto-klinis.md))
- Unggah `foto_klinis` butuh **persetujuan foto pasien yang berlaku** (pengaturan `foto.wajib_consent`, default aktif) → 422 `consent_foto`.
- Persetujuan foto per pasien, bertingkat (klinis ⊂ edukasi ⊂ marketing); tanda tangan baru menggantikan yang lama (`diganti`);
  pencabutan menghentikan foto baru, foto lama tetap bagian RME. Tidak pernah dihapus.
- Foto ber-protokol: `posisi` harus kode posisi protokol itu; `kunjungan_tindakan_id` harus tindakan kunjungan yang sama.
- Thumbnail dibuat & dienkripsi dari browser; tautan pratinjau (`t=1` ikut ditandatangani) & tautan massal tetap tercatat audit.

### Kedokteran gigi (detail: [modul/F1-07-odontogram.md](modul/F1-07-odontogram.md))
- Odontogram = kondisi per gigi (FDI) / per permukaan milik pasien, dicatat per kunjungan; diubah hanya saat pasien `diperiksa`
  (`pemeriksaan.dokter` atau `rme.tindakan`). Satu permukaan satu kondisi; kelompok eksklusif (keberadaan, mahkota, jembatan, protesa,
  pulpa) saling menggantikan; gigi hilang hanya menerima pengganti gigi. Kondisi lama diakhiri, bukan ditimpa; koreksi di kunjungan
  yang sama dihapus dan memulihkan yang digantikannya.
- Treatment `per_gigi` wajib nomor gigi; `kondisi_gigi_hasil` otomatis mencatat kondisi di odontogram (per permukaan → wajib permukaan)
  dan tidak bisa ditimpa manual di kunjungan yang sama. Tagihan menulis nomor gigi & permukaan per baris tindakan.
- Odontogram masuk hash tanda tangan RME dan terkunci setelah kunjungan ditutup.
- Rencana perawatan: draf → disetujui pasien (estimasi dikunci, ubah = revisi) → item dikerjakan via `rencana_item_id` → selesai saat
  kunjungan ditutup → rencana selesai otomatis. Estimasi = harga cabang saat disusun; tagihan = harga saat dikerjakan.
- Odontogram & rencana tampil untuk poli ber-`spesialisasi = gigi` (atau kunjungan yang punya data gigi).

### Paket multi-sesi & promo (detail: [modul/F1-08-paket-promo.md](modul/F1-08-paket-promo.md))
- Paket dipesan dokter/terapis dari pemeriksaan (ditagihkan bersama tagihan kunjungan, sesi pertama boleh di kunjungan yang sama, pasien bisa
  menolak di kasir) atau dijual langsung di kasir (tagihan mandiri); **aktif saat tagihan lunas** (masa berlaku sejak lunas). Sesi dipakai dari pemeriksaan
  (`paket_pasien_item_id`) → baris tagihan Rp 0 "paket … sesi n/N". Sisa = sesi − pemakaian di kunjungan bukan batal (kunjungan terbuka
  ikut mengurangi). Kedaluwarsa dicek terhadap tanggal kunjungan.
- Refund tagihan paket hanya bila belum ada sesi dipakai di kunjungan lain (sesi di kunjungan milik tagihan itu ikut direfund). Refund sisa prorata & pengalihan ke pasien lain hanya bila diizinkan pengaturan
  `paket.*`, oleh pemegang `kasir.void`; refund tunai mengurangi kas seharusnya shift.
- Kode promo dipasang sebelum bayar, diperiksa ulang & dikunci saat bayar, dicatat saat lunas, kuota kembali saat refund. Potongan promo
  di luar batas diskon per peran; diskon manual + promo ≤ total; pajak dari nilai setelah keduanya.
- Neto per baris (`tagihan_items.neto`, saat lunas): promo ke baris yang memenuhi syarat, diskon manual sebanding sisa → nilai paket, dasar
  komisi neto & laporan per item. Pendapatan sesi paket diakui hanya dari paket berbayar.
- Tindakan & sesi paket dicatat dokter atau `rme.tindakan`; sinkron sadar snapshot (`tindakan_ids_awal`), baris petugas lain terlindungi
  dari perawat/terapis, hanya kolom yang dikirim yang berubah, simpan/tutup/pesan berurutan per kunjungan (kunci baris). Detail: F1-05.
- Binding rute memakai cabang aktif request (middleware `cabang` sebelum `SubstituteBindings`) — data cabang lain tidak bisa dibuka lewat id.

### Data klinis pasien & UU PDP (detail: [modul/F1-10-data-klinis-pdp.md](modul/F1-10-data-klinis-pdp.md))
- Data klinis (alergi, Fitzpatrick, hamil/menyusui, riwayat obat & penyakit) terpisah dari identitas: baca `rme.lihat` (farmasi menerima
  alergi & status hamil lewat resep), ubah `pemeriksaan.vital`/`pemeriksaan.dokter`/`rme.tindakan`; milik pasien, tidak terkunci bersama RME.
- Hamil/menyusui hanya untuk perempuan, selalu bertanggal; alergi obat bisa bertaut master obat → peringatan resep (tidak memblokir).
- Persetujuan pemrosesan & opt-in marketing = baris terpisah; formulir baru mengganti yang lama, tidak bersedia promosi mencabut opt-in;
  cabut pemrosesan ikut mencabut marketing. Tidak pernah dihapus.

### Laporan (detail: [modul/F1-11-laporan.md](modul/F1-11-laporan.md))
- Penjualan = tagihan dibayar dalam periode (termasuk yang kemudian direfund); refund mengurangi di periode refund. Penjualan bersih =
  total − diskon − promo (sebelum pajak); potongan dialokasikan proporsional per baris; tunai tanpa kembalian.
- Paket: pendapatan diakui per sesi yang dikerjakan (nilai per sesi); sisa kewajiban = sisa sesi paket aktif yang masih berlaku; hangus =
  sisa sesi paket yang kedaluwarsa dalam periode.
- Rekap shift kas mengurangkan kembalian dari tunai (baris pembayaran tunai menyimpan uang yang diserahkan pasien).

### Komisi (detail: [modul/F1-09-komisi.md](modul/F1-09-komisi.md))
- Peran per tindakan: dokter = dokter kunjungan, terapis = pelaksana (`petugas_id`), asisten = `asisten_id`. Komisi diatur **per treatment
  per peran** di master treatment (`tindakan_komisis`; kosong = tanpa komisi; ubah butuh `master.kelola` + `komisi.kelola`). Jasa konsultasi
  = treatment poli → komisi dokter treatment itu.
- Rekap per cabang per periode dari tagihan kunjungan **lunas** (`dibayar_at`); dasar bruto/neto (`komisi.dasar`), sesi paket = nilai per sesi.
  Periode tidak boleh tumpang tindih. Draf bisa dihitung ulang & diberi penyesuaian; **disetujui = terkunci** (izin `komisi.setujui`).
- Tagihan Rp 0 boleh dilunasi tanpa pembayaran.

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
