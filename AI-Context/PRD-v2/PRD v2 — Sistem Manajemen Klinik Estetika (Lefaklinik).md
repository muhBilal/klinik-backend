# PRD v2 — Sistem Manajemen Klinik Estetika (Lefaklinik)

Versi 2.0 · 1 Okt 2026 · revisi dari PRD v1 (30 Sep 2026, @Thoriq)

## 0. Riwayat revisi & perubahan dari v1

| Versi | Tanggal | Ringkasan |
| --- | --- | --- |
| 1.0 | 30 Sep 2026 | PRD awal + analisis gap terhadap SIM klinik umum (eKlinik) |
| 2.0 | 1 Okt 2026 | Status implementasi per ID, kebutuhan baru, kriteria penerimaan untuk P0 yang tersisa, roadmap yang diperbarui |

**Yang berubah di v2**

1. **Nama produk** menjadi **Lefaklinik** (sebelumnya eKlinik). Nama teknis `eklinik` di kode tidak diubah.
2. **Kolom Status** ditambahkan ke setiap tabel kebutuhan (bagian 5–7), menggambarkan kode per 1 Okt 2026.
3. **Bagian 8 ditulis ulang.** Analisis gap v1 sudah usang karena Fase 0 dan sebagian besar Fase 1 sudah dikerjakan.
4. **Kebutuhan baru** ditandai **(baru)**:
   - BK-08 master ruang/alat dan kebutuhan resource per treatment
   - BK-09 status no-show dan pembatalan
   - PS-08 penggabungan pasien duplikat
   - Modul baru 5.14 Integrasi SATUSEHAT (SS-01..06)
   - AD-05..AD-10, dari regulasi dan temuan teknis
5. **Kriteria penerimaan** (bagian 9) untuk setiap P0 yang belum selesai, agar bisa langsung dikerjakan dan di-UAT.
6. **Kebijakan yang sudah diputuskan** lewat implementasi dipindahkan dari "pertanyaan terbuka" ke bagian 11.2, misalnya kebijakan paket.
7. **Placeholder** v1 (`[embedded content: ...]`) diganti tabel nyata.

**Legenda status**

| Status | Arti |
| --- | --- |
| ✅ Selesai | Backend + frontend + test, siap UAT |
| 🟦 Backend saja | API, aturan bisnis dan test selesai; halaman frontend belum ada sehingga belum bisa dipakai pengguna |
| 🟨 Parsial | Sebagian kebutuhan terpenuhi; kekurangannya ditulis di kolom catatan |
| ⬜ Belum | Belum dikerjakan |
| ⛔ Terblokir | Menunggu pihak ketiga (kredensial, merchant account), bukan pekerjaan kode |

## 1. Ringkasan produk

Lefaklinik adalah sistem manajemen klinik untuk klinik estetika dan spesialis rawat jalan: dermatologi & venereologi, estetika
medis (injeksi, laser, facial), dan kedokteran gigi. Satu aplikasi menangani perjalanan pasien dari booking hingga kontrol ulang,
termasuk Rekam Medis Elektronik (RME) yang patuh Permenkes 24/2022 dan terhubung ke SATUSEHAT.

**Model tenant:** satu instalasi (satu database) melayani satu organisasi klinik dengan banyak cabang. Multi-tenant lintas organisasi
dalam satu database tidak didukung. Untuk model SaaS, setiap organisasi mendapat instalasi sendiri.

**Masalah yang diselesaikan**

- Jadwal dokter, terapis, ruang, dan alat (laser, dental chair) masih dikelola manual atau di WhatsApp, sehingga sering bentrok dan banyak no-show.
- Paket treatment multi-sesi (mis. 6x laser, 10x facial) sulit dilacak sisa sesinya dan sering jadi sengketa dengan pasien.
- Dokumentasi foto before-after tersebar di HP staf, tanpa persetujuan pasien, berisiko melanggar UU PDP.
- Stok consumable mahal (filler, botox, anestesi) tidak terikat ke tindakan, sehingga selisih stok dan pemborosan tidak terdeteksi.
- Komisi dokter/terapis dihitung manual di spreadsheet setiap akhir bulan.
- Retensi pasien bergantung pada ingatan staf, bukan reminder otomatis.

**Pembeda dari SIM klinik umum:** sisi komersial (paket, membership, promo, komisi, CRM) sama pentingnya dengan sisi klinis. Klinik
estetika hidup dari kunjungan ulang, bukan kunjungan sakit.

## 2. Tujuan & metrik keberhasilan

Target diukur 6 bulan setelah klinik go-live, dibandingkan baseline 1 bulan sebelum go-live. Angka target adalah usulan awal dan perlu
divalidasi dengan klinik pilot. Kolom **Sumber data** (baru di v2) menunjukkan dari mana sistem menghitung KPI; KPI yang sumbernya
belum ada harus dibangun sebelum go-live agar baseline bisa diukur.

| Tujuan | KPI | Target | Sumber data di sistem | Siap diukur? |
| --- | --- | --- | --- | --- |
| Kurangi no-show | % booking yang tidak datang | turun dari ~20% ke < 10% | `appointments.status = tidak_hadir` (BK-09) | Data ada; laporan di LP-01 belum |
| Naikkan kunjungan ulang | % pasien kembali dalam 90 hari | +15 poin | `kunjungans` per pasien | Laporan LP-05 belum |
| Percepat alur front office | Waktu registrasi pasien lama | < 2 menit | Uji waktu saat UAT | Manual |
| Percepat kasir | Waktu dari selesai tindakan ke lunas | < 5 menit | Waktu tutup pemeriksaan → `tagihans.dibayar_at` | Data ada; laporan belum |
| Akurasi stok | Selisih stok opname consumable bernilai tinggi | < 2% | Mutasi `penyesuaian` hasil opname (IN-05) | Data ada; laporan belum |
| Komisi tanpa spreadsheet | Waktu tutup buku komisi bulanan | dari hari ke < 1 jam | Modul komisi (KM-01/03) | Belum |
| Kepatuhan RME | % kunjungan dengan RME lengkap & terkirim ke SATUSEHAT | > 98% | `pemeriksaans.ditandatangani_at` + status kirim SATUSEHAT (SS-05) | Bagian SATUSEHAT belum |
| Adopsi booking online | % booking dari kanal online (web/WA) | > 40% | `appointments.sumber` (perlu kolom kanal) | Fase 2 |

## 3. Persona & peran pengguna

Peran di sistem bersifat dinamis: izin per peran bisa diatur admin (AD-02). Tabel berikut adalah peran bawaan.

