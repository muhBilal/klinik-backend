# 03 — Database

PostgreSQL 17. Semua nominal uang disimpan sebagai **integer rupiah** (tanpa desimal).
Status disimpan sebagai string dan di-cast ke Enum pada model.

## Diagram relasi

```
cabangs 1─* users (cabang_id, null = lintas cabang)
cabangs 1─* kunjungans / reseps / tagihans / berkas (cabang_id)
cabangs 1─* tindakan_hargas *─1 tindakans *─1 kategori_tindakans
tindakans 1─* tindakan_bhps *─1 obats             (BHP standar)
perans 1─* peran_izins                      perans.kode ─* users.role
polis 1─* users (dokter.poli_id)
polis 1─* kunjungans *─1 pasiens
users 1─* kunjungans (dokter_id, created_by)

kunjungans 1─1 pemeriksaans 1─* pemeriksaan_diagnosas *─1 icd10s
pemeriksaans 1─* pemeriksaan_addendums                 (koreksi setelah ditandatangani, append-only)
kunjungans 1─* kunjungan_tindakans *─1 tindakans
kunjungan_tindakans *─1 icd9cms, *─1 users (petugas_id)
kunjungan_tindakans 1─1 catatan_tindakans 1─* catatan_tindakan_titiks *─1 obats / stok_batches
kunjungans 1─* informed_consents *─1 template_consents   (kunjungan_tindakan_id opsional)
tindakans *─1 icd9cms, *─1 template_consents              (consent wajib)
template_soaps *─1 polis / tindakans                      kode_favorits (user_id, jenis, kode_id)
kunjungans 1─1 reseps 1─* resep_items *─1 obats
kunjungans 1─1 tagihans 1─* tagihan_items
kunjungans 1─* berkas *─1 pasiens                       (foto: + protokol_foto_id, posisi, tahap, kunjungan_tindakan_id)
protokol_fotos 1─* berkas, 1─* tindakans                 pasiens 1─* persetujuan_fotos (≤1 berlaku)
pasiens 1─* odontogram_kondisis *─1 kunjungans (dicatat) / kunjungans (berakhir_kunjungan_id) / kunjungan_tindakans (turunan)
pasiens 1─* rencana_perawatans 1─* rencana_perawatan_items *─1 tindakans
kunjungan_tindakans *─1 rencana_perawatan_items (rencana_item_id: item yang dikerjakan)
obats 1─* stok_mutasis

audit_logs (tanpa FK: user_id, cabang_id, pasien_id, tipe + subjek_id)
pengaturans (kunci → nilai JSON)
```

## Tabel

### Master
| Tabel | Kolom penting |
|-------|---------------|
| `cabangs` | kode (unik, huruf besar), nama, alamat, telepon, email, jam_buka, jam_tutup (TIME, dikirim `HH:MM`), is_active, deleted_at |
| `perans` | kode (unik, `^[a-z][a-z0-9_]*$`), nama, deskripsi, **is_sistem** (tidak bisa dihapus/ganti kode), **akses_penuh** (semua izin) |
| `peran_izins` | peran_id, izin (nilai `App\Enums\Izin`). Unik `(peran_id, izin)` |
| `users` | name, email (unik), password, **role** (= `perans.kode`), poli_id (dokter), **cabang_id** (null = semua cabang), sip, **sip_berlaku_sampai**, is_active, two_factor_secret (terenkripsi), two_factor_recovery_codes (terenkripsi), two_factor_confirmed_at, two_factor_last_step, deleted_at |
| `polis` | kode (unik), nama, **spesialisasi** (`umum`/`gigi`/`kulit`/`estetika`/`lainnya`; `gigi` = odontogram di pemeriksaan), tarif_konsultasi, is_active, deleted_at |
| `icd10s` | kode (unik), nama, **sensitif** (IMS/HIV → kunjungan berakses terbatas) |
| `icd9cms` | kode (unik), nama — kode tindakan/prosedur (62 kode dasar diisi migration) |
| `protokol_fotos` | nama, deskripsi, posisi (JSON `[{kode, label, petunjuk}]`), is_active, deleted_at — 5 protokol dasar diisi migration |
| `template_soaps` | nama, poli_id (null = semua), tindakan_id, subjektif, objektif, asesmen, plan, icd10_ids (JSON), akses_terbatas, is_active, deleted_at |
| `template_consents` | nama, isi (placeholder `{nama_pasien}` dst.), is_active, deleted_at |
| `kode_favorits` | user_id, jenis (`icd10`/`icd9cm`), kode_id. Unik `(user_id, jenis, kode_id)` |
| `kategori_tindakans` | nama (unik), deskripsi, is_active, deleted_at |
| `tindakans` | kode (unik), nama, **kategori_id** (nullable), **icd9cm_id**, **template_consent_id** (diisi = wajib consent), **jenis_catatan** (`umum`/`injeksi`/`energi`), **per_gigi**, **kondisi_gigi_hasil** (kode odontogram setelah tindakan), **durasi_menit** (default 15), **buffer_menit** (default 0), **tarif** (= harga dasar pusat), is_active, deleted_at |
| `tindakan_hargas` | tindakan_id, cabang_id, tarif, **tersedia** (false = tidak dilayani di cabang itu). Unik `(tindakan_id, cabang_id)`. Tanpa baris = harga dasar |
| `tindakan_bhps` | tindakan_id, obat_id, jumlah `decimal(10,3)` (satuan stok obat, boleh fraksional). Unik `(tindakan_id, obat_id)`. Belum memotong stok (IN-02) |
| `obats` | kode (unik), nama, satuan, harga, **stok** (int, hanya diubah via FarmasiService; masih global, belum per cabang), stok_minimum, is_active, deleted_at |
| `pasiens` | **no_rm** (unik, auto), nik (unik, 16 digit, nullable), no_bpjs, nama, jenis_kelamin (`L`/`P`), tempat_lahir, tanggal_lahir, golongan_darah, alamat, no_hp, pekerjaan, alergi, deleted_at. Appends: `umur` ("34 th"/"8 bln"). **Milik pusat, lintas cabang** |

