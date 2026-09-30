# PRD — Sistem Manajemen Klinik Estetika (eKlinik)

Sep 30, 2026 · @Thoriq

## 1. Ringkasan produk

eKlinik adalah sistem manajemen klinik untuk klinik estetika dan spesialis rawat jalan: dermatologi & venereologi, estetika medis (injeksi, laser, facial), dan kedokteran gigi. Satu aplikasi menangani perjalanan pasien dari booking online hingga kontrol ulang, termasuk Rekam Medis Elektronik (RME) yang patuh Permenkes 24/2022 dan terhubung ke SATUSEHAT.

**Masalah yang diselesaikan**

- Jadwal dokter, terapis, ruang, dan alat (laser, dental chair) masih dikelola manual atau di WhatsApp, sehingga sering bentrok dan banyak no-show.
- Paket treatment multi-sesi (mis. 6x laser, 10x facial) sulit dilacak sisa sesinya dan sering jadi sengketa dengan pasien.
- Dokumentasi foto before-after tersebar di HP staf, tanpa persetujuan pasien, berisiko melanggar UU PDP.
- Stok consumable mahal (filler, botox, anestesi) tidak terikat ke tindakan, sehingga selisih stok dan pemborosan tidak terdeteksi.
- Komisi dokter/terapis dihitung manual di spreadsheet setiap akhir bulan.
- Retensi pasien bergantung pada ingatan staf, bukan reminder otomatis.

**Pembeda dari SIM klinik umum:** sisi komersial (paket, membership, promo, komisi, CRM) sama pentingnya dengan sisi klinis. Klinik estetika hidup dari kunjungan ulang, bukan kunjungan sakit.

## 2. Tujuan & metrik keberhasilan

Target diukur 6 bulan setelah klinik go-live, dibandingkan baseline 1 bulan sebelum go-live. Angka target adalah usulan awal dan perlu divalidasi dengan klinik pilot.

| Tujuan | KPI | Target |
| --- | --- | --- |
| Kurangi no-show | % booking yang tidak datang | turun dari \~20% ke < 10% |
| Naikkan kunjungan ulang | % pasien kembali dalam 90 hari | +15 poin |
| Percepat alur front office | Waktu registrasi pasien lama | < 2 menit |
| Percepat kasir | Waktu dari selesai tindakan ke lunas | < 5 menit |
| Akurasi stok | Selisih stok opname consumable bernilai tinggi | < 2% |
| Komisi tanpa spreadsheet | Waktu tutup buku komisi bulanan | dari hari ke < 1 jam |
| Kepatuhan RME | % kunjungan dengan RME lengkap & terkirim ke SATUSEHAT | > 98% |
| Adopsi booking online | % booking dari kanal online (web/WA) | > 40% |

## 3. Persona & peran pengguna

| Peran | Kebutuhan utama | Akses kunci |
| --- | --- | --- |
| Owner / direktur klinik | Omzet, margin per treatment, performa cabang & dokter | Dashboard semua cabang, laporan keuangan, persetujuan diskon besar |
| Manajer cabang | Jadwal, target harian, stok, komplain | Laporan cabang, jadwal staf, stok opname |
| Dokter (SpKK, SpKG/drg, dokter estetika) | Riwayat pasien cepat, template SOAP, foto before-after, e-resep | RME, foto, resep, informed consent |
| Terapis / beautician / perawat | Daftar tindakan hari ini, catatan tindakan, pemakaian bahan | Catatan tindakan, BHP, sisa sesi paket |
| Front office / CS | Booking, registrasi, antrean, reminder | Kalender, data pasien (non-klinis), WhatsApp |
| Kasir | Tagihan, pembayaran split, deposit, pemakaian voucher | Billing, kas harian, retur |
| Apoteker / gudang | Resep, stok per batch & expired, mutasi antar cabang | Farmasi, inventori, purchase order |
| Marketing / CRM | Segmentasi pasien, kampanye, promo | Data kontak (sesuai consent), promo, laporan kampanye |
| Pasien | Booking sendiri, lihat sisa paket & poin, riwayat treatment | Portal/app pasien |

Hak akses berbasis peran (RBAC) wajib memisahkan data klinis dari data komersial: CS dan marketing tidak boleh melihat isi rekam medis.

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