| Peran | Kebutuhan utama | Akses kunci |
| --- | --- | --- |
| Owner / direktur klinik | Omzet, margin per treatment, performa cabang & dokter | Dashboard semua cabang, laporan keuangan, persetujuan diskon besar |
| Manajer cabang | Jadwal, target harian, stok, komplain | Laporan cabang, jadwal staf, stok opname, void/refund (`kasir.void`) |
| Dokter (SpKK/Sp.D.V.E, SpKG/drg, dokter estetika) | Riwayat pasien cepat, template SOAP, foto before-after, e-resep | RME, foto, resep, informed consent, tanda tangan RME (SIP aktif) |
| Asisten dokter / perawat **(diperjelas)** | Tanda vital, persiapan tindakan, membantu tindakan | Tanda vital, catatan tindakan, bagian komisi sebagai asisten (KM-01) |
| Terapis / beautician | Daftar tindakan hari ini, catatan tindakan, pemakaian bahan | Catatan tindakan, BHP, sisa sesi paket |
| Front office / CS | Booking, registrasi, antrean, reminder | Kalender, data pasien (non-klinis), WhatsApp |
| Kasir | Tagihan, pembayaran split, deposit, pemakaian voucher | Billing, kas harian, penjualan paket (identitas pasien saja, bukan RME) |
| Apoteker / gudang | Resep, stok per batch & expired, mutasi antar cabang | Farmasi, inventori, purchase order |
| Marketing / CRM | Segmentasi pasien, kampanye, promo | Data kontak (sesuai consent), promo, laporan kampanye |
| Pasien | Booking sendiri, lihat sisa paket & poin, riwayat treatment | Portal/app pasien (Fase 2) |

Hak akses wajib memisahkan data klinis dari data komersial: CS, kasir, dan marketing tidak boleh melihat isi rekam medis (izin
`rme.lihat`). Kunjungan dengan diagnosa IMS/HIV berakses terbatas, termasuk bagi staf klinis yang tidak menangani pasien tersebut.

## 4. Ruang lingkup

**In-scope**

- Klinik rawat jalan estetika & spesialis: dermatologi, estetika medis, gigi; mendukung satu atau banyak cabang.
- Web app untuk staf (desktop & tablet), portal/app pasien untuk booking dan melihat paket.
- Integrasi SATUSEHAT, payment gateway/QRIS, WhatsApp Business API, dan akuntansi (ekspor jurnal).

**Out-of-scope (fase ini)**

- Rawat inap, IGD, kamar operasi besar.
- Klaim BPJS Kesehatan (klinik estetika mayoritas pasien umum). Dapat dibuka jika klinik gigi bermitra BPJS.
- Akuntansi penuh (buku besar, pajak). Sistem hanya mengirim jurnal ke software akuntansi.
- E-commerce produk skincare dengan pengiriman (hanya penjualan produk di kasir).
- Teledermatologi video call (kandidat fase 3).
- Multi-tenant lintas organisasi dalam satu database **(baru)**.

## 5. Kebutuhan fungsional

ID dipakai di status implementasi (bagian 8) dan kriteria penerimaan (bagian 9). P0 wajib ada sebelum klinik pilot go-live. Kolom
**Modul** merujuk dokumen teknis di `backend/AI-Context/modul/`.

### 5.1 Booking & penjadwalan

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| BK-01 | Kalender multi-resource: dokter, terapis, ruang, alat (laser, dental chair); cegah double-booking | P0 · MVP | 🟦 Backend saja | F1-02. Pengecekan bentrok petugas + ruang/alat sudah ada; halaman kalender belum |
| BK-02 | Durasi slot otomatis dari treatment yang dipilih plus buffer sterilisasi | P0 · MVP | 🟦 Backend saja | Slot = Σ (durasi + buffer) |
| BK-03 | Jadwal praktik, shift & cuti dokter/terapis per cabang | P0 · MVP | 🟦 Backend saja | Pola mingguan, jadwal tambahan, cuti |
| BK-04 | Booking online via web/portal: pilih cabang, dokter, treatment, slot | P1 · Fase 2 | ⬜ Belum | |
| BK-05 | DP booking untuk treatment tertentu; hangus saat no-show sesuai kebijakan | P1 · Fase 2 | ⬜ Belum | Butuh BL-08 |
| BK-06 | Reminder otomatis H-1 dan 2 jam sebelum via WhatsApp, dengan tombol konfirmasi/reschedule | P0 · MVP | ⛔ Terblokir | Kredensial WhatsApp Business API. Queue & scheduler siap |
| BK-07 | Waiting list untuk mengisi slot dari pembatalan | P2 · Fase 3 | ⬜ Belum | |
| BK-08 **(baru)** | Master ruang & alat per cabang, plus kebutuhan ruang/alat **wajib** per treatment; booking hanya menawarkan ruang/alat yang cocok | P0 · MVP | 🟨 Parsial | API & tabel `tindakan_sumber_dayas` ada; UI master dan isian di katalog belum; booking belum memaksakan kecocokan |
| BK-09 **(baru)** | Status booking `dijadwalkan → dikonfirmasi → hadir / batal / tidak_hadir` dengan alasan batal, sebagai dasar KPI no-show | P0 · MVP | 🟦 Backend saja | |

### 5.2 Registrasi & data pasien

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| PS-01 | Master pasien: NIK, nama, tanggal lahir, kontak, alamat, No. RM otomatis | P0 · MVP | ✅ Selesai | Pasien milik pusat, lintas cabang |
| PS-02 | Pencarian cepat (nama, HP, No. RM, NIK) dan deteksi duplikat | P0 · MVP | 🟨 Parsial | Pencarian ada; deteksi duplikat baru sebatas NIK unik |
| PS-03 | Alergi, riwayat obat, tipe kulit Fitzpatrick, status hamil/menyusui tampil sebagai peringatan | P0 · MVP | ⬜ Belum | Alergi masih teks bebas |
| PS-04 | Consent pemrosesan data dan opt-in marketing terpisah (UU PDP) | P0 · MVP | ⬜ Belum | Consent foto (FT-04) sudah ada dan bisa jadi pola |
| PS-05 | Lookup IHS Number pasien ke SATUSEHAT via NIK | P0 · MVP | ⛔ Terblokir | Lihat SS-03 |
| PS-06 | Sumber pasien (referral, Instagram, Google, promo) untuk atribusi marketing | P1 · Fase 2 | ⬜ Belum | |
| PS-07 | Relasi keluarga untuk berbagi paket/membership | P2 · Fase 3 | ⬜ Belum | |
| PS-08 **(baru)** | Penggabungan (merge) dua data pasien duplikat, dengan seluruh kunjungan, paket, foto, dan RME pindah ke satu No. RM serta tercatat di audit | P1 · Fase 2 | ⬜ Belum | |

### 5.3 Antrean & alur kunjungan

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| AN-01 | Status kunjungan: booking → check-in → konsultasi → tindakan → kasir → selesai | P0 · MVP | 🟦 Backend saja | Check-in booking → kunjungan ada di API; UI booking belum |
| AN-02 | Layar antrean dan panggilan per ruang | P1 · Fase 2 | ⬜ Belum | Tombol panggil di daftar antrean sudah ada |
| AN-03 | Satu kunjungan memuat beberapa tindakan oleh beberapa petugas | P0 · MVP | 🟨 Parsial | Satu petugas per baris tindakan sudah ada. **Diperjelas v2:** satu baris tindakan bisa punya beberapa petugas dengan peran (dokter, terapis, asisten), sebagai dasar split komisi KM-01 |