### Transaksi (milik satu cabang — trait `DalamCabang`)
| Tabel | Kolom penting |
|-------|---------------|
| `kunjungans` | **cabang_id**, no_registrasi (unik), pasien_id, poli_id, dokter_id, tanggal, no_antrian, penjamin, no_penjamin, keluhan, **akses_terbatas**, **status**, dipanggil_at, selesai_at, created_by. Unik `(cabang_id, poli_id, tanggal, no_antrian)` |
| `pemeriksaans` | kunjungan_id (unik), tekanan_darah (`"120/80"`), nadi, suhu, respirasi, berat_badan, tinggi_badan, subjektif, objektif, asesmen, plan, perawat_id, dokter_id, **ditandatangani_at**, **ditandatangani_oleh**, **hash_ttd** (terkunci setelah ditandatangani) |
| `pemeriksaan_addendums` | pemeriksaan_id, user_id, bagian, isi, alasan, created_at — tidak bisa diubah/dihapus |
| `catatan_tindakans` | kunjungan_tindakan_id (unik), jenis, area, catatan, parameter (JSON parameter alat), sumber_daya_id (alat), dicatat_oleh |
| `catatan_tindakan_titiks` | catatan_tindakan_id, tampilan, x, y (0..1), area, obat_id, batch_id, jumlah, satuan, kedalaman, alat (jarum/kanula), catatan |
| `informed_consents` | uuid, cabang_id, kunjungan_id, pasien_id, kunjungan_tindakan_id, template_consent_id, judul, tindakan_nama, isi (snapshot), status, penandatangan_nama, hubungan, ttd_penandatangan & ttd_saksi (**terenkripsi**), saksi_nama, dokter_id, dibuat_oleh, ditandatangani_at, dicabut_at/_oleh, alasan_cabut, checksum, ip_address — tidak pernah dihapus |
| `pemeriksaan_diagnosas` | pemeriksaan_id, icd10_id, jenis (`primer`/`sekunder`) |
| `kunjungan_tindakans` | kunjungan_id, tindakan_id, jumlah, **tarif (snapshot harga cabang kunjungan)**, **petugas_id**, **icd9cm_id**, **gigi** (FDI), **permukaan** (`MO`), **rencana_item_id**, keterangan |
| `odontogram_kondisis` | pasien_id, cabang_id, kunjungan_id (dicatat), kunjungan_tindakan_id (turunan tindakan), gigi, permukaan (null = seluruh gigi), kondisi, keterangan, dicatat_oleh, berakhir_kunjungan_id, berakhir_at, berakhir_oleh, berakhir_karena_id — terkunci setelah kunjungannya ditutup |
| `rencana_perawatans` | pasien_id, cabang_id (dasar harga), kunjungan_id, dokter_id, judul, catatan, status, disetujui_at/_oleh, penyetuju_nama, selesai_at, dibatalkan_at/_oleh, alasan_batal, created_by |
| `rencana_perawatan_items` | rencana_perawatan_id, fase (1–9), urutan, gigi, permukaan, tindakan_id, jumlah, tarif (estimasi), keterangan, status, selesai_at |
| `reseps` | **cabang_id** (= cabang kunjungan), no_resep, kunjungan_id (unik), dokter_id, **status**, catatan, apoteker_id, diserahkan_at |
| `resep_items` | resep_id, obat_id, jumlah, aturan_pakai, **harga (snapshot)** |
| `tagihans` | **cabang_id** (= cabang kunjungan), no_tagihan, kunjungan_id (unik), total, diskon, grand_total, **status**, metode_bayar, dibayar, kembalian, kasir_id, dibayar_at |
| `tagihan_items` | tagihan_id, kategori (`konsultasi`/`tindakan`/`obat`), deskripsi, jumlah, harga, subtotal |
| `stok_mutasis` | obat_id, jenis, jumlah (**bertanda**: + masuk, − keluar), stok_akhir, referensi (mis. no_resep), keterangan, user_id |
| `berkas` | uuid (unik, route key), cabang_id (informasi, **tidak** di-scope), pasien_id, kunjungan_id, **kunjungan_tindakan_id**, kategori, **protokol_foto_id**, **posisi**, **tahap** (sebelum/sesudah/kontrol), keterangan, nama_file, mime, ukuran (byte asli), **diambil_at**, **lebar**, **tinggi**, path & **thumbnail_path** (tersembunyi), checksum SHA-256 (tersembunyi), diunggah_oleh, deleted_at |
| `persetujuan_fotos` | uuid, pasien_id, cabang_id, kunjungan_id, tingkat (klinis/edukasi/marketing), isi (snapshot), status (berlaku/diganti/dicabut), penandatangan_nama, hubungan, ttd (**terenkripsi**), dibuat_oleh, ditandatangani_at, berakhir_at, dicabut_oleh, alasan_cabut, checksum — tidak pernah dihapus |

