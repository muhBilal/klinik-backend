# F1-06 — Foto Klinis Before-After

**PRD:** FT-01 (ambil foto dari tablet dengan template sudut standar), FT-02 (side-by-side & slider before-after lintas kunjungan),
FT-03 (terenkripsi, tidak masuk galeri perangkat, akses tercatat — fondasi F0-05), FT-04 (consent foto bertingkat, bisa dicabut),
RM-04 (foto klinis terstruktur terikat ke kunjungan) · **Fase:** 1 (roadmap #5) · **Status:** selesai (backend + frontend, diuji E2E
di browser dengan kamera palsu Chrome).

Belum: pemakaian foto untuk edukasi/marketing di luar aplikasi (ekspor/galeri marketing — menyusul bersama CRM), PP-03 foto di portal
pasien (Fase 3), DR-04 impor dari dermatoskop (Fase 3), penghapusan permanen foto atas permintaan pasien (UU PDP — perlu keputusan legal
karena bertabrakan dengan retensi RME 25 tahun).

## Keputusan desain

- **Foto = `berkas` kategori `foto_klinis` + metadata**, bukan tabel baru: enkripsi, tautan bertanda tangan, audit, soft delete dan
  akses terbatas (F1-05) sudah berlaku. Kolom baru: `protokol_foto_id`, `posisi` (kode posisi di protokol), `tahap`
  (`sebelum`/`sesudah`/`kontrol`), `kunjungan_tindakan_id`, `diambil_at`, `lebar`, `tinggi`, `thumbnail_path`.
- **Protokol foto = master yang bisa diubah klinik** (`protokol_fotos.posisi` = `[{kode, label, petunjuk}]`), karena posisi berbeda per
  spesialisasi (wajah depan/45°/profil, ekspresi untuk botox, intraoral gigi, tubuh). `kode` posisi adalah kunci pencocokan before-after
  antar kunjungan. 5 protokol dasar diisi migration (data referensi, ikut ke instalasi lama). Protokol bisa dipasang ke treatment
  (`tindakans.protokol_foto_id`) sehingga kamera langsung memakai protokol yang benar.
- **Kamera di dalam aplikasi** (`getUserMedia`) — foto tidak pernah menjadi berkas di galeri perangkat (FT-03). Fallback unggah berkas
  (`<input capture>`) bila kamera tak tersedia (desktop, atau halaman bukan HTTPS/localhost — produksi wajib HTTPS).
- **Gambar diproses ulang di browser**: sisi terpanjang maks. 2048 px (JPEG 0,9) dan **thumbnail 360 px** dibuat di kanvas. Efeknya
  metadata EXIF (GPS, model perangkat) terbuang, dan server tidak butuh GD/Imagick (image Docker tidak punya). Thumbnail dienkripsi
  seperti berkas utama.
- **Tautan pratinjau** = tautan bertanda tangan dengan parameter `t=1` yang **ikut ditandatangani** (tidak bisa diubah menjadi tautan
  foto penuh). Galeri meminta tautan untuk banyak foto sekaligus (`POST /berkas/tautan`); **setiap tautan tetap tercatat** audit
  `akses_berkas` (label `(pratinjau)`), sedangkan pengambilan thumbnail tidak mencatat `unduh_berkas` kedua kalinya.
- **Consent foto per pasien, bertingkat** (`klinis` ⊂ `edukasi` ⊂ `marketing`), bukan per kunjungan: sekali ditandatangani berlaku
  sampai diganti/dicabut. Paling banyak satu baris `berlaku`; tanda tangan baru membuat yang lama `diganti`; pencabutan = `dicabut`.
  Tidak pernah dihapus. Naskah dari pengaturan `foto.naskah_consent` di-render server & di-snapshot, tanda tangan terenkripsi, checksum.
- **Wajib consent sebelum unggah foto klinis** (pengaturan `foto.wajib_consent`, default aktif). Lampiran non-foto tidak butuh consent.
  Pencabutan tidak menghapus foto lama (sudah bagian RME) — hanya menghentikan foto baru dan pemakaian di luar klinis.

## Skema

Migration `2026_10_01_100001_create_foto_klinis_tables` (diuji migrate → rollback → migrate di PostgreSQL 17 dengan data demo).

| Tabel | Kolom |
|-------|-------|
| `protokol_fotos` | nama, deskripsi, posisi (JSON `[{kode, label, petunjuk}]`), is_active, deleted_at |
| `tindakans` (+) | **protokol_foto_id** |
| `berkas` (+) | **protokol_foto_id**, **posisi**, **tahap**, **kunjungan_tindakan_id**, **diambil_at**, **lebar**, **tinggi**, **thumbnail_path** (tersembunyi) |
| `persetujuan_fotos` | uuid, pasien_id, cabang_id, kunjungan_id, tingkat, isi (snapshot), status (`berlaku`/`diganti`/`dicabut`), penandatangan_nama, hubungan, ttd (**terenkripsi**), dibuat_oleh, ditandatangani_at, berakhir_at, dicabut_oleh, alasan_cabut, checksum, ip_address |

Model baru `ProtokolFoto`, `PersetujuanFoto` (ttd/checksum `#[Hidden]`, tidak disalin ke audit). `Pasien::persetujuanFotoAktif()`.
Enum baru `TingkatPersetujuanFoto` (label, keterangan, `mencakup()`), `StatusPersetujuanFoto`, `TahapFoto`.
Service `PersetujuanFotoService` (naskah, simpan, cabut, `pastikanBolehFoto`); `BerkasService` kini menyimpan & membuka thumbnail.

## Aturan

