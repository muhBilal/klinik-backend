# 07 — Gap Analysis & Roadmap Modul

Tujuan: E-Klinik bisa dipakai **semua jenis klinik** (pratama, utama, gigi, KIA/bersalin, kecantikan, fisioterapi,
klinik perusahaan) tanpa fork kode — cukup **konfigurasi**. Dokumen ini memetakan apa yang sudah ada, apa yang kurang,
dan prinsip desain agar fleksibel.

Legenda prioritas: **P0** = wajib sebelum dipakai klinik nyata · **P1** = umum dibutuhkan · **P2** = spesifik jenis klinik / nilai tambah.

---

## 1. Kondisi saat ini

| Modul | Sudah ada | Batasan yang mengunci fleksibilitas |
|-------|-----------|-------------------------------------|
| Auth & user | Login Sanctum, 6 role | Role = enum hardcode (`App\Enums\Role`), 1 user = 1 role, hak akses di `routes/api.php` |
| Pasien | CRUD, no_rm otomatis, alergi (teks) | Tanpa data keluarga/penanggung jawab, alergi tidak terstruktur, tanpa upload dokumen |
| Pendaftaran & antrian | Antrian per poli per hari | `tanggal` selalu `today()` → tidak ada booking/janji temu; tanpa jadwal dokter |
| Pemeriksaan | Tanda vital, SOAP, ICD-10, tindakan, resep | Form sama untuk semua poli (tidak ada odontogram, ANC, dsb.); tanpa ICD-9-CM, tanpa penunjang |
| Farmasi | Stok, kartu stok, mutasi, serah resep | Tanpa batch/expired, supplier, pembelian, racikan, penjualan bebas, multi gudang |
| Kasir | Tagihan otomatis, diskon, 5 metode bayar | Tarif tunggal (tidak per penjamin), tanpa split payment, refund, tutup shift, piutang penjamin |
| Master | Poli, tindakan, ICD-10 (27 kode), obat | Penjamin = enum hardcode (`umum/bpjs/asuransi`) |
| Laporan | Dashboard ringkas | Belum ada laporan operasional/keuangan |
| Alur | Poli → kasir → farmasi | **Urutan hardcode** (obat hanya diserahkan setelah lunas) |

---

## 2. Prinsip desain agar fleksibel

1. **Konfigurasi, bukan kode.** Semua yang berbeda antar klinik disimpan di database, bukan enum/konstanta.
   - Tabel `settings` (key-value, JSON) untuk profil klinik, format nomor, alur, pajak, dll.
   - Enum hanya untuk status internal sistem; data bisnis (penjamin, metode bayar, jenis tindakan) jadi **tabel master**.
2. **Modul bisa di-on/off.** Tabel `modules` / setting `modul.aktif` → backend menolak route modul nonaktif (middleware
   `module:farmasi`), frontend menyembunyikan menu. Klinik kecantikan tanpa BPJS, klinik perusahaan tanpa kasir, dst.
3. **Permission, bukan role.** Role = kumpulan permission yang bisa diatur admin (mis. `spatie/laravel-permission`).
   Satu user boleh banyak role (dokter merangkap pemilik, perawat merangkap pendaftaran di klinik kecil).
4. **Form pemeriksaan dinamis.** Template form per poli disimpan sebagai JSON schema; hasil disimpan di kolom `jsonb`
   (`pemeriksaans.data_tambahan`). SOAP + tanda vital tetap kolom baku (dipakai laporan & SATUSEHAT).
5. **Alur bisa diatur.** Setting `alur.bayar_sebelum_obat` (true/false), `alur.perawat_wajib` (skip triase),
   `alur.bayar_di_depan` (klinik kecantikan/deposit). Service membaca setting, bukan asumsi.
6. **Multi-cabang sejak awal.** Kolom `klinik_id`/`cabang_id` di tabel transaksi & master yang relevan + global scope.
   Lebih murah ditambah sekarang daripada nanti.
7. **Integrasi lewat adapter.** Interface `BridgingBpjs`, `SatuSehatClient`, `PaymentGateway`, `NotifikasiChannel` —
   implementasi bisa diganti/nonaktif per klinik.
8. **Snapshot tetap dipertahankan** (tarif, harga) — sudah benar, lanjutkan untuk semua transaksi baru.

---

