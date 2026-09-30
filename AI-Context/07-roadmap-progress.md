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

Temuan teknis PRD 8.3 yang sudah ditutup: #1 token tanpa kedaluwarsa, #2 kolom cabang, #5 audit log & soft delete,
#6 queue/scheduler/penyimpanan file. Belum: #3 tagihan/resep unik per kunjungan (dikerjakan bersama Billing/paket di Fase 1),
#4 kunjungan selalu hari ini (dikerjakan bersama Booking), #7 endpoint batal tagihan/resep (BL-06).

Test backend: 43 test / 450 assertion lulus (SQLite). Migrasi & rollback diuji di PostgreSQL 17 dengan salinan data demo.
Smoke test HTTP lewat nginx (stack dev terisolasi) lulus: pengaturan, cabang, pendaftaran lintas cabang, unggah & unduh berkas, audit, CORS.

## Status kebutuhan P0

| ID | Status | Catatan |
|----|--------|---------|
| AD-01 | Parsial | cabang & scope transaksi; harga treatment per cabang (F1-01); stok per cabang & laporan konsolidasi belum |
| AD-02 | Selesai | peran & izin dinamis, pemisahan data klinis (`rme.lihat`) |
| AD-03 | Selesai | perubahan data + akses RME/berkas + login |
| AD-04 | Parsial | identitas, struk, lebar kertas, prefix, keamanan; pajak & template dokumen lain belum |
| FT-03 | Selesai | enkripsi, tautan bertanda tangan, audit akses |
| PS-01 | Selesai | (sudah ada sebelumnya) |
| FR-03 | Selesai | (sudah ada sebelumnya; kop etiket kini dari pengaturan) |
| TR-01 | Parsial | kategori, durasi + buffer, harga per cabang, BHP standar selesai (F1-01); aturan komisi → KM-01 |
| Lainnya | lihat PRD bagian 8.2 | belum berubah |

## Fase 1 — MVP estetika (sedang berjalan)

Urutan kerja yang disarankan (dependensi di kolom kanan):

| # | Modul | PRD | Bergantung pada | Status | Dokumen |
|---|-------|-----|-----------------|--------|---------|
| 1 | Katalog treatment: kategori, durasi, harga per cabang, BHP standar | TR-01 | F0-02 | **Selesai** 30 Sep 2026 (komisi → #10) | [modul/F1-01](modul/F1-01-katalog-treatment.md) |
| 2 | Jadwal praktik & booking multi-resource (entitas `appointment` → kunjungan saat check-in) | BK-01..03, AN-01, 8.3 #4 | 1 | **Berikutnya** | |
| 3 | Reminder WhatsApp (job + scheduler) | BK-06, CR-01 | 2, F0-07 | Belum | |
| 4 | RME estetika: template SOAP, ICD-9-CM, informed consent + tanda tangan, catatan tindakan (dosis, batch, parameter alat), addendum | RM-01/02/03/05/07, DR-03, ES-01/02 | F0-03, F0-05 | Belum | |
| 5 | Foto klinis before-after + consent foto | FT-01, FT-02, FT-04 | F0-05 | Belum | |
| 6 | Odontogram & treatment plan per gigi | DG-01, DG-02, DG-07 | 4 | Belum | |
| 7 | Inventori: batch/expired per gudang cabang, BHP otomatis, satuan fraksional | IN-01..03 | 1, F0-02 | Belum | |
| 8 | Paket multi-sesi, voucher & promo | TR-02, TR-06 | 1 | Belum | |
| 9 | Kasir: tagihan tanpa kunjungan, split payment, deposit, diskon per peran, void/refund, shift kas, pajak | BL-01..03/05/06, AD-04, 8.3 #3 #7 | 8 | Belum | |
| 10 | Komisi dokter & terapis (termasuk aturan komisi per treatment dari TR-01) | KM-01, KM-03 | 1, 9 | Belum | |
| 11 | Consent data pasien (UU PDP) & data klinis pasien terstruktur | PS-03, PS-04 | — | Belum | |
| 12 | Integrasi SATUSEHAT (IHS pasien, Encounter, Condition, ...) | PS-05, 7.1 | 4, F0-07 | Belum | |
| 13 | Laporan penjualan & paket | LP-01..03 | 8, 9 | Belum | |

Catatan untuk #2 (Booking): slot = `tindakans.durasi_menit + buffer_menit`; treatment yang `tersedia=false` di suatu cabang tidak
boleh dibooking di cabang itu. Kebutuhan ruang/alat per treatment (BK-01) belum ada di katalog — tambahkan di modul booking.

Test backend setelah F1-01: 49 test / 530 assertion lulus di SQLite **dan** PostgreSQL 17 (container sementara). Migrasi F1-01
diuji migrate → rollback → migrate di PostgreSQL 17.

Gerbang PRD Fase 1: "P0 lolos UAT, go-live klinik pilot".

## Riwayat

| Tanggal | Perubahan |
|---------|-----------|
| 2026-09-30 | Fase 1 #1 selesai: F1-01 Katalog treatment (kategori, durasi + buffer, harga per cabang, BHP standar; tarif pemeriksaan dari harga cabang kunjungan). Validasi pemeriksaan kini menolak tindakan/obat yang sudah dihapus. `PeranIzinTest` tidak lagi memakai ID tetap (lulus di PostgreSQL) |
| 2026-09-30 | Frontend: navigasi baru — rail kiri berisi modul (gaya rail lama dipertahankan; versi panel sidebar penuh dibatalkan), tab header berisi halaman modul terpilih (`frontend/AI-Context/07-navigasi-modul.md`). Dev: `queue` & `scheduler` menjadi profile opsional `worker` (bind mount Windows lambat) |
| 2026-09-30 | Fase 0 selesai: F0-01 s.d. F0-07 (backend + frontend + dokumentasi) |