### 5.4 Rekam Medis Elektronik (RME)

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| RM-01 | SOAP dengan template per spesialisasi dan per treatment | P0 · MVP | ✅ Selesai | F1-05 |
| RM-02 | Diagnosis ICD-10 dan tindakan ICD-9-CM, dengan daftar favorit dokter | P0 · MVP | ✅ Selesai | Data ICD masih seed terbatas (62 kode ICD-9-CM); impor master lengkap sebelum go-live (AD-10) |
| RM-03 | Informed consent digital per tindakan, ditandatangani pasien di tablet | P0 · MVP | ✅ Selesai | Naskah di-snapshot, tanda tangan terenkripsi |
| RM-04 | Foto klinis terstruktur terikat ke kunjungan | P0 · MVP | ✅ Selesai | F1-06 |
| RM-05 | Catatan tindakan: area, dosis/unit, produk & batch, parameter alat | P0 · MVP | ✅ Selesai | |
| RM-06 | Treatment plan multi-sesi dengan estimasi biaya yang bisa dikonversi ke paket | P1 · Fase 2 | 🟨 Parsial | Sudah ada untuk gigi (DG-02); belum untuk kulit/estetika dan belum bisa dikonversi ke paket |
| RM-07 | RME dikunci setelah ditandatangani dokter; koreksi hanya via addendum ber-audit | P0 · MVP | ✅ Selesai | Hash keutuhan + endpoint verifikasi |
| RM-08 | Resume medis, surat keterangan, surat rujukan dalam PDF | P1 · Fase 2 | ⬜ Belum | |

### 5.5 Katalog treatment, paket & membership

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| TR-01 | Master treatment: kategori, durasi, harga per cabang, BHP standar, aturan komisi | P0 · MVP | 🟨 Parsial | Semua selesai kecuali aturan komisi (→ KM-01) |
| TR-02 | Paket multi-sesi: bayar di muka, sisa sesi terlacak, masa berlaku, transfer/refund sesuai kebijakan | P0 · MVP | ✅ Selesai | F1-08; kebijakan jadi pengaturan (11.2) |
| TR-03 | Bundling produk + treatment | P1 · Fase 2 | ⬜ Belum | |
| TR-04 | Membership bertingkat dengan diskon dan benefit | P1 · Fase 2 | ⬜ Belum | |
| TR-05 | Poin loyalty: perolehan dan penukaran | P1 · Fase 2 | ⬜ Belum | |
| TR-06 | Voucher & promo: kode, periode, kuota, syarat minimum, per cabang/treatment | P0 · MVP | ✅ Selesai | |
| TR-07 | Saldo deposit dan gift card pasien | P2 · Fase 3 | ⬜ Belum | |

### 5.6 Farmasi & resep

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| FR-01 | E-resep dari dokter ke farmasi, termasuk racikan (krim/salep racik dermatologi) | P0 · MVP | 🟨 Parsial | E-resep non-racikan selesai; racikan belum ada |
| FR-02 | Peringatan alergi dan interaksi obat saat meresepkan | P1 · Fase 2 | ⬜ Belum | Peringatan alergi bergantung PS-03 |
| FR-03 | Cetak etiket dan aturan pakai | P0 · MVP | ✅ Selesai | |
| FR-04 | Penjualan produk skincare/OTC tanpa resep langsung di kasir | P0 · MVP | ✅ Selesai | Lewat tagihan mandiri |

### 5.7 Inventori & bahan habis pakai (BHP)

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| IN-01 | Stok per gudang/cabang, per batch dan tanggal kedaluwarsa (FEFO) | P0 · MVP | 🟦 Backend saja | Halaman obat masih menampilkan stok ringkasan; tampilan batch & kedaluwarsa belum |
| IN-02 | Potong stok otomatis dari tindakan dengan koreksi pemakaian aktual oleh petugas | P0 · MVP | ✅ Selesai | Editor BHP di modal catatan tindakan |
| IN-03 | Satuan fraksional: vial botulinum lintas pasien, filler per ml, pelacakan vial terbuka | P0 · MVP | ✅ Selesai | |
| IN-04 | Purchase order, penerimaan barang, retur ke supplier | P1 · Fase 2 | ⬜ Belum | |
| IN-05 | Mutasi antar cabang, stok opname, alert stok minimum dan mendekati kedaluwarsa | P1 · Fase 2 | 🟨 Parsial | Opname per batch & alert kedaluwarsa ada di API; mutasi antar cabang belum |
| IN-06 | HPP per treatment untuk laporan margin | P1 · Fase 2 | ⬜ Belum | |

### 5.8 Billing & kasir

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| BL-01 | Tagihan otomatis dari kunjungan: tindakan, resep, produk, pemakaian sesi paket | P0 · MVP | ✅ Selesai | |
| BL-02 | Diskon per item/total dengan batas per peran; di atas batas perlu approval | P0 · MVP | 🟨 Parsial | Batas per peran ada (diskon di atas batas ditolak); alur approval belum (lihat 9.6) |
| BL-03 | Pembayaran split: tunai, EDC, QRIS, transfer, deposit, voucher, poin | P0 · MVP | 🟦 Backend saja | API mendukung banyak metode; UI kasir masih satu metode. Deposit & poin menunggu TR-05/07 |
| BL-04 | Pembayaran bertahap untuk paket bernilai besar | P1 · Fase 2 | ⬜ Belum | |
| BL-05 | Buka/tutup shift kas dengan rekap per metode bayar | P0 · MVP | 🟦 Backend saja | |
| BL-06 | Void dan refund dengan approval dan jejak audit | P0 · MVP | 🟦 Backend saja | |
| BL-07 | Invoice/kuitansi PDF dikirim via WhatsApp/email | P1 · Fase 2 | ⬜ Belum | Cetak kuitansi sudah ada |
| BL-08 | Payment gateway untuk DP booking online dan pembayaran link | P1 · Fase 2 | ⛔ Terblokir | Merchant account |

### 5.9 Komisi & jasa medis

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| KM-01 | Aturan komisi per treatment per peran: persen atau nominal, split dokter–terapis–asisten | P0 · MVP | ⬜ Belum | Bergantung AN-03; kriteria di 9.1 |
| KM-02 | Komisi penjualan produk dan paket untuk CS/beautician | P1 · Fase 2 | ⬜ Belum | |
| KM-03 | Rekap dan slip komisi per periode, dikunci setelah disetujui | P0 · MVP | ⬜ Belum | |