## 5. Kebutuhan fungsional

Kebutuhan dikelompokkan per modul; ID dipakai di analisis gap (bagian 8). P0 wajib ada sebelum klinik pilot go-live.

### 5.1 Booking & penjadwalan

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| BK-01 | Kalender multi-resource: dokter, terapis, ruang, alat (laser, dental chair); cegah double-booking | P0 · MVP |
| BK-02 | Durasi slot otomatis dari treatment yang dipilih (mis. botox 30 menit, laser 60 menit) plus buffer sterilisasi | P0 · MVP |
| BK-03 | Jadwal praktik, shift & cuti dokter/terapis per cabang | P0 · MVP |
| BK-04 | Booking online via web/portal: pilih cabang, dokter, treatment, slot | P1 · Fase 2 |
| BK-05 | DP booking untuk treatment tertentu; hangus saat no-show sesuai kebijakan | P1 · Fase 2 |
| BK-06 | Reminder otomatis H-1 dan 2 jam sebelum via WhatsApp, dengan tombol konfirmasi/reschedule | P0 · MVP |
| BK-07 | Waiting list untuk mengisi slot dari pembatalan | P2 · Fase 3 |

### 5.2 Registrasi & data pasien

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| PS-01 | Master pasien: NIK, nama, tanggal lahir, kontak, alamat, No. RM otomatis | P0 · MVP |
| PS-02 | Pencarian cepat (nama, HP, No. RM, NIK) dan deteksi duplikat | P0 · MVP |
| PS-03 | Alergi, riwayat obat, tipe kulit Fitzpatrick, status hamil/menyusui tampil sebagai peringatan | P0 · MVP |
| PS-04 | Consent pemrosesan data dan opt-in marketing terpisah (UU PDP) | P0 · MVP |
| PS-05 | Lookup IHS Number pasien ke SATUSEHAT via NIK | P0 · MVP |
| PS-06 | Sumber pasien (referral, Instagram, Google, promo) untuk atribusi marketing | P1 · Fase 2 |
| PS-07 | Relasi keluarga untuk berbagi paket/membership | P2 · Fase 3 |

### 5.3 Antrean & alur kunjungan

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| AN-01 | Status kunjungan: booking → check-in → konsultasi → tindakan → kasir → selesai | P0 · MVP |
| AN-02 | Layar antrean dan panggilan per ruang | P1 · Fase 2 |
| AN-03 | Satu kunjungan memuat beberapa tindakan oleh beberapa petugas | P0 · MVP |

### 5.4 Rekam Medis Elektronik (RME)

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| RM-01 | SOAP dengan template per spesialisasi dan per treatment | P0 · MVP |
| RM-02 | Diagnosis ICD-10 dan tindakan ICD-9-CM, dengan daftar favorit dokter | P0 · MVP |
| RM-03 | Informed consent digital per tindakan, ditandatangani pasien di tablet | P0 · MVP |
| RM-04 | Foto klinis terstruktur terikat ke kunjungan (detail di bagian 6) | P0 · MVP |
| RM-05 | Catatan tindakan: area, dosis/unit, produk & batch, parameter alat (mis. fluence, spot size laser) | P0 · MVP |
| RM-06 | Treatment plan multi-sesi dengan estimasi biaya yang bisa dikonversi ke paket | P1 · Fase 2 |
| RM-07 | RME dikunci setelah ditandatangani dokter; koreksi hanya via addendum ber-audit | P0 · MVP |
| RM-08 | Resume medis, surat keterangan, surat rujukan dalam PDF | P1 · Fase 2 |

### 5.5 Katalog treatment, paket & membership

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| TR-01 | Master treatment: kategori, durasi, harga per cabang, BHP standar, aturan komisi | P0 · MVP |
| TR-02 | Paket multi-sesi: bayar di muka, sisa sesi terlacak, masa berlaku, transfer/refund sesuai kebijakan | P0 · MVP |
| TR-03 | Bundling produk + treatment | P1 · Fase 2 |
| TR-04 | Membership bertingkat dengan diskon dan benefit | P1 · Fase 2 |
| TR-05 | Poin loyalty: perolehan dan penukaran | P1 · Fase 2 |
| TR-06 | Voucher & promo: kode, periode, kuota, syarat minimum, per cabang/treatment | P0 · MVP |
| TR-07 | Saldo deposit dan gift card pasien | P2 · Fase 3 |

