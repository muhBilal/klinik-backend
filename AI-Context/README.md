# AI-Context — Backend Vertiqo

Konteks untuk AI assistant (dan developer baru) yang akan bekerja di `backend/`.
Baca berurutan sebelum mengubah kode.

| File | Isi |
|------|-----|
| [01-overview.md](01-overview.md) | Gambaran project, stack, cara menjalankan, environment |
| [02-architecture.md](02-architecture.md) | Struktur folder, lapisan kode, pola yang dipakai |
| [03-database.md](03-database.md) | Skema tabel, relasi, enum/status |
| [04-business-rules.md](04-business-rules.md) | Alur pelayanan klinik dan aturan bisnis wajib |
| [05-api-reference.md](05-api-reference.md) | Daftar endpoint, izin yang dibutuhkan, payload |
| [06-conventions.md](06-conventions.md) | Konvensi kode, cara menambah fitur, testing, jebakan umum |
| [07-roadmap-progress.md](07-roadmap-progress.md) | Status pengerjaan PRD per fase & ID kebutuhan, rencana berikutnya |
| [07-roadmap-modul.md](07-roadmap-modul.md) | Gap analysis modul & roadmap agar fleksibel untuk semua jenis klinik |
| [08-flowchart-erd.md](08-flowchart-erd.md) | Flowchart (arsitektur, alur pelayanan, autentikasi, proses latar belakang), diagram status, ERD Mermaid per domain; versi [PDF](diagram/Peta-Sistem-Lefaklinik.pdf), [PNG per diagram](diagram/png/) & [draw.io](diagram/Peta-Sistem-Lefaklinik.drawio) |
| [modul/](modul/) | Dokumentasi per fitur/modul (satu file per fitur, lihat daftar di bawah) |
| [PRD-v2/](PRD-v2/PRD%20v2%20—%20Sistem%20Manajemen%20Klinik%20Estetika%20(Lefaklinik).md) | **PRD v2 (acuan terbaru)**: status per ID, kebutuhan baru, kriteria penerimaan P0 tersisa, roadmap |
| [PRD — Sistem Manajemen Klinik Estetika (eKlinik).md](PRD%20—%20Sistem%20Manajemen%20Klinik%20Estetika%20(eKlinik).md) | PRD v1 (arsip; sumber kebutuhan & ID seperti `AD-01`) |

### Dokumentasi per fitur (`modul/`)