### 5.10 CRM, marketing & notifikasi

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| CR-01 | WhatsApp Business API: reminder, konfirmasi, follow-up pasca tindakan (H+1, H+7) | P0 · MVP | ⛔ Terblokir | Kredensial WhatsApp Business API; kriteria di 9.5 |
| CR-02 | Recall otomatis sesuai siklus treatment (mis. botox 4–6 bulan, scaling gigi 6 bulan) | P1 · Fase 2 | ⬜ Belum | |
| CR-03 | Segmentasi pasien (RFM, treatment terakhir, ulang tahun) dan broadcast hanya ke yang opt-in | P1 · Fase 2 | ⬜ Belum | Bergantung PS-04 |
| CR-04 | Survei kepuasan/NPS dan tiket komplain | P1 · Fase 2 | ⬜ Belum | |
| CR-05 | Program referral pasien | P2 · Fase 3 | ⬜ Belum | |

### 5.11 Portal / aplikasi pasien

| ID | Kebutuhan | Prioritas | Status |
| --- | --- | --- | --- |
| PP-01 | Booking, reschedule, dan batal mandiri | P1 · Fase 2 | ⬜ Belum |
| PP-02 | Lihat sisa sesi paket, poin, voucher, riwayat treatment | P1 · Fase 2 | ⬜ Belum |
| PP-03 | Lihat foto before-after milik sendiri dan instruksi pasca tindakan | P2 · Fase 3 | ⬜ Belum |
| PP-04 | Isi formulir pra-kunjungan dan consent sebelum datang | P2 · Fase 3 | ⬜ Belum |

### 5.12 Laporan & dashboard

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| LP-01 | Dashboard harian: kunjungan, omzet, no-show, top treatment per cabang | P0 · MVP | 🟨 Parsial | Kunjungan, omzet, tertunda, stok rendah sudah ada; no-show & top treatment belum |
| LP-02 | Penjualan per treatment, dokter, cabang, metode bayar | P0 · MVP | ⬜ Belum | Kriteria di 9.3 |
| LP-03 | Laporan paket: terjual, terpakai, sisa kewajiban (deferred revenue) | P0 · MVP | ⬜ Belum | `nilai_per_sesi` sudah dihitung (F1-08) |
| LP-04 | Pemakaian BHP aktual vs standar per treatment | P1 · Fase 2 | ⬜ Belum | Data dua tahap BHP sudah tersimpan |
| LP-05 | Retensi dan kohort pasien, efektivitas kampanye | P1 · Fase 2 | ⬜ Belum | |
| LP-06 | Ekspor Excel/PDF dan jurnal ke software akuntansi | P1 · Fase 2 | ⬜ Belum | |

### 5.13 Administrasi & platform

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| AD-01 | Multi-cabang: master data pusat, harga dan stok per cabang, laporan konsolidasi | P0 · MVP | 🟨 Parsial | Cabang, scope transaksi, harga & stok per cabang selesai; laporan konsolidasi dan tarif konsultasi poli per cabang belum |
| AD-02 | RBAC granular; data klinis terpisah dari data komersial | P0 · MVP | ✅ Selesai | F0-01 |
| AD-03 | Audit log akses dan perubahan RME serta transaksi keuangan | P0 · MVP | ✅ Selesai | F0-03 |
| AD-04 | Pengaturan klinik: jam operasional, template dokumen, printer, pajak | P0 · MVP | 🟨 Parsial | Template dokumen selain struk/etiket belum |
| AD-05 **(baru)** | Data STR & SIP tenaga medis dengan peringatan H-60/H-30 sebelum kedaluwarsa; hanya SIP aktif yang menandatangani RME | P0 · MVP | 🟨 Parsial | Pemeriksaan SIP aktif saat tanda tangan sudah ada; STR & peringatan belum |
| AD-06 **(baru)** | Nomor notifikasi BPOM wajib di master produk skincare/obat yang dijual | P0 · MVP | ⬜ Belum | Regulasi 7.1 |
| AD-07 **(baru)** | Modul spesialisasi (face chart, odontogram, body chart) aktif per cabang lewat pengaturan | P1 · Fase 2 | 🟨 Parsial | Saat ini per poli (`polis.spesialisasi`) dan per jenis catatan treatment |
| AD-08 **(baru)** | Hak subjek data UU PDP: ekspor data pasien, permintaan koreksi, log pemrosesan; prosedur notifikasi kebocoran ≤ 3×24 jam | P1 · Fase 2 | ⬜ Belum | |
| AD-09 **(baru)** | Admin dapat me-reset 2FA pengguna lain (dengan audit) | P1 · Fase 2 | ⬜ Belum | Saat ini hanya lewat kode pemulihan |
| AD-10 **(baru)** | Impor master ICD-10 & ICD-9-CM lengkap dan master obat dari sumber resmi | P0 · MVP | ⬜ Belum | Data saat ini hanya seed dasar |

### 5.14 Integrasi SATUSEHAT **(modul baru di v2)**

Pada v1, SATUSEHAT hanya muncul di PS-05 dan regulasi. Karena KPI kepatuhan RME (> 98% terkirim) dan Permenkes 24/2022 bergantung
padanya, v2 menjadikannya modul tersendiri.

| ID | Kebutuhan | Prioritas | Status |
| --- | --- | --- | --- |
| SS-01 | Pengaturan kredensial per organisasi (client ID/secret, Organization ID) dan Location per cabang/ruang | P0 · MVP | ⛔ Terblokir |
| SS-02 | Pemetaan Practitioner: NIK tenaga medis → IHS Practitioner ID | P0 · MVP | ⛔ Terblokir |
| SS-03 | Lookup IHS Patient via NIK saat registrasi (= PS-05) | P0 · MVP | ⛔ Terblokir |
| SS-04 | Kirim Encounter, Condition, Observation (tanda vital), Procedure, MedicationRequest setelah RME ditandatangani | P0 · MVP | ⛔ Terblokir |
| SS-05 | Antrean kirim di queue dengan retry otomatis, status kirim per kunjungan, layar daftar gagal & kirim ulang manual | P0 · MVP | ⬜ Belum (bisa dikerjakan dengan sandbox) |
| SS-06 | Addendum RME dikirim sebagai pembaruan resource terkait | P1 · Fase 2 | ⬜ Belum |

Catatan: pekerjaan kode SS-05 dan kerangka SS-04 bisa dimulai dengan kredensial **sandbox** SATUSEHAT, yang lebih mudah didapat daripada
kredensial produksi.

## 6. Kebutuhan khusus per spesialisasi

Modul spesialisasi saat ini tampil berdasarkan poli dan jenis catatan treatment: odontogram hanya di poli gigi, face chart hanya untuk
treatment injeksi. Pengaktifan per cabang ada di AD-07.

### 6.1 Dermatologi & venereologi

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| DR-01 | Body chart: tandai lokasi lesi di peta tubuh dan bandingkan antar kunjungan | P1 · Fase 2 | ⬜ Belum | Pola koordinat face chart bisa dipakai ulang |
| DR-02 | Skor klinis terhitung otomatis: PASI, EASI/SCORAD, IGA akne, MASI | P1 · Fase 2 | ⬜ Belum | |
| DR-03 | Template SOAP akne, melasma, dermatitis, infeksi jamur; kasus IMS dengan akses terbatas | P0 · MVP | ✅ Selesai | |
| DR-04 | Impor foto dan hasil alat analisis kulit/dermatoskop | P2 · Fase 3 | ⬜ Belum | |