### 5.6 Farmasi & resep

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| FR-01 | E-resep dari dokter ke farmasi, termasuk racikan (krim/salep racik dermatologi) | P0 · MVP |
| FR-02 | Peringatan alergi dan interaksi obat saat meresepkan | P1 · Fase 2 |
| FR-03 | Cetak etiket dan aturan pakai | P0 · MVP |
| FR-04 | Penjualan produk skincare/OTC tanpa resep langsung di kasir | P0 · MVP |

### 5.7 Inventori & bahan habis pakai (BHP)

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| IN-01 | Stok per gudang/cabang, per batch dan tanggal kedaluwarsa (FEFO) | P0 · MVP |
| IN-02 | Potong stok otomatis dari tindakan (BHP standar) dengan koreksi pemakaian aktual oleh petugas | P0 · MVP |
| IN-03 | Satuan fraksional: 1 vial botulinum 100U dipakai lintas pasien, filler per ml, dengan pelacakan vial terbuka | P0 · MVP |
| IN-04 | Purchase order, penerimaan barang, retur ke supplier | P1 · Fase 2 |
| IN-05 | Mutasi antar cabang, stok opname, alert stok minimum dan mendekati kedaluwarsa | P1 · Fase 2 |
| IN-06 | HPP per treatment untuk laporan margin | P1 · Fase 2 |

### 5.8 Billing & kasir

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| BL-01 | Tagihan otomatis dari kunjungan: tindakan, resep, produk, pemakaian sesi paket | P0 · MVP |
| BL-02 | Diskon per item/total dengan batas per peran; di atas batas perlu approval | P0 · MVP |
| BL-03 | Pembayaran split: tunai, EDC, QRIS, transfer, deposit, voucher, poin | P0 · MVP |
| BL-04 | Pembayaran bertahap untuk paket bernilai besar | P1 · Fase 2 |
| BL-05 | Buka/tutup shift kas dengan rekap per metode bayar | P0 · MVP |
| BL-06 | Void dan refund dengan approval dan jejak audit | P0 · MVP |
| BL-07 | Invoice/kuitansi PDF dikirim via WhatsApp/email | P1 · Fase 2 |
| BL-08 | Payment gateway untuk DP booking online dan pembayaran link | P1 · Fase 2 |

### 5.9 Komisi & jasa medis

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| KM-01 | Aturan komisi per treatment per peran: persen atau nominal, split dokter–terapis–asisten | P0 · MVP |
| KM-02 | Komisi penjualan produk dan paket untuk CS/beautician | P1 · Fase 2 |
| KM-03 | Rekap dan slip komisi per periode, dikunci setelah disetujui | P0 · MVP |

### 5.10 CRM, marketing & notifikasi

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| CR-01 | WhatsApp Business API: reminder, konfirmasi, follow-up pasca tindakan (H+1, H+7) | P0 · MVP |
| CR-02 | Recall otomatis sesuai siklus treatment (mis. botox 4–6 bulan, scaling gigi 6 bulan) | P1 · Fase 2 |
| CR-03 | Segmentasi pasien (RFM, treatment terakhir, ulang tahun) dan broadcast hanya ke yang opt-in | P1 · Fase 2 |
| CR-04 | Survei kepuasan/NPS dan tiket komplain | P1 · Fase 2 |
| CR-05 | Program referral pasien | P2 · Fase 3 |

### 5.11 Portal / aplikasi pasien

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| PP-01 | Booking, reschedule, dan batal mandiri | P1 · Fase 2 |
| PP-02 | Lihat sisa sesi paket, poin, voucher, riwayat treatment | P1 · Fase 2 |
| PP-03 | Lihat foto before-after milik sendiri dan instruksi pasca tindakan | P2 · Fase 3 |
| PP-04 | Isi formulir pra-kunjungan dan consent sebelum datang | P2 · Fase 3 |