`cabang_id` di tabel transaksi nullable di skema (agar migrasi data lama aman) tetapi **selalu diisi aplikasi**.

### Sistem
| Tabel | Isi |
|-------|-----|
| `audit_logs` | user_id, cabang_id, **aksi**, **tipe** + subjek_id, pasien_id, label, perubahan (JSON `{kolom: {lama, baru}}`), ip_address, user_agent, created_at. **Append-only**, tanpa FK. Lihat [modul/F0-03-audit-log.md](modul/F0-03-audit-log.md) |
| `pengaturans` | kunci (PK, mis. `klinik.nama`), nilai (JSON), updated_by, updated_at. Hanya berisi kunci yang pernah diubah; default di `config/eklinik.php` |
| `counters` | penomoran (lihat 02-architecture.md) |
| `personal_access_tokens` | Sanctum (`expires_at` diisi saat login) |
| `jobs`, `job_batches`, `failed_jobs` | antrian (`QUEUE_CONNECTION=database`) |
| `cache`, `cache_locks`, `sessions`, `password_reset_tokens` | bawaan Laravel |

## Migration Fase 0 (2026-09-30)

| File | Isi |
|------|-----|
| `100001_create_pengaturans_table` | tabel pengaturan |
| `100002_create_perans_table` | `perans`, `peran_izins` + **seed 9 peran bawaan** (6 sistem + terapis, manajer, marketing) dengan izin setara perilaku lama |
| `100003_create_cabangs_table` | `cabangs`, kolom `cabang_id` (users, kunjungans, reseps, tagihans), unik antrian per cabang, **migrasi data lama** ke cabang `UTAMA` |
| `100004_create_audit_logs_table` | audit log |
| `100005_add_soft_deletes_to_master_tables` | `deleted_at` pada pasiens, users, obats, tindakans, polis |
| `100006_add_two_factor_columns_to_users_table` | kolom 2FA |
| `100007_create_berkas_table` | metadata berkas terenkripsi |

Sudah diuji `migrate` + `migrate:rollback` + `migrate` ulang di PostgreSQL 17 dengan salinan data demo.

## Migration Fase 1