### 6.2 Estetika medis (injeksi, laser, facial)

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| ES-01 | Face chart injeksi: titik, unit/ml per titik, produk & batch, jarum/kanula | P0 · MVP | ✅ Selesai | Tampak depan; tampak kiri/kanan disiapkan |
| ES-02 | Parameter laser/energy device: alat, panjang gelombang, fluence, spot size, jumlah shot, reaksi kulit | P0 · MVP | ✅ Selesai | |
| ES-03 | Log pemakaian dan servis alat (shot counter, jadwal kalibrasi) | P2 · Fase 3 | ⬜ Belum | Jumlah shot sudah tercatat per tindakan |
| ES-04 | Instruksi pasca tindakan per treatment terkirim otomatis ke WhatsApp pasien | P1 · Fase 2 | ⬜ Belum | Bergantung CR-01 |
| ES-05 | Pencatatan komplikasi/adverse event dan tindak lanjutnya | P1 · Fase 2 | ⬜ Belum | |

### 6.3 Foto klinis before-after (lintas spesialisasi)

| ID | Kebutuhan | Prioritas | Status |
| --- | --- | --- | --- |
| FT-01 | Ambil foto dari tablet dengan template sudut standar (depan, 45° kiri/kanan, profil) | P0 · MVP | ✅ Selesai |
| FT-02 | Tampilan side-by-side dan slider before-after lintas kunjungan | P0 · MVP | ✅ Selesai |
| FT-03 | Simpan terenkripsi di server, tidak masuk galeri perangkat, akses tercatat | P0 · MVP | ✅ Selesai |
| FT-04 | Consent foto bertingkat: klinis saja / edukasi / marketing, bisa dicabut pasien | P0 · MVP | ✅ Selesai |

### 6.4 Kedokteran gigi

| ID | Kebutuhan | Prioritas | Status | Catatan |
| --- | --- | --- | --- | --- |
| DG-01 | Odontogram interaktif notasi FDI, status per gigi dan per permukaan (M/O/D/B/L) | P0 · MVP | ✅ Selesai | Daftar & warna kondisi wajib ditinjau drg. penanggung jawab |
| DG-02 | Treatment plan per gigi dengan fase dan estimasi biaya | P0 · MVP | ✅ Selesai | |
| DG-03 | Charting periodontal: kedalaman poket, BOP, mobilitas | P2 · Fase 3 | ⬜ Belum | |
| DG-04 | Lampiran dan viewer radiografi (periapikal, panoramik) | P1 · Fase 2 | 🟨 Parsial | Unggah lampiran terenkripsi sudah ada; viewer khusus (zoom, kontras) belum |
| DG-05 | Perawatan jangka panjang: ortodonti (kontrol bulanan + cicilan), PSA, implan | P1 · Fase 2 | ⬜ Belum | Bergantung BL-04 |
| DG-06 | Order dan tracking pekerjaan lab gigi (crown, gigi tiruan, aligner) | P1 · Fase 2 | ⬜ Belum | |
| DG-07 | Tindakan per gigi otomatis masuk tagihan | P0 · MVP | ✅ Selesai | |

## 7. Kebutuhan non-fungsional & regulasi

### 7.1 Regulasi yang wajib dipenuhi

Rujukan pasal di bawah disusun dari ingatan dan harus diverifikasi tim legal sebelum dipakai sebagai acuan kepatuhan.

| Regulasi | Dampak ke produk | Status |
| --- | --- | --- |
| Permenkes 24/2022 tentang Rekam Medis | RME wajib; disimpan minimal 25 tahun sejak kunjungan terakhir; interoperabel dengan SATUSEHAT; isi tidak boleh dihapus, hanya dikoreksi dengan jejak | 🟨 Penguncian, addendum, soft delete, audit selesai; SATUSEHAT terblokir (5.14) |
| Platform SATUSEHAT (Kemenkes) | Kirim data kunjungan format FHIR: Patient, Practitioner, Encounter, Condition, Procedure, Observation, MedicationRequest | ⛔ Lihat 5.14 |
| UU 27/2022 Pelindungan Data Pribadi | Data kesehatan dan foto wajah adalah data pribadi spesifik: consent eksplisit, hak akses/koreksi pasien, notifikasi kebocoran maks. 3×24 jam | 🟨 Consent foto selesai; consent data (PS-04) & hak subjek data (AD-08) belum |
| UU 17/2023 Kesehatan & aturan praktik tenaga medis | Simpan STR/SIP, peringatan sebelum SIP kedaluwarsa; hanya dokter ber-SIP aktif yang menandatangani RME | 🟨 Lihat AD-05 |
| Ketentuan BPOM | Produk skincare yang dijual harus punya nomor notifikasi BPOM di master produk | ⬜ Lihat AD-06 |

### 7.2 Non-fungsional

