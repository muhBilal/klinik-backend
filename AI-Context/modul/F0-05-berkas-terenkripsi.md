# F0-05 — Berkas Klinis Terenkripsi

**PRD:** FT-03 (simpan terenkripsi di server, tidak masuk galeri perangkat, akses tercatat), 7.2 Enkripsi (URL bertanda tangan
yang kedaluwarsa), fondasi FT-01/FT-02 (foto before-after), RM-03 (informed consent), DG-04 (radiografi) · **Fase:** 0 ·
**Status:** fondasi selesai. Template sudut foto, tampilan before-after, dan consent foto bertingkat dikerjakan di Fase 1.

## Alur

```
Unggah (POST /berkas, multipart)
  → BerkasService::simpan: baca isi → SHA-256 → Crypt::encryptString (AES-256-CBC + MAC, kunci APP_KEY)
  → tulis disk `berkas` : {Y}/{m}/{uuid}.enc  → baris `berkas` (metadata)
Lihat (GET /berkas/{uuid}/tautan)            → audit `akses_berkas` → signed URL berlaku BERKAS_TAUTAN_MENIT (5)
Buka  (GET /berkas/{uuid}/unduh?expires&u&signature, tanpa token)
  → middleware `signed` → user `u` masih aktif → dekripsi → cek checksum → audit `unduh_berkas`
  → respons inline, Cache-Control: private, no-store; X-Content-Type-Options: nosniff
```

- Tautan memuat id pengguna penerbit (`u`) sehingga unduhan tercatat atas nama orang yang meminta tautan.
- Tautan diminta hanya saat pengguna menekan "Lihat" agar jejak audit bermakna (bukan untuk setiap thumbnail).
- Checksum tidak cocok (file di disk rusak/diubah) → exception, berkas tidak dikirim.

## Penyimpanan

| Lingkungan | Lokasi |
|------------|--------|
| Default / dev | `storage/app/berkas` (di-ignore git oleh `storage/app/.gitignore`) — disk `berkas` di `config/filesystems.php` (`BERKAS_ROOT`) |
| Docker stack lengkap | volume `berkas` → `/var/www/html/storage/app/berkas` (entrypoint meng-`chown` ke www-data) |

**Cadangkan volume `berkas` dan `APP_KEY` bersama-sama.** Rotasi kunci: set `APP_KEY` baru + `APP_PREVIOUS_KEYS` lama
(Laravel mendekripsi dengan kunci lama); berkas lama tetap terenkripsi kunci lama sampai dienkripsi ulang (belum ada perintahnya).

## Model `Berkas`

`uuid` (route key), `cabang_id` (informasi, **tidak** di-scope cabang — riwayat lintas cabang), `pasien_id`, `kunjungan_id`,
`kategori` (`App\Enums\KategoriBerkas`: foto_klinis, informed_consent, radiologi, hasil_penunjang, lainnya), `keterangan`,
`nama_file`, `mime` (dideteksi dari isi, bukan nama file), `ukuran`, `path`/`checksum` (tersembunyi), `diunggah_oleh`, soft delete.

## Aturan

- Jenis: jpg, jpeg, png, webp, pdf (`config('eklinik.berkas.mimes')`), maks. `BERKAS_MAKS_KB` (10240). Nginx/PHP image: 20 MB.
- `kunjungan_id` opsional; bila diisi harus kunjungan cabang aktif, milik pasien yang sama, tidak batal.
- Izin: daftar & tautan `rme.lihat`; unggah & hapus `berkas.kelola` (perawat, dokter, terapis bawaan).
- Hapus = soft delete; file terenkripsi **tidak** dihapus dari disk (retensi RME). Tautan untuk berkas terhapus → 404.
- Berkas milik kunjungan **berakses terbatas** (IMS) tidak ikut `GET /berkas` dan tautannya 403 bagi pengguna di luar tim yang
  menangani ([F1-05](F1-05-rme-estetika.md)).
- Informed consent digital (tanda tangan di tablet) disimpan di tabel `informed_consents`, bukan sebagai berkas; kategori berkas
  `informed_consent` tetap untuk pindaian consent kertas.

## Frontend

`components/LampiranBerkas.vue` (props `pasienId`, `kunjunganId?`, `readonly`):
- Dipakai di `PemeriksaanView` (lampiran kunjungan, bisa unggah), `KunjunganDetail` (read-only), `PasienDetail` (semua lampiran pasien).
- Unggah: file + kategori + keterangan (FormData). Daftar: kategori, nama, ukuran, pengunggah, waktu.
- "Lihat": gambar dipratinjau di modal (`draggable=false`, klik kanan dicegah), PDF dibuka di tab baru (jendela dibuka sebelum
  request agar tidak diblokir popup blocker).

## Belum dikerjakan

- FT-01 template sudut standar, FT-02 perbandingan before-after, FT-04 consent foto bertingkat → Fase 1 (modul Foto Klinis).
- Kompresi/thumbnail, pemindaian malware, penyimpanan S3/MinIO terenkripsi.
- Perintah enkripsi ulang setelah rotasi `APP_KEY`.

## Test

`tests/Feature/BerkasTest.php` — isi di disk terenkripsi & dapat didekripsi, tautan valid/diubah/kedaluwarsa, header no-store,
audit akses & unduh, hak akses & validasi (jenis file, kategori, kunjungan pasien lain), soft delete.