### 5.12 Laporan & dashboard

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| LP-01 | Dashboard harian: kunjungan, omzet, no-show, top treatment per cabang | P0 · MVP |
| LP-02 | Penjualan per treatment, dokter, cabang, metode bayar | P0 · MVP |
| LP-03 | Laporan paket: terjual, terpakai, sisa kewajiban (deferred revenue) | P0 · MVP |
| LP-04 | Pemakaian BHP aktual vs standar per treatment | P1 · Fase 2 |
| LP-05 | Retensi dan kohort pasien, efektivitas kampanye | P1 · Fase 2 |
| LP-06 | Ekspor Excel/PDF dan jurnal ke software akuntansi | P1 · Fase 2 |

### 5.13 Administrasi & platform

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| AD-01 | Multi-cabang: master data pusat, harga dan stok per cabang, laporan konsolidasi | P0 · MVP |
| AD-02 | RBAC granular; data klinis terpisah dari data komersial | P0 · MVP |
| AD-03 | Audit log akses dan perubahan RME serta transaksi keuangan | P0 · MVP |
| AD-04 | Pengaturan klinik: jam operasional, template dokumen, printer, pajak | P0 · MVP |

## 6. Kebutuhan khusus per spesialisasi

Modul spesialisasi diaktifkan per klinik/cabang lewat pengaturan, sehingga klinik gigi tidak melihat face chart dan klinik kulit tidak melihat odontogram.

### 6.1 Dermatologi & venereologi

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| DR-01 | Body chart: tandai lokasi lesi di peta tubuh dan bandingkan antar kunjungan | P1 · Fase 2 |
| DR-02 | Skor klinis terhitung otomatis: PASI, EASI/SCORAD, IGA akne, MASI | P1 · Fase 2 |
| DR-03 | Template SOAP akne, melasma, dermatitis, infeksi jamur; kasus IMS dengan akses terbatas | P0 · MVP |
| DR-04 | Impor foto dan hasil alat analisis kulit/dermatoskop | P2 · Fase 3 |

### 6.2 Estetika medis (injeksi, laser, facial)

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| ES-01 | Face chart injeksi: titik, unit/ml per titik, produk & batch, jarum/kanula | P0 · MVP |
| ES-02 | Parameter laser/energy device: alat, panjang gelombang, fluence, spot size, jumlah shot, reaksi kulit | P0 · MVP |
| ES-03 | Log pemakaian dan servis alat (shot counter, jadwal kalibrasi) | P2 · Fase 3 |
| ES-04 | Instruksi pasca tindakan per treatment terkirim otomatis ke WhatsApp pasien | P1 · Fase 2 |
| ES-05 | Pencatatan komplikasi/adverse event dan tindak lanjutnya | P1 · Fase 2 |

### 6.3 Foto klinis before-after (lintas spesialisasi)

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| FT-01 | Ambil foto dari tablet dengan template sudut standar (depan, 45° kiri/kanan, profil) | P0 · MVP |
| FT-02 | Tampilan side-by-side dan slider before-after lintas kunjungan | P0 · MVP |
| FT-03 | Simpan terenkripsi di server, tidak masuk galeri perangkat, akses tercatat | P0 · MVP |
| FT-04 | Consent foto bertingkat: klinis saja / edukasi / marketing, bisa dicabut pasien | P0 · MVP |

### 6.4 Kedokteran gigi

| ID | Kebutuhan | Prioritas |
| --- | --- | --- |
| DG-01 | Odontogram interaktif notasi FDI, status per gigi dan per permukaan (M/O/D/B/L) | P0 · MVP |
| DG-02 | Treatment plan per gigi dengan fase dan estimasi biaya | P0 · MVP |
| DG-03 | Charting periodontal: kedalaman poket, BOP, mobilitas | P2 · Fase 3 |
| DG-04 | Lampiran dan viewer radiografi (periapikal, panoramik) | P1 · Fase 2 |
| DG-05 | Perawatan jangka panjang: ortodonti (kontrol bulanan + cicilan), PSA, implan | P1 · Fase 2 |
| DG-06 | Order dan tracking pekerjaan lab gigi (crown, gigi tiruan, aligner) | P1 · Fase 2 |
| DG-07 | Tindakan per gigi otomatis masuk tagihan | P0 · MVP |

## 7. Kebutuhan non-fungsional & regulasi

### 7.1 Regulasi yang wajib dipenuhi

Rujukan pasal di bawah disusun dari ingatan dan harus diverifikasi tim legal sebelum dipakai sebagai acuan kepatuhan.

