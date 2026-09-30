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
kunjungans 1─* kunjungan_tindakans *─1 tindakans
kunjungans 1─1 reseps 1─* resep_items *─1 obats
kunjungans 1─1 tagihans 1─* tagihan_items
kunjungans 1─* berkas *─1 pasiens
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
| `users` | name, email (unik), password, **role** (= `perans.kode`), poli_id (dokter), **cabang_id** (null = semua cabang), sip, is_active, two_factor_secret (terenkripsi), two_factor_recovery_codes (terenkripsi), two_factor_confirmed_at, two_factor_last_step, deleted_at |
| `polis` | kode (unik), nama, tarif_konsultasi, is_active, deleted_at |
| `icd10s` | kode (unik), nama |
| `kategori_tindakans` | nama (unik), deskripsi, is_active, deleted_at |
| `tindakans` | kode (unik), nama, **kategori_id** (nullable), **durasi_menit** (default 15), **buffer_menit** (default 0), **tarif** (= harga dasar pusat), is_active, deleted_at |
| `tindakan_hargas` | tindakan_id, cabang_id, tarif, **tersedia** (false = tidak dilayani di cabang itu). Unik `(tindakan_id, cabang_id)`. Tanpa baris = harga dasar |
| `tindakan_bhps` | tindakan_id, obat_id, jumlah `decimal(10,3)` (satuan stok obat, boleh fraksional). Unik `(tindakan_id, obat_id)`. Belum memotong stok (IN-02) |
| `obats` | kode (unik), nama, satuan, harga, **stok** (int, hanya diubah via FarmasiService; masih global, belum per cabang), stok_minimum, is_active, deleted_at |
| `pasiens` | **no_rm** (unik, auto), nik (unik, 16 digit, nullable), no_bpjs, nama, jenis_kelamin (`L`/`P`), tempat_lahir, tanggal_lahir, golongan_darah, alamat, no_hp, pekerjaan, alergi, deleted_at. Appends: `umur` ("34 th"/"8 bln"). **Milik pusat, lintas cabang** |

### Transaksi (milik satu cabang — trait `DalamCabang`)
| Tabel | Kolom penting |
|-------|---------------|
| `kunjungans` | **cabang_id**, no_registrasi (unik), pasien_id, poli_id, dokter_id, tanggal, no_antrian, penjamin, no_penjamin, keluhan, **status**, dipanggil_at, selesai_at, created_by. Unik `(cabang_id, poli_id, tanggal, no_antrian)` |
| `pemeriksaans` | kunjungan_id (unik), tekanan_darah (`"120/80"`), nadi, suhu, respirasi, berat_badan, tinggi_badan, subjektif, objektif, asesmen, plan, perawat_id, dokter_id |
| `pemeriksaan_diagnosas` | pemeriksaan_id, icd10_id, jenis (`primer`/`sekunder`) |
| `kunjungan_tindakans` | kunjungan_id, tindakan_id, jumlah, **tarif (snapshot harga cabang kunjungan)**, keterangan |
| `reseps` | **cabang_id** (= cabang kunjungan), no_resep, kunjungan_id (unik), dokter_id, **status**, catatan, apoteker_id, diserahkan_at |
| `resep_items` | resep_id, obat_id, jumlah, aturan_pakai, **harga (snapshot)** |
| `tagihans` | **cabang_id** (= cabang kunjungan), no_tagihan, kunjungan_id (unik), total, diskon, grand_total, **status**, metode_bayar, dibayar, kembalian, kasir_id, dibayar_at |
| `tagihan_items` | tagihan_id, kategori (`konsultasi`/`tindakan`/`obat`), deskripsi, jumlah, harga, subtotal |
| `stok_mutasis` | obat_id, jenis, jumlah (**bertanda**: + masuk, − keluar), stok_akhir, referensi (mis. no_resep), keterangan, user_id |
| `berkas` | uuid (unik, route key), cabang_id (informasi, **tidak** di-scope), pasien_id, kunjungan_id, kategori, keterangan, nama_file, mime, ukuran (byte asli), path (tersembunyi), checksum SHA-256 (tersembunyi), diunggah_oleh, deleted_at |

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
| Icd10 | icd10s | Auditable |
| KategoriTindakan | kategori_tindakans | Auditable, SoftDeletes |
| Tindakan | tindakans | Auditable, SoftDeletes. Scope `denganHargaCabang($cabangId)` (+`tarif_cabang`, `tersedia`), `tersediaDi($cabangId)` |
| TindakanHarga | tindakan_hargas | Auditable (tercatat dengan `cabang_id` harganya) |
| TindakanBhp | tindakan_bhps | Auditable |
| Obat | obats | Auditable (kolom `stok` diabaikan — sudah di kartu stok), SoftDeletes |
| StokMutasi | stok_mutasis | — (ledger); kini punya `cabang_id` & `batch_id` |
| StokBatch | stok_batches | Auditable, **DalamCabang** — stok per cabang per batch (FEFO) |
| KunjunganTindakanBhp | kunjungan_tindakan_bhps | Auditable — pemakaian BHP aktual |
| Kunjungan | kunjungans | Auditable, **DalamCabang** |
| Pemeriksaan | pemeriksaans | Auditable |
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
  saat pemeriksaan masih terbuka dilakukan per model sehingga tercatat di audit log.