- Unggah foto: `berkas.kelola`. `posisi` wajib bila `protokol_foto_id` diisi dan harus salah satu kode posisi protokol itu.
  `kunjungan_tindakan_id` harus tindakan di kunjungan yang dikirim. Thumbnail opsional (JPEG ≤ 1 MB). Metadata foto diabaikan untuk
  kategori selain `foto_klinis`. `diambil_at` default saat unggah.
- Consent foto: lihat status `pasien.lihat`; tanda tangan & cabut `pasien.kelola` (front office) atau `rme.tindakan`; naskah + tanda
  tangan (`GET /persetujuan-fotos/{uuid}`) `pasien.kelola` / `rme.lihat`, tercatat audit `lihat`.
- Tanda tangan divalidasi sama seperti informed consent (PNG data URL 100 B – 300 KB).
- Protokol yang dipasang ke treatment tidak bisa dihapus (nonaktifkan). Kode posisi dibentuk dari label bila kosong, unik per protokol.
- Galeri & tautan menghormati akses terbatas (F1-05): foto kunjungan terbatas tidak terdaftar/dilewati untuk pengguna di luar tim.

## API

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/protokol-fotos` | login | **array**; `aktif=1` untuk pilihan; tanpa filter + `tindakans_count` |
| POST / PUT / DELETE | `/protokol-fotos`, `/{id}` | master.kelola | `{ nama*, deskripsi, posisi*[]{kode?, label*, petunjuk?} (1–20), is_active }` |
| POST | `/berkas` | berkas.kelola | + `thumbnail`, `protokol_foto_id`, `posisi`, `tahap`, `kunjungan_tindakan_id`, `diambil_at`, `lebar`, `tinggi`. 422 `consent_foto` bila belum ada persetujuan foto |
| GET | `/berkas` | rme.lihat | + filter `protokol_foto_id`, `posisi`, `tahap`; item + `protokol{id,nama,posisi}`, `kunjungan{id,tanggal,no_registrasi,poli}`, `ada_thumbnail` |
| GET | `/berkas/{uuid}/tautan` | rme.lihat | `?pratinjau=1` = thumbnail |
| POST | `/berkas/tautan` | rme.lihat | `{ uuids*[] (≤60), pratinjau? }` → `[{ uuid, url, kedaluwarsa }]` (berkas terbatas dilewati) |
| GET | `/pasiens/{id}/persetujuan-foto` | pasien.lihat | `{ aktif, riwayat[], tingkat[{value,label,keterangan}] }` |
| GET | `/pasiens/{id}/persetujuan-foto/pratinjau` | pasien.kelola, rme.tindakan | `?tingkat=` → `{ isi }` |
| POST | `/pasiens/{id}/persetujuan-foto` | pasien.kelola, rme.tindakan | `{ tingkat*, penandatangan_nama*, hubungan*, ttd*, kunjungan_id? }` → 201 |
| GET | `/persetujuan-fotos/{uuid}` | pasien.kelola, rme.lihat | + `isi`, `ttd`, `pasien`, `cabang`, `checksum_valid` |
| POST | `/persetujuan-fotos/{uuid}/cabut` | pasien.kelola, rme.tindakan | `{ alasan* }` |

Treatment: payload & detail + `protokol_foto_id` / `protokol_foto`. Pengaturan baru `foto.wajib_consent` (default `true`) dan
`foto.naskah_consent` (placeholder `{nama_pasien}` `{no_rm}` `{klinik}` `{tanggal}` `{tingkat}` `{pilihan}`).

## Frontend

Detail: `frontend/AI-Context/08-fitur-fase-1.md` bagian F1-06. Komponen `components/foto/*`: `FotoKlinisCard` (kartu gabungan),
`PersetujuanFotoPanel`, `KameraFoto` (kamera terpandu per protokol & tahap), `GaleriFoto` (thumbnail per kunjungan, filter,
"⇆ awal", pilih 2), `BandingFoto` (slider & berdampingan); `lib/foto.js` (proses gambar, unggah, tautan massal). Dipasang di
Pemeriksaan (+ tombol **Foto** per tindakan), Detail Kunjungan, Detail Pasien. Master **Protokol Foto** di modul rail Rekam Medis.

## Data demo

5 protokol (Wajah standar, Wajah dinamis (injeksi), Lesi / area close-up, Gigi intraoral, Tubuh). Treatment: TRT-001 → wajah dinamis;
TRT-002/011/012/021/022 → wajah standar; TND-101..103 → gigi intraoral.

## Test

`tests/Feature/FotoKlinisTest.php` (4 test): protokol bawaan & kelola (kode otomatis, duplikat, protokol terpakai), persetujuan bertingkat
(pratinjau, ganti, terenkripsi, audit, cabut, izin), foto butuh persetujuan + metadata + validasi posisi/tindakan + thumbnail terenkripsi +
filter + cabut + pengaturan, tautan massal pratinjau (isi thumbnail, `t` ditandatangani, audit, foto penuh, akses terbatas). `BerkasTest`
mematikan `foto.wajib_consent` (fokus enkripsi); `RmeEstetikaTest` & `FotoKlinisTest` memakai trait `tests/Concerns/BuatBerkasUji`.

E2E browser (Chrome headless `--use-fake-device-for-media-stream`): tanda tangan persetujuan foto → kamera dari tindakan botox (protokol
wajah dinamis, 4 posisi) → 1 foto tahap sesudah → galeri → bandingkan slider & berdampingan → detail pasien → master protokol.
E2E menemukan & memperbaiki dua bug: status posisi tidak dipisah per tahap, dan race saat upload masih berjalan (hasil upload lama
menandai posisi yang salah & menghapus pratinjau baru) — konteks kini dikunci sebelum upload dan pilihan tahap/posisi dinonaktifkan.