| File | Isi | Modul |
|------|-----|-------|
| `2026_09_30_110001_create_katalog_treatment_tables` | `kategori_tindakans`, kolom `kategori_id`/`durasi_menit`/`buffer_menit` di `tindakans`, `tindakan_hargas`, `tindakan_bhps` | [F1-01](modul/F1-01-katalog-treatment.md) |
| `2026_09_30_120001_create_booking_tables` (+ `120002` izin) | `sumber_dayas`, `tindakan_sumber_dayas`, `jadwal_praktiks`, `jadwal_pengecualians`, `appointments` | [F1-02](modul/F1-02-booking-jadwal.md) |
| `2026_09_30_130001_create_kasir_tables` (+ `130002` izin) | `pembayarans`, `shift_kas`, tagihan tanpa kunjungan, pajak | [F1-03](modul/F1-03-kasir.md) |
| `2026_09_30_140001_create_inventori_tables` (+ `140002` izin) | `stok_batches`, `kunjungan_tindakan_bhps`, stok desimal | [F1-04](modul/F1-04-inventori.md) |
| `2026_10_01_100001_create_foto_klinis_tables` | `protokol_fotos` (+ 5 protokol), `persetujuan_fotos`; kolom foto di `berkas`, `tindakans.protokol_foto_id` | [F1-06](modul/F1-06-foto-klinis.md) |
| `2026_10_01_110001_create_odontogram_tables` | `polis.spesialisasi`, `tindakans.per_gigi/kondisi_gigi_hasil`, `odontogram_kondisis`, `rencana_perawatans`, `rencana_perawatan_items`, kolom gigi di `kunjungan_tindakans`; isi data lama dari kode/nama poli & ICD-9-CM 23.xx | [F1-07](modul/F1-07-odontogram.md) |
| `2026_09_30_150001_create_rme_estetika_tables` (+ `150002` izin) | `icd9cms` (+ 62 kode), `template_soaps`, `template_consents`, `catatan_tindakans`, `catatan_tindakan_titiks`, `informed_consents`, `pemeriksaan_addendums`, `kode_favorits`; kolom baru di `icd10s`, `tindakans`, `kunjungans`, `kunjungan_tindakans`, `pemeriksaans`, `users` | [F1-05](modul/F1-05-rme-estetika.md) |

## Model ↔ tabel

Nama tabel diset eksplisit dengan `#[Table('...')]` karena pluralisasi Inggris tidak cocok untuk kata Indonesia.
Selalu lakukan hal yang sama untuk model baru.

| Model | Tabel | Trait |
|-------|-------|-------|
| Cabang | cabangs | Auditable, SoftDeletes |
| Peran / PeranIzin | perans / peran_izins | Auditable (Peran) |
| User | users | Auditable, SoftDeletes |
| Poli | polis | Auditable, SoftDeletes |
| Pasien | pasiens | Auditable, SoftDeletes |
| Icd10 | icd10s | Auditable. `kodeSensitif($kode)` = pola IMS/HIV |
| Icd9cm | icd9cms | Auditable |
| KodeFavorit | kode_favorits | — (preferensi pribadi) |
| TemplateSoap / TemplateConsent | template_soaps / template_consents | Auditable, SoftDeletes |
| CatatanTindakan / CatatanTindakanTitik | catatan_tindakans / catatan_tindakan_titiks | Auditable |
| InformedConsent | informed_consents | Auditable (tanda tangan & naskah tidak disalin ke audit), route key `uuid` |
| PemeriksaanAddendum | pemeriksaan_addendums | Auditable, menolak update/delete |
| ProtokolFoto | protokol_fotos | Auditable, SoftDeletes |
| PersetujuanFoto | persetujuan_fotos | Auditable (ttd/naskah tidak disalin ke audit), route key `uuid` |
| OdontogramKondisi | odontogram_kondisis | Auditable; scope `aktif`, `berlakuPada($kunjunganId)`; menolak ubah/hapus isi bila kunjungannya sudah ditutup |
| RencanaPerawatan / RencanaPerawatanItem | rencana_perawatans / rencana_perawatan_items | Auditable. `RencanaPerawatanItem::pelaksanaan` = tindakan kunjungan terbaru yang mengerjakannya |
| KategoriTindakan | kategori_tindakans | Auditable, SoftDeletes |
| Tindakan | tindakans | Auditable, SoftDeletes. Scope `denganHargaCabang($cabangId)` (+`tarif_cabang`, `tersedia`), `tersediaDi($cabangId)` |
| TindakanHarga | tindakan_hargas | Auditable (tercatat dengan `cabang_id` harganya) |
| TindakanBhp | tindakan_bhps | Auditable |
| Obat | obats | Auditable (kolom `stok` diabaikan — sudah di kartu stok), SoftDeletes |
| StokMutasi | stok_mutasis | — (ledger); kini punya `cabang_id` & `batch_id` |
| StokBatch | stok_batches | Auditable, **DalamCabang** — stok per cabang per batch (FEFO) |
| KunjunganTindakanBhp | kunjungan_tindakan_bhps | Auditable — pemakaian BHP aktual |
| Kunjungan | kunjungans | Auditable, **DalamCabang** |
| Pemeriksaan | pemeriksaans | Auditable; menolak update/delete setelah `ditandatangani_at` terisi |
| PemeriksaanDiagnosa | pemeriksaan_diagnosas | Auditable |
| KunjunganTindakan | kunjungan_tindakans | Auditable |
| Resep / ResepItem | reseps / resep_items | Auditable, **DalamCabang** (Resep) |
| Tagihan / TagihanItem | tagihans / tagihan_items | Auditable, **DalamCabang**, SoftDeletes (Tagihan) |
| Pembayaran | pembayarans | Auditable — split payment; refund menandai `dikembalikan_at` |
| ShiftKas | shift_kas | Auditable, **DalamCabang** |
| Appointment / AppointmentTindakan | appointments / appointment_tindakans | Auditable, **DalamCabang**, SoftDeletes (Appointment) |
| SumberDaya | sumber_dayas | Auditable, **DalamCabang**, SoftDeletes — ruang & alat |
| JadwalPraktik / JadwalPengecualian | jadwal_praktiks / jadwal_pengecualians | Auditable, **DalamCabang** |
| Berkas | berkas | Auditable, SoftDeletes |
| AuditLog | audit_logs | — (menolak update/delete) |