## 3. Modul yang kurang

### 3.1 Fondasi & Administrasi

| # | Modul | Isi | Prio |
|---|-------|-----|------|
| A1 | **Pengaturan klinik** | Profil (nama, alamat, logo, NIB, izin, kode faskes), kop surat & struk, jam operasional, zona waktu, format penomoran (`RM-{YYYY}-{0000}`), mata uang/pembulatan | P0 |
| A2 | **Modul on/off (feature flag)** | Aktif/nonaktif modul per klinik; middleware + menu dinamis | P0 |
| A3 | **Role & permission dinamis** | CRUD role, matriks permission, multi-role per user | P0 |
| A4 | **Audit log** | Siapa ubah apa & kapan (wajib untuk rekam medis), terutama pemeriksaan, tagihan, stok | P0 |
| A5 | **Multi-cabang / multi-tenant** | Data terpisah per cabang, user bisa akses beberapa cabang, laporan konsolidasi | P1 |
| A6 | **Backup & keamanan data** | Backup terjadwal, enkripsi field sensitif (NIK), kebijakan retensi, log akses RM (UU PDP) | P1 |
| A7 | **Hari libur & cuti** | Kalender libur klinik, cuti dokter (dipakai jadwal/booking) | P1 |

### 3.2 Front Office

| # | Modul | Isi | Prio |
|---|-------|-----|------|
| F1 | **Jadwal dokter** | Jadwal praktik per hari/sesi, kuota pasien, dokter pengganti | P0 |
| F2 | **Janji temu / booking** | Daftar untuk tanggal mendatang, slot waktu, check-in saat datang, no-show | P1 |
| F3 | **Display antrian & panggilan suara** | Halaman TV per poli, TTS "nomor A-012 ke Poli Umum", tiket antrian cetak | P1 |
| F4 | **Master penjamin** | Tabel penjamin (BPJS, asuransi X, perusahaan Y) menggantikan enum; kontrak, plafon, masa berlaku | P0 |
| F5 | **Data pasien lengkap** | Penanggung jawab/keluarga, foto, kartu pasien (QR/barcode), merge pasien duplikat, pasien tanpa NIK (bayi/WNA) | P1 |
| F6 | **Portal / app pasien** | Booking online, lihat antrian real-time, riwayat, hasil lab, invoice | P2 |
| F7 | **Notifikasi WA/SMS/email** | Pengingat jadwal, kontrol ulang, nomor antrian hampir dipanggil | P2 |

### 3.3 Pelayanan Medis

| # | Modul | Isi | Prio |
|---|-------|-----|------|
| M1 | **Template pemeriksaan per poli** | Form builder JSON: gigi (odontogram), KIA (ANC/KB/imunisasi), kecantikan (foto before/after), fisioterapi, MCU | P0 |
| M2 | **ICD-10 lengkap & ICD-9-CM** | Import master ICD-10 WHO/Kemenkes penuh, ICD-9-CM untuk prosedur, favorit per dokter | P0 |
| M3 | **Surat-surat** | Surat sakit, surat sehat, rujukan, keterangan, resume medis — template bisa diedit, nomor otomatis, cetak PDF | P0 |
| M4 | **Laboratorium** | Order lab dari dokter, master pemeriksaan & nilai rujukan, input hasil, cetak hasil, lab rujukan eksternal | P1 |
| M5 | **Radiologi / penunjang lain** | Order, upload hasil/gambar (PDF/DICOM link) | P2 |
| M6 | **Alergi & riwayat terstruktur** | Alergi obat terkoneksi master obat → peringatan saat resep; riwayat penyakit, riwayat keluarga | P1 |
| M7 | **Resep lanjutan** | Racikan (puyer/kapsul), signa terstruktur, resep ulang (iter), template resep, cek interaksi & dosis | P1 |
| M8 | **Informed consent & tanda tangan** | Persetujuan tindakan, tanda tangan digital pasien & dokter | P1 |
| M9 | **Dokumen & lampiran** | Upload foto/PDF ke kunjungan atau pasien | P1 |
| M10 | **Rujukan & kontrol ulang** | Rujuk internal (antar poli, satu kunjungan) & eksternal, jadwal kontrol berikutnya | P1 |
| M11 | **Paket / program perawatan** | Paket multi-sesi (kecantikan, fisioterapi, ortodonti) dengan sisa sesi | P2 |
| M12 | **Rawat inap / ODC / persalinan** | Kamar & bed, visite, catatan harian — hanya klinik utama/bersalin | P2 |

