# V2-05 — Profil Klinis Pasien, Consent UU PDP & Deteksi Pasien Ganda

**PRD v2:** PS-03 (alergi, riwayat obat, Fitzpatrick, hamil/menyusui sebagai peringatan), PS-04 (consent pemrosesan data & opt-in
marketing terpisah, UU 27/2022), PS-02 (deteksi duplikat), FR-02 sebagian (peringatan alergi saat meresepkan) · **Status:** selesai.
Belum: penggabungan pasien ganda (PS-08, Fase 2), interaksi obat (FR-02 sisa), hak subjek data lain (AD-08).

## Keputusan desain

- **Profil klinis terpisah dari identitas** (`profil_klinis`, 1:1 pasien) agar tidak ikut terkirim ke peran non-klinis yang hanya
  memegang `pasien.lihat` (kasir, CS). Baca `rme.lihat` (tercatat audit `lihat` `profil_klinis`), ubah `pemeriksaan.vital` / `.dokter`.
- **Alergi terstruktur** (`pasien_alergis`): jenis (obat/makanan/lingkungan/lainnya), zat, `obat_id` opsional (penghubung ke master obat
  untuk peringatan resep), reaksi, keparahan (ringan/sedang/berat). Replace-all per model, hapus = soft delete. Kolom lama
  `pasiens.alergi` (teks bebas) tetap ada & tampil sebagai peringatan sampai alergi terstruktur diisi.
- **Peringatan** dihitung server (`ProfilKlinisService::peringatan`): `bahaya` (alergi berat, hamil/menyusui + usia kehamilan dari HPHT),
  `waspada` (alergi lain/catatan lama), `info` (Fitzpatrick ≥ IV → risiko PIH pada laser/peeling). Status hamil hanya untuk perempuan.
- **Resep obat alergi** (FR-02 sebagian): `PUT /kunjungans/{id}/pemeriksaan` menolak obat yang **baru** ditambahkan ke resep bila
  `obat_id`-nya tercatat sebagai alergi (422 `resep.{i}.obat_id` + `konfirmasi_alergi`), kecuali `abaikan_alergi: true`. Obat yang sudah
  ada di resep sebelumnya tidak ditanyakan ulang.
- **Consent PDP** (`persetujuan_datas`) memakai pola consent foto: naskah dari pengaturan di-render server & di-snapshot, tanda tangan
  terenkripsi, checksum, satu `berlaku` per jenis, baru → lama `diganti`, cabut → `dicabut`, tidak pernah dihapus. **Pemrosesan** tidak bisa
  "ditolak" lewat sistem (422 `setuju`); **marketing** boleh setuju/menolak eksplisit. `Pasien::scopeOptInMarketing` = dasar broadcast (CR-03).
- **Wajib consent** (`pdp.wajib_consent`, default tidak): `POST /kunjungans` & buat booking → 422 `pasien_id` + `consent_data` bila belum ada
  persetujuan pemrosesan yang berlaku.
- **Duplikat** (`GET /pasiens-duplikat`): NIK sama, **HP sama** (9 digit terakhir kolom baru `pasiens.no_hp_digit` — digit saja, `62`→`0`,
  diisi model saat simpan, migration mengisi data lama, disembunyikan dari JSON & audit), atau **tanggal lahir sama + nama mengandung kata
  pertama**. Maks. 5 kandidat + `alasan[]`. `kecuali_id` saat ubah data.

## API

```
GET  /api/pasiens/{id}/profil-klinis                rme.lihat → { profil, alergis[], peringatan[] }
PUT  /api/pasiens/{id}/profil-klinis                pemeriksaan.vital / .dokter: fitzpatrick?, status_kehamilan?, hpht?, riwayat_obat?,
                                                    riwayat_penyakit?, alergis[]{id?, jenis, zat, obat_id?, reaksi?, keparahan, catatan?}
GET  /api/pasiens/{id}/persetujuan-data             pasien.lihat → { pemrosesan, marketing, riwayat[], wajib }
GET  /api/pasiens/{id}/persetujuan-data/pratinjau   pasien.kelola / rme.tindakan: jenis, setuju → { isi }
POST /api/pasiens/{id}/persetujuan-data             jenis, setuju, penandatangan_nama, hubungan, ttd
POST /api/persetujuan-datas/{uuid}/cabut            alasan
GET  /api/persetujuan-datas/{uuid}                  pasien.kelola / rme.lihat (naskah + ttd, tercatat audit, checksum_valid)
GET  /api/pasiens-duplikat                          pasien.lihat: nama?, tanggal_lahir?, no_hp?, nik?, kecuali_id?
GET  /api/pasiens/{id}                              + persetujuan_data { pemrosesan: bool|null, marketing: bool|null }
```

Pengaturan baru: `pdp.wajib_consent`, `pdp.naskah_pemrosesan`, `pdp.naskah_marketing` (placeholder `{nama_pasien} {no_rm} {klinik}
{tanggal} {keputusan}`).

## Frontend

- `components/pasien/ProfilKlinisCard.vue` — kartu di detail pasien (rme.lihat) & mode `ringkas` (baris peringatan) di header pemeriksaan;
  editor Fitzpatrick, kehamilan + HPHT, riwayat, alergi (hubungkan ke master obat). Expose `obatAlergi` (Map) → konfirmasi saat dokter
  menambah obat alergi; 422 `konfirmasi_alergi` juga dikonfirmasi lalu disimpan ulang dengan `abaikan_alergi`.
- `components/pasien/PersetujuanDataPanel.vue` — status & tanda tangan consent pemrosesan / marketing, cabut, riwayat, lihat/cetak; di
  kartu identitas pasien dan form pendaftaran (setelah pasien dipilih).
- `PasienFormModal` — kandidat pasien ganda (debounce 500 ms); pasien baru: "Gunakan pasien ini"; simpan tetap diminta konfirmasi.
- Pengaturan → kartu **Persetujuan Data Pribadi (UU PDP)**.

## Test

`tests/Feature/ProfilKlinisPdpTest.php` — profil & peringatan, replace-all alergi, pemisahan data klinis, hamil hanya perempuan;
resep obat alergi butuh konfirmasi & tidak ditanya ulang; consent pemrosesan vs marketing, opt-in scope, diganti, audit, checksum, cabut,
izin kasir; wajib consent menahan pendaftaran & booking; deteksi duplikat NIK / HP beda format / nama + tanggal lahir.