`Kunjungan::loadDetail($rekamMedis = true)` memuat seluruh relasi untuk halaman detail/pemeriksaan — pakai ini agar
bentuk respons konsisten. Relasi ke `User` (dokter, perawat, kasir, apoteker, pengunggah) memakai `withTrashed()` agar nama
petugas yang sudah dihapus tetap tampil di riwayat.

## Kardinalitas yang berubah di Fase 1

- `tagihans.kunjungan_id` **tidak unique** dan **nullable**: satu kunjungan boleh punya beberapa tagihan, dan tagihan
  boleh berdiri sendiri (produk/paket/deposit, `pasien_id` diisi langsung). Sama untuk `reseps.kunjungan_id` (tidak unique).
  Relasi `Kunjungan::tagihan()`/`resep()` mengambil baris terbaru yang bukan batal; `tagihans()`/`reseps()` untuk semuanya.
- `appointments.kunjungan_id` unique & nullable: satu booking menghasilkan paling banyak satu kunjungan (saat check-in).
- `obats.stok` = **ringkasan** `stok_batches` lintas cabang, bertipe desimal (12,3). Stok nyata per cabang ada di batch.

## Aturan hapus

- Data yang sudah dipakai transaksi **tidak boleh dihapus** (controller `abort_if(..., 422)`): pasien dengan kunjungan
  (di cabang mana pun), poli dengan kunjungan, obat yang pernah diresepkan, tindakan yang pernah dipakai, ICD-10 yang dipakai
  diagnosa, cabang yang punya kunjungan/pengguna, peran sistem atau peran yang masih dipakai, kategori treatment yang masih
  dipakai treatment, obat yang menjadi BHP standar treatment, ruang/alat yang masih dipakai booking mendatang,
  booking yang sudah menjadi kunjungan. Solusinya menonaktifkan (`is_active=false`).
- Hapus yang diizinkan = **soft delete** (pasien, pengguna, obat, tindakan, kategori treatment, poli, cabang, berkas). Harga cabang &
  BHP standar ikut tersimpan saat treatment di-soft delete. Kolom unik (NIK, email,
  kode) tetap terpakai oleh baris yang dihapus — pulihkan data lama, jangan membuat duplikat.
- Rekam medis (pemeriksaan, diagnosa, tindakan, resep) tidak punya endpoint hapus; penggantian diagnosa/tindakan/item resep
  saat pemeriksaan masih terbuka dilakukan per model sehingga tercatat di audit log. Tindakan kunjungan di-upsert (cocok `id`
  atau `tindakan_id`) sehingga catatan & consent-nya tidak ikut terhapus saat pemeriksaan disimpan ulang.
- Informed consent & addendum tidak pernah dihapus. Kode ICD-9-CM yang dipakai tindakan/treatment dan template consent yang masih
  dipasang ke treatment tidak bisa dihapus.
- Kondisi odontogram tidak dihapus setelah kunjungannya ditutup — kondisi yang tidak berlaku lagi diakhiri di kunjungan berikutnya.
  Selama kunjungan terbuka, kondisi manual yang dicatat di sana boleh dihapus (koreksi). Item rencana yang sudah dikerjakan tidak
  bisa dihapus; rencana dibatalkan (status), tidak dihapus.