### 3.4 Farmasi & Logistik

| # | Modul | Isi | Prio |
|---|-------|-----|------|
| L1 | **Batch & kedaluwarsa** | Stok per batch + ED, FEFO saat serah obat, peringatan hampir expired | P0 |
| L2 | **Supplier & pembelian** | Master supplier, PO, penerimaan barang (faktur), retur, hutang supplier | P1 |
| L3 | **Multi gudang / depo** | Gudang utama → apotek/poli, mutasi antar gudang | P1 |
| L4 | **Stok opname** | Sesi opname terjadwal, selisih, approval (sekarang hanya mutasi `penyesuaian` per item) | P1 |
| L5 | **Penjualan bebas (OTC)** | Jual obat/produk tanpa kunjungan (apotek klinik, produk skincare) | P1 |
| L6 | **Harga & satuan** | Konversi satuan (box→strip→tablet), margin/HNA+PPN, harga per penjamin | P1 |
| L7 | **Bahan habis pakai (BHP) & alat** | BHP otomatis berkurang saat tindakan (resep tindakan), inventaris alat & kalibrasi | P2 |
| L8 | **Obat narkotik/psikotropika** | Laporan khusus sesuai ketentuan BPOM | P2 |

### 3.5 Keuangan

| # | Modul | Isi | Prio |
|---|-------|-----|------|
| K1 | **Tarif fleksibel** | Matriks tarif: item × penjamin × (opsional) kelas/cabang; tarif berlaku mulai tanggal | P0 |
| K2 | **Pembatalan & refund** | Void tagihan dengan alasan + approval, retur obat ke stok | P0 |
| K3 | **Cetak kwitansi/struk** | Template struk thermal & A4, cetak ulang | P0 |
| K4 | **Shift & tutup kasir** | Buka/tutup kasir, saldo awal, rekap per metode bayar, selisih | P1 |
| K5 | **Split payment & deposit** | Bayar sebagian, kombinasi metode, deposit/uang muka, cicilan paket | P1 |
| K6 | **Piutang penjamin** | Tagihan ke asuransi/perusahaan, klaim, pelunasan, aging | P1 |
| K7 | **Jasa medis (fee dokter)** | Aturan bagi hasil per tindakan/dokter (persen/nominal), rekap bulanan | P1 |
| K8 | **Pajak & diskon** | PPN opsional, aturan diskon/promo/voucher, membership | P2 |
| K9 | **Pengeluaran & akuntansi dasar** | Kas keluar, kategori biaya, laba-rugi sederhana, ekspor ke software akuntansi | P2 |
| K10 | **Payment gateway** | QRIS dinamis / VA (Midtrans/Xendit) via adapter | P2 |

### 3.6 Integrasi Pemerintah & Pihak Ketiga

| # | Modul | Isi | Prio |
|---|-------|-----|------|
| I1 | **SATUSEHAT** | Kirim Encounter, Condition, Observation (vital), Procedure, MedicationRequest/Dispense; mapping KFA untuk obat, LOINC untuk lab. Wajib RME (Permenkes 24/2022) | P0 |
| I2 | **BPJS Kesehatan** | PCare (FKTP) / VClaim (FKRTL), Antrean Online (Mobile JKN), cek kepesertaan, rujukan | P1 (wajib bila melayani BPJS) |
| I3 | **Asuransi swasta / TPA** | Cek eligibilitas, klaim (umumnya manual + ekspor) | P2 |
| I4 | **API publik / webhook** | Untuk integrasi sistem lain (HRIS perusahaan, lab eksternal) | P2 |

### 3.7 Laporan & Analitik

| # | Laporan | Prio |
|---|---------|------|
| R1 | Kunjungan harian/bulanan per poli, dokter, penjamin; pasien baru vs lama | P0 |
| R2 | 10 besar penyakit (ICD-10), per umur & jenis kelamin | P0 |
| R3 | Pendapatan per kategori / metode bayar / kasir; tagihan belum lunas | P0 |
| R4 | Stok: obat hampir habis, hampir expired, pemakaian obat, nilai persediaan | P1 |
| R5 | Jasa medis dokter, waktu tunggu pasien (daftar → panggil → selesai) | P1 |
| R6 | Laporan dinas kesehatan (LB1, imunisasi, KIA) — template per wilayah | P2 |
| R7 | Ekspor Excel/PDF untuk semua laporan | P0 |