| Regulasi | Dampak ke produk |
| --- | --- |
| Permenkes 24/2022 tentang Rekam Medis | Semua fasyankes wajib RME; RME disimpan minimal 25 tahun sejak kunjungan terakhir; wajib interoperabel dengan SATUSEHAT; isi RME tidak boleh dihapus, hanya dikoreksi dengan jejak |
| Platform SATUSEHAT (Kemenkes) | Kirim data kunjungan dalam format FHIR: Patient, Practitioner, Encounter, Condition, Procedure, Observation, MedicationRequest; butuh Organization ID dan kredensial per klinik |
| UU 27/2022 Pelindungan Data Pribadi | Data kesehatan dan foto wajah adalah data pribadi spesifik: perlu consent eksplisit, hak akses/koreksi pasien, notifikasi kebocoran maksimal 3×24 jam |
| UU 17/2023 Kesehatan & aturan praktik tenaga medis | Simpan STR/SIP dokter dan drg, beri peringatan sebelum SIP kedaluwarsa; hanya dokter ber-SIP aktif yang bisa menandatangani RME |
| Ketentuan BPOM | Produk skincare yang dijual harus punya nomor notifikasi BPOM yang tercatat di master produk |

### 7.2 Non-fungsional

| Aspek | Kebutuhan |
| --- | --- |
| Keamanan | HTTPS wajib, password ter-hash, 2FA untuk admin dan dokter, session timeout 15 menit di perangkat bersama, rate limiting login |
| Enkripsi | Foto klinis dan lampiran terenkripsi saat disimpan; akses file lewat URL bertanda tangan yang kedaluwarsa |
| Ketersediaan | Uptime 99,5% pada jam operasional klinik; mode terbatas saat internet putus untuk registrasi dan kasir |
| Performa | Halaman utama < 2 detik; pencarian pasien < 1 detik pada 100.000 pasien |
| Backup | Harian, RPO ≤ 24 jam, RTO ≤ 4 jam, uji restore tiap kuartal |
| Skalabilitas | Minimal 50 cabang dan 500 pengguna aktif dalam satu tenant |
| Usability | Optimal di tablet untuk dokter dan terapis; bahasa Indonesia; format Rupiah dan tanggal lokal |
| Audit | Log akses dan perubahan tidak bisa diubah pengguna, disimpan sesuai masa retensi RME |

## 8. Analisis gap: project eKlinik saat ini vs PRD

eKlinik saat ini adalah SIM klinik rawat jalan umum (Poli Umum, Gigi, KIA), belum sistem klinik estetika: dari 51 kebutuhan P0, baru 2 terpenuhi, 15 parsial, dan 34 belum ada. Alur kunjungan dasarnya rapi dan layak dipertahankan; yang kurang hampir seluruh sisi komersial estetika, modul spesialisasi, dan integrasi.

&#91;embedded content: analisis kode backend & frontend eKlinik, 30 Sep 2026 · 51 kebutuhan P0 dari bagian 5–6\]

Booking dan foto klinis sama sekali belum tersentuh; RME dan billing punya dasar yang bisa diperluas. Portal pasien tidak tampil karena tidak punya kebutuhan P0.

### 8.1 Yang sudah ada

Backend Laravel 13 + PostgreSQL 17 (REST API, token Sanctum), frontend Vue 3 + Vite + Tailwind. Sistem untuk satu klinik, tanpa konsep cabang. Alur yang sudah berjalan end-to-end:

1. Pendaftaran pasien dan nomor antrean per poli (hari ini).
2. Pemeriksaan: tanda vital, SOAP, diagnosis ICD-10, tindakan, resep.
3. Tagihan dibuat otomatis saat pemeriksaan selesai, lalu dibayar di kasir.
4. Farmasi menyerahkan obat setelah tagihan lunas, stok tercatat di kartu stok.

Tersedia 11 feature test untuk alur utama dan dokumentasi AI-Context yang sesuai dengan kode.

### 8.2 Status per modul