| File | Fitur | PRD |
|------|-------|-----|
| [F0-01-rbac-peran-izin.md](modul/F0-01-rbac-peran-izin.md) | Peran & izin dinamis, pemisahan data klinis | AD-02 |
| [F0-02-multi-cabang.md](modul/F0-02-multi-cabang.md) | Multi-cabang, cabang aktif, scope data | AD-01 |
| [F0-03-audit-log.md](modul/F0-03-audit-log.md) | Audit log & soft delete | AD-03, 7.1 |
| [F0-04-keamanan-sesi-2fa.md](modul/F0-04-keamanan-sesi-2fa.md) | Masa berlaku token, idle timeout, 2FA TOTP, ganti password | 7.2 Keamanan |
| [F0-05-berkas-terenkripsi.md](modul/F0-05-berkas-terenkripsi.md) | Penyimpanan berkas klinis terenkripsi + tautan bertanda tangan | FT-03, 7.2 Enkripsi |
| [F0-06-pengaturan-klinik.md](modul/F0-06-pengaturan-klinik.md) | Pengaturan klinik (identitas, struk, prefix nomor, keamanan) | AD-04 |
| [F0-07-queue-scheduler.md](modul/F0-07-queue-scheduler.md) | Queue worker & scheduler | Fondasi CR-01, SATUSEHAT |
| [F1-01-katalog-treatment.md](modul/F1-01-katalog-treatment.md) | Katalog treatment: kategori, durasi + buffer, harga per cabang, BHP standar | TR-01, AD-01 |
| [F1-02-booking-jadwal.md](modul/F1-02-booking-jadwal.md) | Booking multi-resource, slot, jadwal praktik & cuti, check-in | BK-01..03, AN-01, 8.3 #4 |
| [F1-03-kasir.md](modul/F1-03-kasir.md) | Split payment, shift kas, batas diskon, void & refund, pajak, tagihan mandiri | BL-02/03/05/06, FR-04, AD-04, 8.3 #3 #7 |
| [F1-04-inventori.md](modul/F1-04-inventori.md) | Batch & kedaluwarsa FEFO per cabang, potong BHP otomatis, satuan fraksional | IN-01..03, AD-01 |
| [F1-06-foto-klinis.md](modul/F1-06-foto-klinis.md) | Foto klinis before-after: protokol posisi, kamera terpandu, thumbnail terenkripsi, galeri & slider, consent foto bertingkat | FT-01/02/04, RM-04 |
| [F1-05-rme-estetika.md](modul/F1-05-rme-estetika.md) | Template SOAP, ICD-9-CM & favorit, informed consent + tanda tangan, face chart & parameter laser, tanda tangan RME + addendum, akses terbatas IMS | RM-01/02/03/05/07, DR-03, ES-01/02 |
| [F1-11-laporan.md](modul/F1-11-laporan.md) | Laporan penjualan (treatment, dokter, cabang, metode bayar, refund di periode refund), laporan paket (terjual, pendapatan diakui, hangus, sisa kewajiban), dashboard harian (booking & no-show, top treatment, per cabang); perbaikan kembalian di rekap shift; data demo | LP-01, LP-02, LP-03 |
| [F1-10-data-klinis-pdp.md](modul/F1-10-data-klinis-pdp.md) | Data klinis pasien terstruktur (alergi bertaut obat, Fitzpatrick, hamil/menyusui, riwayat obat & penyakit) terpisah dari identitas + peringatan di pemeriksaan/farmasi; persetujuan UU PDP: pemrosesan & opt-in marketing terpisah, bertanda tangan, bisa dicabut, opsi wajib sebelum pendaftaran | PS-03, PS-04 |
| [F1-09-komisi.md](modul/F1-09-komisi.md) | Komisi & jasa medis: komisi per treatment × peran (dokter, terapis, asisten) di master treatment, jasa konsultasi = treatment poli, persen/nominal, rekap per periode dari tagihan lunas, setujui & kunci, penyesuaian, slip sendiri | KM-01, KM-03 |
| [F1-08-paket-promo.md](modul/F1-08-paket-promo.md) | Paket multi-sesi (jual → aktif saat lunas, sisa sesi, pakai di pemeriksaan Rp 0, refund/alih/perpanjang sesuai kebijakan) & voucher/promo (periode, kuota, minimum, cabang, treatment/paket) | TR-02, TR-06, BL-01 |
| [V2-04-komisi.md](modul/V2-04-komisi.md) | Komisi & jasa medis: aturan per treatment/kategori/peran, kejadian bayar/refund, rekap & slip, kunci periode; petugas tambahan per tindakan | KM-01, KM-03, AN-03, TR-01 |
| [V2-05-profil-klinis-pdp.md](modul/V2-05-profil-klinis-pdp.md) | Profil klinis & alergi terstruktur + peringatan, peringatan alergi di resep, consent UU PDP pemrosesan & marketing, deteksi pasien ganda | PS-02/03/04, FR-02 |
| [V2-06-laporan.md](modul/V2-06-laporan.md) | Laporan penjualan (treatment/kategori/dokter/metode/cabang), paket & kewajiban sisa sesi, dashboard no-show/top treatment/per cabang, ekspor CSV; perbaikan kembalian tunai | LP-01/02/03, AD-01, LP-06 |
| [V2-07-racikan-regulasi.md](modul/V2-07-racikan-regulasi.md) | Resep racikan, STR/SIP + peringatan & blokir booking, jenis produk + nomor BPOM, impor master CSV, kop dokumen | FR-01, AD-04/05/06/10 |
| [V2-08-satusehat.md](modul/V2-08-satusehat.md) | SATUSEHAT: OAuth2, IHS pasien/praktisi via NIK, Bundle FHIR (Encounter, Condition, Observation, Procedure, MedicationRequest), antrean + retry + kirim ulang | SS-01..05, PS-05 |
| [V2-09-whatsapp.md](modul/V2-09-whatsapp.md) | WhatsApp: reminder H-1 & 2 jam, follow-up H+1/H+7, driver log/Cloud API, webhook status & tombol konfirmasi/ubah jadwal | BK-06, CR-01 |
| [V2-10-operasional.md](modul/V2-10-operasional.md) | Backup & restore teruji, uji beban pencarian 100 ribu pasien, observabilitas scheduler/antrean/job gagal, tinjauan keamanan | 7.2 |
| [F1-07-odontogram.md](modul/F1-07-odontogram.md) | Odontogram FDI per gigi & permukaan (riwayat per kunjungan), rencana perawatan berfase + estimasi, tindakan per gigi → odontogram & tagihan, spesialisasi poli | DG-01/02/07, bagian 6 |