---

## 4. Matriks modul per jenis klinik

✓ = umumnya dipakai · ○ = opsional · — = jarang

| Modul | Pratama | Utama | Gigi | KIA/Bersalin | Kecantikan | Fisioterapi | Perusahaan |
|-------|:-------:|:-----:|:----:|:------------:|:----------:|:-----------:|:----------:|
| Jadwal & booking | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ○ |
| Template pemeriksaan khusus | ○ | ✓ | ✓ odontogram | ✓ ANC/KB | ✓ foto | ✓ | ✓ MCU |
| Laboratorium | ○ | ✓ | — | ✓ | — | — | ✓ |
| Farmasi & resep | ✓ | ✓ | ○ | ✓ | ○ | — | ✓ |
| Penjualan bebas | ○ | ○ | ○ | ○ | ✓ | ○ | — |
| Paket / multi-sesi | — | ○ | ✓ | ○ | ✓ | ✓ | — |
| Rawat inap / persalinan | — | ○ | — | ✓ | — | — | — |
| BPJS | ✓ | ✓ | ○ | ✓ | — | ○ | ○ |
| Kasir | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ○ (ditanggung) |
| Membership/promo | — | — | ○ | — | ✓ | ○ | — |
| SATUSEHAT | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |

Matriks ini bisa jadi **preset** saat instalasi: admin pilih jenis klinik → modul & template default ter-set otomatis,
lalu tetap bisa diubah.

---

## 5. Perubahan pada kode yang ada

| Bagian | Sekarang | Usulan |
|--------|----------|--------|
| `App\Enums\Role` + `role:` middleware | Hardcode | Tabel `roles`/`permissions`, middleware `can:` |
| `App\Enums\Penjamin` | Enum 3 nilai | Tabel `penjamins` (FK di `kunjungans.penjamin_id`) |
| `App\Enums\MetodeBayar` | Enum | Tabel `metode_bayars` (aktif/nonaktif, akun kas) |
| `polis.tarif_konsultasi`, `tindakans.tarif`, `obats.harga` | Satu harga | Tabel `tarifs` (item polymorphic × penjamin × berlaku_mulai); kolom lama jadi default |
| `KunjunganController::store` | `tanggal = today()` | Terima tanggal + slot bila modul booking aktif |
| `FarmasiService` (serah setelah lunas) | Hardcode | Baca setting `alur.bayar_sebelum_obat` |
| `pemeriksaans` | Kolom tetap | + `template_id`, `data_tambahan jsonb` |
| `obats.stok` | Satu angka | Stok per batch per gudang (`obat_batches`), `obats.stok` jadi agregat |
| `counters` | Format tetap | Format dari setting (prefix, reset tahunan/bulanan, panjang digit) |
| Semua tabel transaksi | Tanpa cabang | + `cabang_id` + global scope |

---

## 6. Urutan implementasi yang disarankan

1. **Fondasi (P0):** pengaturan klinik (A1), feature flag (A2), permission (A3), audit log (A4), master penjamin (F4), tarif fleksibel (K1).
   Ini mengubah skema inti — kerjakan dulu sebelum modul lain agar tidak migrasi dua kali.
2. **Operasional harian (P0):** jadwal dokter (F1), ICD lengkap (M2), surat (M3), cetak struk (K3), refund (K2), batch/ED (L1), laporan R1–R3 + R7.
3. **Kepatuhan:** SATUSEHAT (I1), lalu BPJS (I2) bagi klinik yang melayani JKN.
4. **Fleksibilitas jenis klinik:** template pemeriksaan (M1), lab (M4), penjualan bebas (L5), paket (M11), shift kasir (K4), fee dokter (K7).
5. **Skala & nilai tambah:** multi-cabang (A5 — atau lebih awal bila target pasar jaringan klinik), booking & portal pasien (F2, F6), notifikasi (F7), payment gateway (K10), akuntansi (K9).

Setiap modul baru: tambah test di `tests/Feature/`, update `03-database.md`, `04-business-rules.md`, `05-api-reference.md`.