| Aspek | Kebutuhan | Status |
| --- | --- | --- |
| Keamanan | HTTPS wajib, password ter-hash, 2FA untuk admin dan dokter, session timeout 15 menit di perangkat bersama, rate limiting login | ✅ Token maks. 12 jam + idle timeout (default 15 menit), 2FA wajib per peran, rate limit. Catatan: token di `localStorage` (8.3 #1) |
| Enkripsi | Foto klinis dan lampiran terenkripsi saat disimpan; akses lewat URL bertanda tangan yang kedaluwarsa | ✅ Selesai. Belum: penyimpanan objek (S3/MinIO), enkripsi ulang saat rotasi `APP_KEY` |
| Ketersediaan | Uptime 99,5% pada jam operasional; mode terbatas saat internet putus untuk registrasi dan kasir | ⬜ Mode terbatas belum dirancang. **Diperjelas v2:** minimal antrean transaksi lokal (PWA) yang disinkronkan saat online, atau prosedur manual cadangan yang disepakati klinik pilot |
| Performa | Halaman utama < 2 detik; pencarian pasien < 1 detik pada 100.000 pasien | ⬜ Belum diuji beban. Perlu data uji 100.000 pasien dan indeks pencarian |
| Backup | Harian, RPO ≤ 24 jam, RTO ≤ 4 jam, uji restore tiap kuartal | ⬜ Belum ada skrip backup database + volume `berkas` |
| Skalabilitas | Minimal 50 cabang dan 500 pengguna aktif dalam satu tenant | ⬜ Belum diuji |
| Usability | Optimal di tablet untuk dokter dan terapis; bahasa Indonesia; format Rupiah dan tanggal lokal | ✅ Bahasa & format lokal; kamera & tanda tangan di tablet sudah diuji |
| Audit | Log akses dan perubahan tidak bisa diubah pengguna, disimpan sesuai masa retensi RME | ✅ Append-only |
| Observabilitas **(baru)** | Monitoring antrean job, notifikasi job gagal (SATUSEHAT, WhatsApp), log error terpusat | ⬜ Belum |
| Keamanan berkas **(baru)** | Pemindaian malware untuk berkas yang diunggah | ⬜ Belum |

## 8. Status implementasi per 1 Okt 2026

Bagian ini menggantikan "Analisis gap" v1. Status rinci dan riwayat pengerjaan ada di `backend/AI-Context/07-roadmap-progress.md`.

### 8.1 Ringkasan kebutuhan P0 v1 (51 kebutuhan)

| Status | Jumlah | ID |
| --- | --- | --- |
| ✅ Selesai | 26 | PS-01, RM-01/02/03/04/05/07, TR-02, TR-06, FR-03, FR-04, IN-02, IN-03, BL-01, AD-02, AD-03, DR-03, ES-01, ES-02, FT-01..04, DG-01, DG-02, DG-07 |
| 🟦 Backend saja | 8 | BK-01, BK-02, BK-03, AN-01, IN-01, BL-03, BL-05, BL-06 |
| 🟨 Parsial | 8 | PS-02, AN-03, TR-01, FR-01, BL-02, LP-01, AD-01, AD-04 |
| ⬜ Belum | 6 | PS-03, PS-04, KM-01, KM-03, LP-02, LP-03 |
| ⛔ Terblokir | 3 | BK-06, CR-01, PS-05 |

Dibanding v1 (2 selesai, 15 parsial, 34 belum), 26 P0 kini selesai dan 8 lainnya tinggal halaman frontend.

**P0 baru di v2:** BK-08 (🟨), BK-09 (🟦), AD-05 (🟨), AD-06 (⬜), AD-10 (⬜), SS-01..04 (⛔), SS-05 (⬜). Total P0 v2 = **61**.

### 8.2 Status per modul

| Modul | Status | Sudah ada | Yang kurang |
| --- | --- | --- | --- |
| Booking & penjadwalan | 🟦 | Appointment, slot dari durasi+buffer, bentrok petugas/ruang/alat, jadwal praktik & cuti, check-in → kunjungan, status no-show | **Semua halaman frontend**; BK-08 resource wajib per treatment; BK-06 reminder (terblokir) |
| Registrasi & pasien | 🟨 | Master pasien pusat, pencarian, No. RM otomatis, kartu paket & foto di detail pasien | PS-02 deteksi duplikat; PS-03 data klinis terstruktur; PS-04 consent UU PDP; PS-05 IHS (terblokir) |
| Antrean & kunjungan | 🟨 | Alur kunjungan → pemeriksaan → kasir → selesai; petugas per tindakan | AN-03 multi-petugas per tindakan; AN-02 layar antrean |
| RME | ✅ | Template SOAP, ICD-10/ICD-9-CM + favorit, consent, catatan tindakan, tanda tangan + hash + addendum, akses terbatas IMS | AD-10 master ICD lengkap; RM-06/08 (Fase 2) |
| Foto klinis | ✅ | Protokol posisi, kamera di aplikasi, thumbnail terenkripsi, slider before-after, consent bertingkat | — |
| Kedokteran gigi | ✅ | Odontogram FDI, rencana perawatan berfase, tindakan & tagihan per gigi | Fase 2: radiografi viewer, ortodonti, lab |
| Katalog, paket & promo | 🟨 | Kategori, durasi+buffer, harga per cabang, BHP standar, paket multi-sesi, voucher/promo | Aturan komisi; UI kebutuhan ruang/alat |
| Farmasi & resep | 🟨 | E-resep, serah obat setelah lunas, etiket, penjualan produk tanpa resep | FR-01 racikan |
| Inventori & BHP | 🟦 | Batch FEFO per cabang, BHP otomatis dua tahap, satuan fraksional, opname, alert kedaluwarsa | **Halaman batch, kedaluwarsa & opname per batch**; AD-06 BPOM |
| Billing & kasir | 🟦 | Split payment, shift kas, batas diskon per peran, void/refund, pajak, tagihan mandiri, promo | **UI split payment, shift kas, void/refund**; approval diskon |
| Komisi | ⬜ | Petugas pelaksana per tindakan (data dasar) | KM-01, KM-03 |
| CRM & notifikasi | ⛔ | Queue worker & scheduler | Job WhatsApp (terblokir kredensial) |
| Laporan & dashboard | 🟨 | Dashboard hari ini per cabang/semua cabang | LP-01 no-show & top treatment; LP-02; LP-03 |
| Administrasi & platform | 🟨 | Multi-cabang, RBAC dinamis, audit log, 2FA, pengaturan klinik | AD-04 template dokumen; AD-05 STR/SIP; AD-10 master data |
| SATUSEHAT | ⛔ | — | SS-01..05 |
| Portal pasien | ⬜ | — | Fase 2 |

### 8.3 Temuan teknis terbuka

Semua 7 temuan teknis v1 sudah ditutup (lihat `07-roadmap-progress.md`). Temuan baru, diurutkan berdasarkan risiko:

1. **Status "Selesai" di dokumen progres belum membedakan backend dan frontend.** Booking, kasir split/shift/void, dan inventori batch
   ditandai selesai padahal belum bisa dipakai pengguna. Pakai legenda status v2 agar UAT tidak salah sasaran.
2. **Token login masih di `localStorage`** (risiko XSS). Masa berlaku dan idle timeout sudah ada; pertimbangkan Sanctum SPA cookie
   mode bila frontend dan API satu domain di produksi.
3. **Belum ada satu pun job queue fitur** dan belum ada monitoring atau notifikasi job gagal. Ini prasyarat SS-05 dan CR-01.
4. **Data referensi masih seed** (ICD-10 terbatas, 62 ICD-9-CM, obat demo). Perlu impor master resmi (AD-10) sebelum UAT klinis.
5. **Berkas di disk lokal container** tanpa skrip backup, pemindaian malware, atau perintah enkripsi ulang saat rotasi `APP_KEY`.
   Kehilangan `APP_KEY` berarti semua foto, tanda tangan, dan secret 2FA tidak bisa dibuka.
6. **Tarif konsultasi poli** masih satu harga untuk semua cabang (harga treatment sudah per cabang).
7. **Belum ada uji beban** untuk target performa 7.2 (100.000 pasien).

## 9. Kriteria penerimaan P0 yang belum selesai

Kriteria berikut menjadi definisi selesai untuk UAT. Setiap modul juga wajib: feature test, audit log untuk perubahan data penting,
izin RBAC, dokumen di `AI-Context/modul/`, dan pembaruan `07-roadmap-progress.md`.

### 9.1 Komisi & jasa medis (KM-01, KM-03, AN-03)

- Satu baris tindakan kunjungan bisa punya beberapa petugas, masing-masing dengan peran (`dokter`, `terapis`, `asisten`).
- Aturan komisi per treatment per peran: persen **atau** nominal; bisa ditimpa per cabang; aturan default per kategori bila treatment
  tidak punya aturan sendiri.
- **Dasar perhitungan** dapat diatur: harga sebelum atau sesudah diskon. Pajak tidak pernah masuk dasar.
- Treatment yang memakai sesi paket dihitung dari `nilai_per_sesi` paket saat sesi dipakai, bukan Rp 0.
- Komisi dihitung saat tagihan kunjungan **lunas**. Refund tagihan otomatis membuat baris komisi negatif di periode berjalan.
- Rekap per periode per petugas; status `draf → disetujui`. Setelah disetujui periode terkunci, dan koreksi hanya lewat penyesuaian di
  periode berikutnya.
- Slip komisi per petugas bisa dicetak/PDF. Petugas hanya melihat slipnya sendiri.
- Waktu tutup buku satu cabang (≤ 30 petugas, ≤ 3.000 tindakan/bulan) < 1 jam termasuk review.

### 9.2 Data pasien, consent UU PDP & duplikat (PS-02, PS-03, PS-04)

- Alergi terstruktur (zat/obat, reaksi, tingkat keparahan), riwayat obat rutin, tipe kulit Fitzpatrick I–VI, status hamil/menyusui
  dengan tanggal pembaruan.
- Peringatan tampil di header pemeriksaan, catatan tindakan, dan saat meresepkan (dasar FR-02).
- Consent pemrosesan data (wajib saat registrasi) dan opt-in marketing (opsional) adalah dua catatan terpisah, ditandatangani,
  di-snapshot, bisa dicabut, dan tidak pernah dihapus (pola sama dengan consent foto FT-04).
- Pasien tanpa opt-in marketing tidak pernah masuk daftar broadcast (CR-03).
- Saat registrasi, sistem menampilkan kandidat duplikat dengan kecocokan nama + tanggal lahir, atau nomor HP yang sama, sebelum pasien
  baru disimpan.

### 9.3 Laporan (LP-01, LP-02, LP-03, AD-01 konsolidasi)

- LP-01: tambahkan no-show (jumlah & %), top 5 treatment, dan perbandingan antar cabang untuk pengguna lintas cabang.
- LP-02: filter periode, cabang (atau semua cabang); dikelompokkan per treatment, dokter/petugas, cabang, metode bayar; angka bruto,
  diskon, promo, pajak, neto; refund mengurangi periode refund terjadi.
- LP-03: paket terjual (jumlah, nilai), sesi terpakai × nilai per sesi, sisa kewajiban per tanggal, paket kedaluwarsa dengan sisa
  (potensi pendapatan diakui).
- Semua laporan bisa diekspor CSV/Excel (bagian awal LP-06) dan dibatasi izin `laporan.keuangan` (sudah ada) dan cabang aktif pengguna.
- Angka laporan cocok dengan rekap shift kas untuk periode yang sama (uji rekonsiliasi saat UAT).

### 9.4 Resep racikan (FR-01)

- Satu item resep bisa berupa racikan: nama racikan, bentuk (krim, salep, kapsul, puyer), jumlah, komponen (obat + kuantitas) dan
  aturan pakai.
- Stok setiap komponen dipotong per batch (FEFO) saat diserahkan; harga = Σ komponen + biaya racik (pengaturan).
- Etiket mencetak nama racikan dan aturan pakai.

### 9.5 Reminder & follow-up WhatsApp (BK-06, CR-01) — dikerjakan setelah kredensial tersedia

- Job terjadwal mengirim reminder H-1 dan 2 jam sebelum booking berstatus `dijadwalkan`/`dikonfirmasi`.
- Pesan memakai template resmi yang disetujui Meta; tombol balasan "Konfirmasi" dan "Ubah jadwal" mengubah status booking lewat webhook.
- Follow-up H+1 dan H+7 setelah tindakan sesuai pengaturan per treatment.
- Pesan hanya dikirim ke nomor yang tervalidasi; setiap pesan tercatat (status terkirim/dibaca/gagal) dan bisa dikirim ulang.
- Abstraksi pengirim (driver) agar fallback SMS/email bisa ditambahkan (risiko biaya di bagian 10).

### 9.6 Approval diskon (BL-02)

- Diskon di atas batas peran tidak langsung ditolak. Kasir bisa meminta persetujuan pemegang izin persetujuan diskon, baik di tempat
  (PIN/password manajer) maupun lewat notifikasi di aplikasi.
- Persetujuan tercatat di audit dengan nama penyetuju, nilai, dan alasan.

### 9.7 Frontend untuk modul yang sudah ada di backend

| Halaman | Kebutuhan | Kriteria utama |
| --- | --- | --- |
| Kalender booking | BK-01..03, BK-09, AN-01 | Tampilan hari/minggu per petugas dan per ruang; buat/ubah booking dengan slot yang tersedia; konfirmasi, batal, tidak hadir, check-in; nyaman di tablet |
| Jadwal praktik & cuti | BK-03 | Pola mingguan, jadwal tambahan, cuti per petugas per cabang |
| Master ruang & alat | BK-08 | CRUD per cabang; kebutuhan ruang/alat wajib di form treatment |
| Kasir lanjutan | BL-03, BL-05, BL-06 | Beberapa metode bayar dalam satu pembayaran; buka/tutup shift dengan rekap & selisih; void/refund dengan alasan untuk pemegang `kasir.void` |
| Inventori batch | IN-01, IN-05 | Daftar batch per obat & cabang, kedaluwarsa terdekat, penerimaan batch baru, opname per batch |

### 9.8 Regulasi & master data (AD-05, AD-06, AD-10)

- Master tenaga medis: nomor & masa berlaku STR dan SIP per cabang praktik. Peringatan di dashboard H-60 dan H-30; SIP kedaluwarsa
  memblokir tanda tangan RME (sudah) dan penjadwalan booking baru untuk dokter tersebut.
- Master produk: nomor notifikasi BPOM wajib untuk kategori skincare/kosmetik yang dijual; validasi format.
- Perintah impor ICD-10, ICD-9-CM, dan master obat dari berkas resmi; idempoten, tidak menghapus kode yang sudah dipakai.

## 10. Roadmap & prioritas rilis

Setiap fase ditutup oleh gerbang; fase berikutnya dimulai setelah kriteria gerbang terpenuhi. Estimasi durasi menunggu keputusan tim
dan jadwal klinik pilot.

| Fase | Isi | Status | Gerbang |
| --- | --- | --- | --- |
| **0 — Fondasi** | Multi-cabang, audit log & soft delete, keamanan sesi & 2FA, queue & scheduler, berkas terenkripsi, pengaturan klinik, RBAC dinamis | Selesai 30 Sep 2026 | Fondasi lolos review keamanan — **menunggu review** |
| **1 — MVP estetika (P0)** | Seluruh P0 bagian 5–7 | Berjalan (26/51 P0 v1 selesai) | Seluruh P0 lolos UAT, go-live klinik pilot |
| **2 — Komersial & pasien (P1)** | Booking online & DP, portal pasien, membership, poin, cicilan, payment gateway, CRM & recall, laporan lanjutan, PO & mutasi antar cabang, modul klinis P1 | Belum | **(baru)** KPI bagian 2 tercapai sebagian di klinik pilot selama 3 bulan, dan klinik kedua go-live |
| **3 — Lanjutan (P2)** | Waiting list, deposit/gift card, referral, relasi keluarga, periodontal, log alat, impor alat analisis kulit, teledermatologi | Belum | — |

### 10.1 Urutan sisa Fase 1

| # | Pekerjaan | Kebutuhan | Bergantung pada |
| --- | --- | --- | --- |
| 1 | Frontend booking, jadwal, master ruang/alat + resource wajib per treatment | BK-01..03, BK-08, BK-09, AN-01 | — |
| 2 | Frontend kasir split payment, shift kas, void/refund + approval diskon | BL-02, BL-03, BL-05, BL-06 | — |
| 3 | Frontend inventori batch & opname | IN-01, IN-05 (sebagian) | — |
| 4 | Multi-petugas per tindakan + komisi | AN-03, KM-01, KM-03, TR-01 | 2 |
| 5 | Data klinis pasien, consent UU PDP, deteksi duplikat | PS-02, PS-03, PS-04 | — |
| 6 | Laporan dashboard, penjualan, paket, konsolidasi | LP-01..03, AD-01 | 2, 4 |
| 7 | Racikan, STR/SIP, BPOM, impor master data, template dokumen | FR-01, AD-04, AD-05, AD-06, AD-10 | — |
| 8 | SATUSEHAT: antrean kirim + resource FHIR (sandbox → produksi) | SS-01..05, PS-05 | Kredensial sandbox, lalu produksi |
| 9 | Reminder & follow-up WhatsApp | BK-06, CR-01 | Kredensial WhatsApp Business API |
| 10 | Non-fungsional pra go-live: backup & uji restore, uji beban, monitoring job, review keamanan | 7.2 | Semua di atas |

Pengurusan kredensial pihak ketiga (#8, #9, payment gateway) dimulai **sekarang**, paralel dengan #1–#7, karena prosesnya di luar kendali tim.

## 11. Risiko, asumsi, keputusan & pertanyaan terbuka

### 11.1 Risiko

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Kebocoran foto wajah pasien | Sanksi UU PDP, reputasi klinik hancur | Enkripsi, URL bertanda tangan, kamera di aplikasi (tidak masuk galeri), audit akses (FT-03) — **sudah diterapkan** |
| Integrasi SATUSEHAT molor karena kredensial & mapping kode | Klinik tidak patuh Permenkes 24/2022 | Daftarkan Organization ID sekarang; kerjakan SS-05 dengan sandbox; antrean kirim ulang otomatis |
| Dokter menolak input RME karena lambat | Data klinis kosong, KPI kepatuhan gagal | Template per treatment, favorit diagnosis, input tablet (sudah); uji waktu input < 3 menit saat UAT |
| Aturan komisi tiap klinik berbeda-beda | Kustomisasi tak berujung | Mesin aturan komisi yang bisa dikonfigurasi (9.1), bukan hard-code |
| Biaya WhatsApp Business API per pesan | Margin langganan tergerus | Batasi template per paket langganan; abstraksi driver untuk fallback SMS/email |
| **(baru)** Kehilangan atau rotasi `APP_KEY` tanpa prosedur | Seluruh foto, tanda tangan, dan 2FA tidak bisa dibuka | Simpan `APP_KEY` di secret manager dengan cadangan offline; perintah enkripsi ulang; `APP_PREVIOUS_KEYS` saat rotasi |
| **(baru)** Backend jauh mendahului frontend | UAT tertunda, umpan balik pengguna terlambat sehingga desain API sulit diubah | Prioritaskan frontend (10.1 #1–#3) sebelum modul backend baru |
| **(baru)** Data referensi (ICD, odontogram, naskah consent) belum ditinjau klinisi/legal | Kesalahan klinis atau consent tidak sah | Tinjauan drg., SpKK, dan legal sebelum UAT |

### 11.2 Keputusan yang sudah diambil

| Topik | Keputusan |
| --- | --- |
| Nama produk | Lefaklinik (nama teknis `eklinik` tetap) |
| Model tenant | Satu instalasi = satu organisasi, banyak cabang |
| Data pusat vs cabang | Pasien, No. RM, katalog, ICD, peran, pengaturan bersifat pusat; kunjungan, resep, tagihan, booking, stok, kas per cabang |
| Kebijakan paket | Diatur per organisasi lewat pengaturan: refund penuh bila belum dipakai; refund prorata sisa (default tidak) dengan potongan persen; transfer ke pasien lain (default tidak); perpanjangan masa berlaku oleh pemegang izin dengan alasan |
| Pemakaian sesi paket di tagihan | Baris Rp 0 dengan keterangan sesi ke-n; nilai per sesi dialokasikan saat paket lunas (untuk LP-03 dan komisi) |
| Promo vs diskon manual | Terpisah; promo tidak terkena batas diskon per peran |
| Tanda tangan RME | Menyatu dengan "selesai pemeriksaan" (satu klik), hanya dokter ber-SIP aktif |
| Consent foto | Per pasien (bukan per kunjungan), bertingkat klinis ⊂ edukasi ⊂ marketing |
| Modul spesialisasi | Ditentukan spesialisasi poli dan jenis catatan treatment; per cabang menyusul (AD-07) |

### 11.3 Asumsi

- Mayoritas pasien adalah pasien umum (bayar sendiri), bukan BPJS.
- Klinik pilot memiliki koneksi internet stabil dan minimal satu tablet per ruang tindakan.
- Model bisnis SaaS berlangganan per cabang, dengan satu instalasi per organisasi.
- Produksi berjalan di HTTPS (wajib untuk kamera di aplikasi dan keamanan token).

### 11.4 Pertanyaan terbuka

- [ ] Klinik pilot mana (kulit, gigi, atau campuran) dan kapan target go-live?
- [ ] Apakah klinik gigi pilot bermitra BPJS, sehingga klaim BPJS masuk scope?
- [ ] Software akuntansi apa yang dipakai klinik target (Jurnal, Accurate, lainnya)?
- [ ] Apakah dibutuhkan aplikasi mobile native, atau cukup web responsif untuk portal pasien?
- [ ] **(baru)** Komisi: dasar perhitungan sebelum atau sesudah diskon? Komisi paket dihitung saat sesi dipakai (usulan v2) atau saat paket terjual?
- [ ] **(baru)** Approval diskon: PIN manajer di tempat, persetujuan jarak jauh, atau keduanya?
- [ ] **(baru)** Mode offline: cukup prosedur manual cadangan, atau wajib PWA dengan sinkronisasi?
- [ ] **(baru)** Lokasi hosting dan data center (pertimbangan UU PDP dan latensi), serta siapa pemegang `APP_KEY` produksi?
- [ ] **(baru)** Siapa drg., SpKK, dan konsultan legal yang meninjau daftar kondisi odontogram, template SOAP, dan naskah consent?
- [ ] **(baru)** Biaya racik dan aturan harga racikan: tetap per resep atau per komponen?