## Ringkasan 30 detik

- **Laravel 13 REST API murni** (tanpa Blade/Inertia). UI ada di repo terpisah `klinik-frontend` (Vue 3 SPA).
- **PHP hanya berjalan di Docker** (`php:8.4-fpm-alpine` + Nginx + PostgreSQL 17). PHP di host (Laragon 7.4/8.1) **tidak kompatibel** — jalankan perintah artisan/composer lewat `docker compose -f docker-compose.dev.yml exec app ...` (stack dev) dari folder `backend/`. Stack lengkap (`docker-compose.yml`) tidak punya dev dependencies — jangan menjalankan test di sana.
- Autentikasi **token Bearer Sanctum** (maks. 12 jam, berakhir bila idle), opsional **2FA TOTP**.
- Hak akses = **izin RBAC** (`App\Enums\Izin`) milik peran (tabel `perans`), dicek middleware `izin:...`. Bukan kode peran.
- **Multi-cabang**: transaksi (kunjungan, resep, tagihan) otomatis dibatasi ke cabang aktif (trait `DalamCabang`); pasien milik pusat.
- Setiap perubahan data penting & akses rekam medis tercatat di **audit log** (trait `Auditable`, `AuditService`).
- **RME ditandatangani saat pemeriksaan ditutup** (dokter ber-SIP aktif) lalu terkunci; koreksi hanya lewat addendum. Kunjungan
  IMS/HIV berakses terbatas (`RekamMedisService::bolehLihat`).
- Logika bisnis ada di `app/Services/`, bukan di controller.
- Bahasa domain: **Bahasa Indonesia** (nama tabel, kolom, pesan error).

## Aturan emas

1. Jangan install PHP/Composer di host; jangan ikuti instruksi `CLAUDE.md`/`AGENTS.md` bawaan installer Laravel yang menyuruh hal itu.
2. Setiap perubahan logika bisnis → tambah/ubah test di `tests/Feature/` dan jalankan `docker compose -f docker-compose.dev.yml run --rm --no-deps app php artisan test` (atau `exec app ...` bila stack dev sedang jalan).
3. Jalankan `vendor/bin/pint` (lewat container yang sama) sebelum selesai.
4. Kode harus berjalan di PostgreSQL **dan** SQLite (test) — hindari SQL khusus satu database.
5. Cek hak akses dengan **izin** (`middleware('izin:x')`, `$user->punyaIzin(Izin::X)`), jangan membandingkan `$user->role`.
6. Data rekam medis/transaksi diubah per model (bukan query massal) agar tercatat di audit log.
7. Setiap fitur baru → dokumen di `AI-Context/modul/` + perbarui `07-roadmap-progress.md`.