| Modul | Status | Sudah ada di kode | Yang kurang (ID PRD) |
| --- | --- | --- | --- |
| Booking & penjadwalan | Belum ada | Kunjungan hanya walk-in untuk hari ini | Semua BK-01 s.d. BK-07: kalender, slot, jadwal dokter, reminder, DP |
| Registrasi & pasien | Parsial | Master pasien (NIK unik, No. RM otomatis, alergi teks bebas), pencarian & filter | PS-03 riwayat klinis terstruktur & Fitzpatrick; PS-04 consent UU PDP; PS-05 IHS SATUSEHAT; PS-06 sumber pasien; PS-07 relasi keluarga; foto & email pasien |
| Antrean & kunjungan | Parsial | Status menunggu → diperiksa → menunggu pembayaran → selesai/batal; daftar antrean + tombol panggil | AN-01 tahap booking & tindakan; AN-02 layar antrean & suara; AN-03 tindakan oleh terapis berbeda dalam satu kunjungan |
| RME | Parsial | Tanda vital, SOAP 4 kolom, ICD-10 (27 kode seed), tindakan; terkunci setelah pemeriksaan selesai | RM-01 template; RM-02 ICD-9-CM; RM-03 informed consent & tanda tangan; RM-04 foto/lampiran; RM-05 dosis, batch, parameter alat; RM-06 treatment plan; RM-07 addendum ber-audit; RM-08 resume & surat |
| Katalog, paket & membership | Parsial | Master tindakan datar: kode, nama, tarif, aktif | TR-01 kategori, durasi, BHP, harga per cabang; TR-02 paket multi-sesi; TR-03 s.d. TR-07 bundling, membership, poin, voucher, deposit |
| Farmasi & resep | Parsial | E-resep dari pemeriksaan, harga di-snapshot, serah obat setelah lunas, cetak etiket | FR-01 racikan; FR-02 peringatan alergi/interaksi; FR-04 penjualan OTC tanpa resep; pembatalan resep |
| Inventori & BHP | Parsial | Stok obat satu angka + stok minimum, kartu stok, alert stok rendah di dashboard | IN-01 batch & expired; IN-02 potong BHP dari tindakan; IN-03 satuan fraksional (vial, ml); IN-04 supplier & PO; IN-05 multi-gudang; IN-06 HPP |
| Billing & kasir | Parsial | Tagihan otomatis (konsultasi + tindakan + obat), satu metode bayar, diskon nominal, cetak kuitansi | BL-02 batas diskon per peran; BL-03 split payment & deposit; BL-04 cicilan; BL-05 shift kas; BL-06 void/refund; BL-07 invoice PDF/WA; BL-08 payment gateway (QRIS kini hanya label) |
| Komisi & jasa medis | Belum ada | Tidak ada | KM-01 s.d. KM-03 |
| CRM & notifikasi | Belum ada | Tidak ada; mail driver masih `log`, belum ada queue job atau scheduler | CR-01 s.d. CR-05, termasuk WhatsApp API |
| Portal pasien | Belum ada | Endpoint publik hanya login staf | PP-01 s.d. PP-04 |
| Laporan & dashboard | Parsial | Dashboard hari ini: kunjungan per status/poli, omzet, resep & tagihan tertunda, stok rendah | LP-02 laporan penjualan berperiode; LP-03 laporan paket; LP-04 BHP; LP-05 retensi; LP-06 ekspor Excel/PDF |
| Administrasi & platform | Parsial | 6 role tetap (admin, pendaftaran, perawat, dokter, apoteker, kasir) lewat middleware | AD-01 multi-cabang; AD-02 role terapis/CS/marketing dan izin yang bisa diatur; AD-03 audit log; AD-04 pengaturan klinik (nama “E-KLINIK” dan format nomor di-hard-code) |
| Dermatologi | Belum ada | Beberapa kode ICD-10 kulit di data seed | DR-01 s.d. DR-04 |
| Estetika medis | Belum ada | Tidak ada | ES-01 s.d. ES-05 |
| Foto klinis | Belum ada | Tidak ada upload file sama sekali | FT-01 s.d. FT-04 |
| Kedokteran gigi | Belum ada | Poli Gigi, tindakan gigi (scaling, tambal) dan kode ICD-10 gigi di data seed | DG-01 odontogram; DG-02 treatment plan per gigi; DG-03 perio; DG-04 radiografi; DG-05 perawatan jangka panjang; DG-06 lab gigi; DG-07 billing per gigi |
| Integrasi & regulasi | Belum ada | Field `no_bpjs`, nomor SIP dokter, label penjamin BPJS/asuransi | SATUSEHAT (FHIR), consent UU PDP, alert SIP kedaluwarsa, nomor BPOM produk, soft delete untuk retensi RME |

