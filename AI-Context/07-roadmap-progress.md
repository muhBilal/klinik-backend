# 07 — Progres Roadmap PRD

Pelacak pengerjaan PRD ([PRD — Sistem Manajemen Klinik Estetika (eKlinik).md](PRD%20—%20Sistem%20Manajemen%20Klinik%20Estetika%20(eKlinik).md)).
ID kebutuhan (`AD-01`, `BK-01`, ...) mengacu ke PRD bagian 5–6. Perbarui file ini setiap fitur selesai.

Status: **Selesai** · **Fondasi** (infrastruktur siap, fitur pengguna menyusul) · **Parsial** · **Belum**

## Fase 0 — Fondasi (selesai 30 Sep 2026)

Gerbang PRD: "fondasi lolos review keamanan" — **menunggu review**.

| Item roadmap | PRD | Status | Dokumen |
|--------------|-----|--------|---------|
| Multi-cabang: cabang, `cabang_id`, cabang aktif | AD-01 | Fondasi (harga & stok per cabang di Fase 1) | [modul/F0-02](modul/F0-02-multi-cabang.md) |
| Audit log & soft delete | AD-03, 7.1 | Selesai | [modul/F0-03](modul/F0-03-audit-log.md) |
| Token kedaluwarsa, idle timeout, 2FA | 7.2 Keamanan | Selesai | [modul/F0-04](modul/F0-04-keamanan-sesi-2fa.md) |
| Queue worker & scheduler | 8.3 #6 | Fondasi | [modul/F0-07](modul/F0-07-queue-scheduler.md) |
| Penyimpanan file terenkripsi | FT-03, 7.2 Enkripsi | Selesai (fondasi FT-01/02/04) | [modul/F0-05](modul/F0-05-berkas-terenkripsi.md) |
| Pengaturan klinik | AD-04 | Parsial (pajak → Billing Fase 1) | [modul/F0-06](modul/F0-06-pengaturan-klinik.md) |
| RBAC dinamis & pemisahan data klinis | AD-02 | Selesai | [modul/F0-01](modul/F0-01-rbac-peran-izin.md) |

Temuan teknis PRD 8.3 **seluruhnya sudah ditutup**: #1 token tanpa kedaluwarsa, #2 kolom cabang, #5 audit log & soft delete,
#6 queue/scheduler/penyimpanan file (Fase 0); #3 tagihan/resep unik per kunjungan & #7 endpoint batal (F1-03 Kasir);
#4 kunjungan selalu hari ini (F1-02 Booking, lewat entitas `appointments`).

Test backend: 43 test / 450 assertion lulus (SQLite). Migrasi & rollback diuji di PostgreSQL 17 dengan salinan data demo.
Smoke test HTTP lewat nginx (stack dev terisolasi) lulus: pengaturan, cabang, pendaftaran lintas cabang, unggah & unduh berkas, audit, CORS.

## Status kebutuhan P0