### 8.3 Temuan teknis yang perlu dibereskan lebih dulu

Urutan berdasarkan risiko dan biaya jika ditunda:

1. **Token login tidak pernah kedaluwarsa** (`expiration => null` di `backend/config/sanctum.php`) dan disimpan di `localStorage` (`frontend/src/stores/auth.js`). Ini bertentangan dengan session timeout di perangkat bersama; pasang masa berlaku token atau pindah ke mode SPA cookie Sanctum.
2. **Belum ada kolom `klinik_id`/`cabang_id`** di tabel mana pun. Multi-cabang (AD-01) sebaiknya ditambahkan sekarang, sebelum tabel bertambah.
3. **`reseps.kunjungan_id` dan `tagihans.kunjungan_id` bersifat unique.** Satu kunjungan hanya boleh satu resep dan satu tagihan, dan tagihan wajib terikat kunjungan. Paket, deposit, dan penjualan produk butuh tagihan tanpa kunjungan.
4. **Kunjungan selalu bertanggal hari ini.** Booking butuh entitas `appointment` terpisah yang dikonversi menjadi kunjungan saat check-in.
5. **Tidak ada audit log dan soft delete**, padahal keduanya wajib untuk RME dan transaksi keuangan.
6. **Tidak ada queue worker, scheduler, dan penyimpanan file.** Ketiganya prasyarat untuk reminder WhatsApp, pengiriman SATUSEHAT, dan foto klinis.
7. Status `batal` untuk tagihan dan resep ada di enum tetapi tidak punya endpoint.

Layak dipertahankan: uang disimpan sebagai integer rupiah, harga di-snapshot ke transaksi, logika bisnis di Service class, dan feature test alur utama.

## 9. Roadmap & prioritas rilis

Fase 0 (fondasi teknis) harus selesai sebelum fitur estetika dibangun, karena multi-cabang, audit log, dan penyimpanan file menyentuh hampir semua tabel yang sudah ada.

&#91;embedded content: roadmap rilis · 4 fase, 3 gerbang\]

Setiap fase ditutup oleh gerbang; fase berikutnya dimulai setelah kriteria gerbang terpenuhi. Estimasi durasi per fase menunggu keputusan tim dan jadwal klinik pilot.

## 10. Risiko, asumsi & pertanyaan terbuka

**Risiko**

| Risiko | Dampak | Mitigasi |
| --- | --- | --- |
| Kebocoran foto wajah pasien | Sanksi UU PDP, reputasi klinik hancur | Enkripsi, URL bertanda tangan, larangan unduh ke perangkat, audit akses (FT-03) |
| Integrasi SATUSEHAT molor karena kredensial & mapping kode | Klinik tidak patuh Permenkes 24/2022 | Mulai proses registrasi Organization ID sejak sprint 1; antrean kirim ulang otomatis |
| Dokter menolak input RME karena lambat | Data klinis kosong, KPI kepatuhan gagal | Template per treatment, favorit diagnosis, input tablet, uji waktu input < 3 menit |
| Aturan komisi tiap klinik berbeda-beda | Kustomisasi tak berujung | Mesin aturan komisi yang bisa dikonfigurasi, bukan hard-code |
| Biaya WhatsApp Business API per pesan | Margin langganan tergerus | Batasi template per paket langganan; fallback SMS/email |

**Asumsi**

- Mayoritas pasien adalah pasien umum (bayar sendiri), bukan BPJS.
- Klinik pilot memiliki koneksi internet stabil dan minimal satu tablet per ruang tindakan.
- Model bisnis SaaS berlangganan per cabang.

**Pertanyaan terbuka**

- [ ] Klinik pilot mana (kulit, gigi, atau campuran) dan kapan target go-live?
- [ ] Apakah klinik gigi pilot bermitra BPJS, sehingga klaim BPJS masuk scope?
- [ ] Kebijakan paket: boleh transfer ke orang lain? refund prorata atau tidak?
- [ ] Software akuntansi apa yang dipakai klinik target (Jurnal, Accurate, lainnya)?
- [ ] Apakah dibutuhkan aplikasi mobile native, atau cukup web responsif untuk portal pasien?