| ID | Status | Catatan |
|----|--------|---------|
| AD-01 | Selesai | cabang & scope transaksi; harga treatment per cabang (F1-01); stok per cabang (F1-04); laporan konsolidasi semua cabang + rincian per cabang & dashboard per cabang (F1-11) |
| AD-02 | Selesai | peran & izin dinamis, pemisahan data klinis (`rme.lihat`) |
| AD-03 | Selesai | perubahan data + akses RME/berkas + login |
| AD-04 | Selesai | identitas, struk, lebar kertas, prefix, keamanan, pajak & jam operasional (F1-03); kop & kaki dokumen cetak (PRD v2 #7) |
| FR-01 / AD-05 / AD-06 / AD-10 | Selesai | resep racikan, STR/SIP + peringatan, nomor BPOM, impor master CSV (PRD v2 #7, [V2-07](modul/V2-07-racikan-regulasi.md)) |
| FT-03 | Selesai | enkripsi, tautan bertanda tangan, audit akses |
| PS-01 | Selesai | (sudah ada sebelumnya) |
| LP-01 | Selesai | dashboard harian: kunjungan, omzet, booking & no-show, top treatment, per cabang (F1-11) |
| LP-02 | Selesai | penjualan per treatment, dokter, cabang, metode bayar, kategori, per hari; refund di periode refund (F1-11) |
| LP-03 | Selesai | paket terjual, pendapatan diakui per sesi, refund, hangus, sisa kewajiban, segera kedaluwarsa (F1-11) |
| PS-02 | Selesai | deteksi pasien ganda saat pendaftaran (`pasiens.no_hp_digit`, PRD v2 #5) |
| PS-03 | Selesai | alergi terstruktur (bertaut master obat), riwayat obat & penyakit, Fitzpatrick, hamil/menyusui bertanggal; peringatan di pemeriksaan, resep & farmasi; terpisah dari identitas (F1-10) |
| PS-04 | Selesai | persetujuan pemrosesan data & opt-in marketing (kanal) terpisah, bertanda tangan, naskah snapshot, cabut; opsi wajib sebelum pendaftaran/check-in (F1-10) |
| FR-03 | Selesai | (sudah ada sebelumnya; kop etiket kini dari pengaturan) |
| TR-01 | Selesai | kategori, durasi + buffer, harga per cabang, BHP standar (F1-01); komisi per peran di master treatment (F1-09) |
| BK-01/02/03 | Selesai | kalender multi-resource, slot = durasi + buffer, jadwal praktik & cuti (F1-02); **frontend kalender & jadwal (PRD v2 #1)** |
| BK-08 / BK-09 | Selesai | ruang/alat wajib per treatment + UI master Ruang & Alat; status no-show di kalender (PRD v2 #1) |
| AN-01 | Selesai | tahap booking → check-in → kunjungan (F1-02) |
| IN-01/02/03 | Selesai | batch & kedaluwarsa FEFO per cabang, potong BHP otomatis, satuan fraksional (F1-04); **frontend Stok Batch + mutasi antar cabang IN-05 (PRD v2 #3)** |
| BL-02/03/05/06 | Selesai | batas diskon per peran, split payment, shift kas, void & refund (F1-03); **frontend + persetujuan atasan untuk diskon di atas batas, opsi wajib shift (PRD v2 #2)** |
| FR-04 | Selesai | penjualan produk lewat tagihan mandiri tanpa kunjungan (F1-03) |
| RM-01 | Selesai | template SOAP per poli & per treatment + saran diagnosa (F1-05) |
| RM-02 | Selesai | ICD-9-CM (62 kode dasar) per tindakan + default katalog, favorit ICD-10/ICD-9-CM per dokter (F1-05) |
| RM-03 | Selesai | informed consent per tindakan, naskah di-snapshot, tanda tangan pasien/saksi terenkripsi, wajib sebelum tutup (F1-05) |
| RM-05 | Selesai | catatan tindakan: area, petugas, produk & batch per titik, parameter alat (F1-05) |
| RM-07 | Selesai | tanda tangan dokter ber-SIP aktif saat tutup + hash keutuhan, kunci model, addendum append-only (F1-05) |
| DR-03 | Selesai | template akne, melasma, dermatitis, jamur, IMS; kunjungan IMS/HIV berakses terbatas (F1-05) |
| ES-01 / ES-02 | Selesai | face chart injeksi; parameter laser/energy device (F1-05) |
| FT-01 / FT-02 / FT-04, RM-04 | Selesai | protokol posisi & kamera terpandu, galeri + slider before-after, consent foto bertingkat (F1-06) |
| FT-03 | Selesai | + thumbnail terenkripsi, EXIF dibuang, kamera di dalam aplikasi (F1-06) |
| KM-01 | Selesai | komisi per treatment × peran dokter/terapis/asisten di master treatment, persen/nominal; jasa konsultasi = treatment poli (F1-09) |
| KM-03 | Selesai | rekap per periode dari tagihan lunas, penyesuaian, setujui & kunci, slip per petugas & Komisi Saya (F1-09) |
| TR-02 | Selesai | paket multi-sesi: jual via tagihan, aktif saat lunas, sisa sesi, masa berlaku, refund/alih/perpanjang sesuai kebijakan (F1-08) |
| TR-06 | Selesai | voucher & promo: kode, periode, kuota total & per pasien, minimum, cabang, treatment/paket (F1-08) |
| BL-01 | Selesai | + pemakaian sesi paket di tagihan kunjungan (F1-08) |
| DG-01 | Selesai | odontogram FDI tetap + sulung, per gigi & permukaan M/O/D/B/L, aturan penggantian, riwayat per kunjungan, masuk hash RME (F1-07) |
| DG-02 | Selesai | rencana perawatan per gigi berfase + estimasi biaya, persetujuan pasien, revisi, cetak, dikerjakan dari pemeriksaan (F1-07) |
| DG-07 | Selesai | tindakan per gigi wajib nomor gigi, ditagih per gigi, memperbarui odontogram otomatis (F1-07) |
| Bagian 6 (modul per spesialisasi) | Parsial | `polis.spesialisasi` menampilkan odontogram hanya untuk poli gigi (F1-07); face chart per jenis catatan treatment (F1-05); pengaturan per cabang belum |
| AN-03 | Parsial | pelaksana (F1-05) + asisten (F1-09) per tindakan; lebih dari satu orang per peran belum |
| 7.1 SIP | Selesai | hanya SIP aktif yang menandatangani RME (F1-05); STR, peringatan sebelum kedaluwarsa, booking dokter ber-SIP kedaluwarsa ditolak (PRD v2 #7) |
| Lainnya | lihat PRD bagian 8.2 | belum berubah |

## Fase 1 — MVP estetika (sedang berjalan)

Urutan kerja yang disarankan (dependensi di kolom kanan):

| # | Modul | PRD | Bergantung pada | Status | Dokumen |
|---|-------|-----|-----------------|--------|---------|
| 1 | Katalog treatment: kategori, durasi, harga per cabang, BHP standar | TR-01 | F0-02 | **Selesai** 30 Sep 2026 (komisi → #10) | [modul/F1-01](modul/F1-01-katalog-treatment.md) |
| 2 | Jadwal praktik & booking multi-resource (entitas `appointment` → kunjungan saat check-in) | BK-01..03, AN-01, 8.3 #4 | 1 | **Selesai** 30 Sep 2026 | [modul/F1-02](modul/F1-02-booking-jadwal.md) |
| 3 | Reminder WhatsApp (job + scheduler) | BK-06, CR-01 | 2, F0-07 | **Kode selesai** 1 Okt 2026 (PRD v2 #9); aktivasi menunggu kredensial WhatsApp Business API | [modul/V2-09](modul/V2-09-whatsapp.md) |
| 4 | RME estetika: template SOAP, ICD-9-CM, informed consent + tanda tangan, catatan tindakan (dosis, batch, parameter alat), addendum | RM-01/02/03/05/07, DR-03, ES-01/02 | F0-03, F0-05 | **Selesai** 30 Sep 2026 (backend + frontend) | [modul/F1-05](modul/F1-05-rme-estetika.md) |
| 5 | Foto klinis before-after + consent foto | FT-01, FT-02, FT-04 | F0-05 | **Selesai** 1 Okt 2026 (backend + frontend) | [modul/F1-06](modul/F1-06-foto-klinis.md) |
| 6 | Odontogram & treatment plan per gigi | DG-01, DG-02, DG-07 | 4 | **Selesai** 1 Okt 2026 (backend + frontend) | [modul/F1-07](modul/F1-07-odontogram.md) |
| 7 | Inventori: batch/expired per gudang cabang, BHP otomatis, satuan fraksional | IN-01..03 | 1, F0-02 | **Selesai** 30 Sep 2026 | [modul/F1-04](modul/F1-04-inventori.md) |
| 8 | Paket multi-sesi, voucher & promo | TR-02, TR-06 | 1 | **Selesai** 1 Okt 2026 (backend + frontend; revisi: pesan dari pemeriksaan) | [modul/F1-08](modul/F1-08-paket-promo.md) |
| 9 | Kasir: tagihan tanpa kunjungan, split payment, diskon per peran, void/refund, shift kas, pajak | BL-01..03/05/06, AD-04, 8.3 #3 #7 | — | **Selesai** 30 Sep 2026 (paket & promo: F1-08; deposit = TR-07 Fase 3) | [modul/F1-03](modul/F1-03-kasir.md) |
| 10 | Komisi dokter & terapis (termasuk aturan komisi per treatment dari TR-01) | KM-01, KM-03 | 1, 9 | **Selesai** 1 Okt 2026 (backend + frontend) | [modul/F1-09](modul/F1-09-komisi.md) |
| 11 | Consent data pasien (UU PDP) & data klinis pasien terstruktur | PS-03, PS-04 | — | **Selesai** 1 Okt 2026 (backend + frontend) | [modul/F1-10](modul/F1-10-data-klinis-pdp.md) |
| 12 | Integrasi SATUSEHAT (IHS pasien, Encounter, Condition, ...) | PS-05, 7.1 | 4, F0-07 | **Kode selesai** 1 Okt 2026 (PRD v2 #8); aktivasi menunggu Organization ID & kredensial Kemenkes | [modul/V2-08](modul/V2-08-satusehat.md) |
| 13 | Laporan penjualan & paket | LP-01..03 | 8, 9 | **Selesai** 1 Okt 2026 (backend + frontend) | [modul/F1-11](modul/F1-11-laporan.md) |

Catatan: kebutuhan ruang/alat **wajib** per treatment (BK-08) selesai 1 Okt 2026 (PRD v2 #1). Pengerjaan sejak PRD v2 dilacak di
[PRD-v2/progress.md](PRD-v2/progress.md).

Modul #3 dan #12 terblokir kredensial pihak ketiga, bukan pekerjaan kode: WhatsApp Business API dan Organization ID
SATUSEHAT. Payment gateway (BL-08, Fase 2) juga menunggu merchant account.

Test backend setelah revisi paket (1 Okt 2026): **136 test** lulus di SQLite (2067 assertion) **dan** PostgreSQL 17 (2075 — selisih dari
invarian data demo); migrate →
rollback → migrate F1-05 s.d. F1-10 diuji di PostgreSQL 17 dengan data demo (termasuk konversi data migration revisi komisi & teks alergi).
Data demo transaksi: `DemoSeeder` (01-overview). Alur F1-05 s.d. F1-09 juga diuji E2E di browser (lihat modul masing-masing).

Frontend: F1-05 s.d. F1-08 punya UI lengkap. Editor pemakaian BHP (F1-04) tersedia di modal catatan tindakan; kasir kini menangani
tagihan tanpa kunjungan, kode promo & pajak (F1-08). Halaman Booking (PRD v2 #1), Kasir split payment/shift/void (PRD v2 #2), dan Inventori batch/opname/mutasi (PRD v2 #3) selesai.

Gerbang PRD Fase 1: "P0 lolos UAT, go-live klinik pilot".

## Riwayat

| Tanggal | Perubahan |
|---------|-----------|
| 2026-10-05 | Nama produk **lefaklinik → Vertiqo** tanpa embel-embel "klinik" (judul tab, manifest, wordmark drawer `Vertiqo` satu kata — header desktop tetap hanya logo, default `APP_NAME` & nama klinik & issuer 2FA, nama paket frontend `vertiqo-frontend`, dokumen) + **logo baru** (jaringan simpul biru, panah naik, centang oranye; vektor digambar ulang dari gambar user di `frontend/public/favicon.svg`, ikon turunan dibuat ulang `npm run ikon`; lihat `frontend/AI-Context/04-conventions.md` bagian Logo produk). Nama teknis `eklinik` (folder, database, container, `config/eklinik.php`, perintah `eklinik:*`, kunci penyimpanan browser, email demo) tidak diubah; nama berkas PRD v2 & diagram `Peta-Sistem-Lefaklinik.*` tetap agar tautan tidak putus |
| 2026-10-01 | **Merge PRD v2 (muhBilal) dengan pekerjaan lokal.** Modul yang dikerjakan dua kali memakai versi lokal: komisi di master treatment (F1-09), data klinis & UU PDP (F1-10), laporan & dashboard (F1-11), kasir/kembalian & paket dari pemeriksaan. Dari PRD v2 dipertahankan: booking/jadwal/ruang & alat (UI), mutasi stok & stok batch, persetujuan atasan untuk diskon, `keuangan.wajib_shift`, racikan & cek alergi resep (memakai `pasien_alergis` lokal), STR & peringatan SIP/STR, jenis produk & BPOM, impor master, kop & kaki dokumen, SATUSEHAT, WhatsApp (opt-in marketing memakai persetujuan lokal), avatar & tema, sistem/backup, deteksi pasien ganda. Dibuang (diganti versi lokal): aturan komisi & tabel `komisis`/`periode_komisis`/`kunjungan_tindakan_petugas`, `profil_klinis`, laporan v2, `pembayarans.diterima`, `tagihan_items.kunjungan_tindakan_id`, migration konsultasi poli v2 |
| 2026-10-01 | F1-08 revisi (keputusan user): **paket dipesan dokter/terapis dari pemeriksaan** & ditagihkan bersama tagihan kunjungan (sesi pertama di kunjungan yang sama; kasir bisa *Batalkan paket* bila pasien tidak jadi), **perawat/terapis (`rme.tindakan`) mencatat tindakan & sesi paket** (ICD-9-CM, diagnosa, resep & tutup tetap dokter). Hasil review adversarial: sinkron tindakan sadar snapshot & baris petugas lain terlindungi (dokter & terapis di perangkat berbeda tidak saling menghapus), simpan hanya kolom yang berubah, kunci baris kunjungan (simpan/tutup/pesan), isi RME hanya diubah yang boleh membaca, neto per baris tagihan (`tagihan_items.neto`) untuk nilai paket/komisi/laporan, laporan paket hanya sesi berbayar, batal kunjungan berisi dokumentasi ditolak. **Perbaikan keamanan (bug lama):** binding rute kini memakai cabang aktif — sebelumnya user cabang lain bisa membuka/mengubah data cabang lain lewat id. Emoji dihapus dari UI (ikon AppIcon) |
| 2026-10-01 | Fase 1 #13 selesai: F1-11 Laporan penjualan (treatment/dokter/cabang/metode/kategori/per hari, refund di periode refund), laporan paket (terjual, pendapatan diakui, hangus, sisa kewajiban, segera kedaluwarsa), dashboard harian (booking & no-show, top treatment, per cabang). **Perbaikan:** rekap shift kasir kini mengurangkan kembalian tunai. **Data demo:** `DemoSeeder` (cabang kedua, ±430 kunjungan 6 minggu lewat service, paket, komisi, PDP) + opsi `SEED_DEMO_TRANSAKSI` di compose |
| 2026-10-01 | Fase 1 #11 selesai: F1-10 Data klinis pasien & persetujuan UU PDP (alergi terstruktur bertaut obat, Fitzpatrick, hamil/menyusui, riwayat obat & penyakit — terpisah dari identitas, hanya rme.lihat; peringatan di pemeriksaan, resep & farmasi; persetujuan pemrosesan & opt-in marketing terpisah, bertanda tangan, bisa dicabut; opsi wajib sebelum pendaftaran). `pasiens.alergi` dikonversi & dihapus; alergi tidak lagi diisi di form pasien. Grid pendaftaran diperbaiki untuk mobile |
| 2026-10-01 | F1-09 revisi (permintaan user): **komisi diatur langsung di master treatment** (`tindakan_komisis`, bagian "Komisi & jasa medis" di form treatment; halaman & API Aturan Komisi dihapus) dan **jasa konsultasi dokter = treatment** (kategori Konsultasi; `polis.tindakan_konsultasi_id` menggantikan `tarif_konsultasi`, harga per cabang & komisi dokter ikut katalog, tidak dobel bila dicatat sebagai tindakan). Migration `140001` mengonversi data lama |
| 2026-10-01 | Fase 1 #10 selesai: F1-09 Komisi & jasa medis (mesin aturan treatment/kategori/umum & konsultasi × dokter/terapis/asisten, persen/nominal, khusus cabang; rekap per cabang dari tagihan lunas, bruto/neto, sesi paket; setujui & kunci dengan izin terpisah; penyesuaian; slip & Komisi Saya). `kunjungan_tindakans.asisten_id`; tagihan Rp 0 bisa dilunasi |
| 2026-10-01 | PRD v2 #10: skrip backup/restore (teruji: 100 ribu pasien, restore 1,1 dtk), `eklinik:data-uji` + pengukuran pencarian (43–102 ms pada 100 ribu pasien), detak scheduler + status antrean + job gagal (Integrasi → Sistem), perbaikan CSV formula injection, audit dependensi bersih. **Insiden:** DB dev sempat dikosongkan & di-seed ulang oleh `migrate:fresh` yang salah target (lihat jebakan di 06-conventions) |
| 2026-10-01 | PRD v2 #8 & #9: integrasi SATUSEHAT (klien OAuth2, IHS via NIK, Bundle FHIR, antrean + retry + kirim ulang, kepatuhan) dan WhatsApp (outbox, penjadwal reminder/follow-up, driver log & Cloud API, webhook bertanda tangan, tombol konfirmasi/ubah jadwal); izin `integrasi.kelola`; halaman Administrasi → Integrasi. Aktivasi menunggu kredensial |
| 2026-10-01 | PRD v2 #7: resep racikan (komponen, harga + biaya racik, potong stok komponen, cek alergi komponen), STR & peringatan SIP/STR + blokir booking, jenis produk & nomor BPOM, impor master ICD-10/ICD-9-CM/obat (API + artisan), kop & kaki dokumen; frontend modal racikan, impor CSV, form obat/pengguna, banner izin praktik |
| 2026-10-01 | PRD v2 #6: laporan penjualan (per treatment/kategori/dokter/metode/cabang, refund di periode refund), laporan paket & kewajiban sisa sesi, dashboard booking/no-show/top treatment/per cabang, ekspor CSV; **perbaikan bug** pembayaran tunai menyimpan kembalian (`pembayarans.diterima` + koreksi data lama) — saat merge digantikan versi lokal (F1-09/F1-10/F1-11, kasir lokal) |
| 2026-10-01 | PRD v2 #5: profil klinis & alergi terstruktur (peringatan hamil/Fitzpatrick/alergi), resep obat alergi butuh konfirmasi, consent UU PDP pemrosesan & marketing (+ opsi wajib sebelum pendaftaran/booking), deteksi pasien ganda (`pasiens.no_hp_digit`); frontend kartu profil klinis, panel consent, kandidat duplikat di form pasien — saat merge digantikan versi lokal (F1-09/F1-10/F1-11, kasir lokal) |
| 2026-10-01 | PRD v2 #4: komisi & jasa medis (aturan, kejadian bayar/refund, rekap, slip, hitung ulang, setujui & kunci periode), petugas tambahan per tindakan, `tagihan_items.kunjungan_tindakan_id`, izin `komisi.kelola`/`komisi.setujui`, pengaturan `komisi.dasar`; frontend Komisi, Komisi Saya, petugas tambahan di catatan tindakan — saat merge digantikan versi lokal (F1-09/F1-10/F1-11, kasir lokal) |
| 2026-10-01 | PRD v2 #3: mutasi stok antar cabang (`POST /stok-batches/{id}/mutasi`), halaman Stok Batch & Kedaluwarsa (terima, opname, mutasi, buang, alert kedaluwarsa), tombol Batch di Obat & Stok |
| 2026-10-01 | PRD v2 #2: persetujuan atasan untuk diskon di atas batas (izin `kasir.diskon`, `tagihans.diskon_disetujui_oleh`, audit `setujui_diskon`), `keuangan.wajib_shift`, `GET /shift-kas/aktif` mengembalikan `null`; frontend split payment, void/refund, halaman Shift Kas, kartu Keuangan & Kasir di Pengaturan |
| 2026-10-01 | PRD v2 #1: frontend Booking (kalender per petugas/ruang/daftar, form booking + slot, detail & aksi), Jadwal Praktik & cuti, master Ruang & Alat; ruang/alat wajib per treatment (BK-08) di backend & form Treatment; `appointments-slot?kecuali_id`; AsyncSelect tidak lagi mengirim form saat Enter |
| 2026-10-01 | Nama produk **e-klinik → lefaklinik** (wordmark header/drawer `lefa`+`klinik`, judul tab, manifest, default `APP_NAME` & nama klinik, dokumen; logo K tetap; nama teknis `eklinik` tidak diubah). Data demo poli disesuaikan PRD: Poli Estetika Medis, Poli Kulit & Kelamin, Poli Gigi & Estetika Gigi (Poli Umum & KIA serta treatment KIA dihapus); akun `dokter.kia@` → `dokter.kulit@` (Sp.D.V.E); test tarif konsultasi kini membaca tarif poli |
| 2026-10-01 | Fase 1 #8 selesai: F1-08 Paket multi-sesi (jual via tagihan, aktif saat lunas, sisa sesi dihitung dari tindakan kunjungan, pemakaian di pemeriksaan Rp 0, nilai per sesi untuk LP-03, refund/alih/perpanjang sesuai pengaturan) & voucher/promo (periode, kuota, minimum, cabang, treatment/paket). Kasir mendapat `pasien.lihat`; izin baru `promo.kelola`. Frontend: master Paket Treatment, Voucher & Promo, kartu paket pasien, pakai paket di pemeriksaan, kode promo & tagihan tanpa kunjungan di kasir (bug daftar kasir untuk tagihan mandiri diperbaiki) |
| 2026-10-01 | Fase 1 #6 selesai: F1-07 Kedokteran gigi (odontogram FDI per gigi & permukaan dengan riwayat per kunjungan, aturan penggantian kondisi, rencana perawatan berfase + estimasi & persetujuan, tindakan per gigi → odontogram otomatis & tagihan per gigi, `polis.spesialisasi`). Hash RME mencakup odontogram (RME lama tetap valid). Frontend: komponen `components/gigi/*` di pemeriksaan, detail kunjungan & pasien; master Poli & Treatment |
| 2026-10-01 | Fase 1 #5 selesai: F1-06 Foto klinis (protokol posisi, kamera terpandu di aplikasi, thumbnail terenkripsi, galeri + slider before-after, consent foto bertingkat per pasien, tautan massal bertanda tangan). Frontend: kartu Foto Klinis di pemeriksaan, detail kunjungan & pasien; master Protokol Foto |
| 2026-09-30 | Fase 1 #4 selesai: F1-05 RME estetika (template SOAP, ICD-9-CM & favorit, informed consent + tanda tangan, catatan tindakan: face chart & parameter laser, tanda tangan RME ber-SIP + hash + addendum, akses terbatas IMS). Tindakan kunjungan kini di-upsert (bukan replace-all). Poli demo KULIT & ESTETIKA. Frontend: modul rail baru **Rekam Medis**. `BookingTest` check-in tidak lagi bergantung jam dinding |
| 2026-09-30 | Fase 1 #7 selesai: F1-04 Inventori (batch & kedaluwarsa FEFO per cabang, potong BHP otomatis dua tahap dengan koreksi pemakaian, satuan fraksional & pelacakan vial terbuka, stok opname, alert kedaluwarsa). `obats.stok` kini desimal dan menjadi ringkasan `stok_batches` |
| 2026-09-30 | Fase 1 #9 selesai: F1-03 Kasir (split payment, shift kas, batas diskon per peran, void & refund, pajak, tagihan tanpa kunjungan). Menutup temuan 8.3 #3 & #7: unique `kunjungan_id` pada tagihan & resep dilepas |
| 2026-09-30 | Fase 1 #2 selesai: F1-02 Booking (entitas `appointments`, kalender multi-resource, slot dari durasi+buffer, jadwal praktik & cuti, check-in → kunjungan). Menutup temuan 8.3 #4 |
| 2026-09-30 | Fase 1 #1 selesai: F1-01 Katalog treatment (kategori, durasi + buffer, harga per cabang, BHP standar; tarif pemeriksaan dari harga cabang kunjungan). Validasi pemeriksaan kini menolak tindakan/obat yang sudah dihapus. `PeranIzinTest` tidak lagi memakai ID tetap (lulus di PostgreSQL) |
| 2026-09-30 | Frontend: navigasi baru — rail kiri berisi modul (gaya rail lama dipertahankan; versi panel sidebar penuh dibatalkan), tab header berisi halaman modul terpilih (`frontend/AI-Context/07-navigasi-modul.md`). Dev: `queue` & `scheduler` menjadi profile opsional `worker` (bind mount Windows lambat) |
| 2026-09-30 | Fase 0 selesai: F0-01 s.d. F0-07 (backend + frontend + dokumentasi) |
