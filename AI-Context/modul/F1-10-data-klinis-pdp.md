# F1-10 — Data Klinis Pasien & Persetujuan Data Pribadi (UU PDP)

**PRD:** PS-03 (alergi, riwayat obat, tipe kulit Fitzpatrick, status hamil/menyusui tampil sebagai peringatan), PS-04 (consent pemrosesan
data dan opt-in marketing terpisah, UU PDP), bagian 3 (data klinis terpisah dari data komersial) · **Fase:** 1 (roadmap #11) ·
**Status:** selesai (backend + frontend, diuji E2E di browser).

Belum: hak subjek data lain (ekspor salinan data pasien, permintaan penghapusan/anonimisasi di luar kewajiban retensi RME), kanal opt-in
dipakai broadcast (CRM CR-03, Fase 2), "tidak ada alergi yang diketahui" sebagai pernyataan eksplisit (kini: belum ada catatan), kode
alergi terstandar untuk SATUSEHAT AllergyIntolerance (#12), snapshot peringatan klinis ke RME kunjungan, email pasien. **Naskah
persetujuan wajib ditinjau penasihat hukum klinik.**

## Keputusan desain

- **Data klinis dipisah dari identitas.** `pasiens` kini hanya identitas & kontak; kolom teks bebas `pasiens.alergi` dihapus (dikonversi).
  Profil klinis di `pasien_klinis` (1:1) dan alergi di `pasien_alergis` (1:n). Hanya dimuat untuk pemegang `rme.lihat` (detail kunjungan
  ber-RME, `GET /pasiens/{id}/klinis`), sehingga kasir/marketing/pendaftaran (`pasien.lihat`) tidak melihat data kesehatan. Pengecualian:
  **farmasi** menerima alergi & status hamil/menyusui lewat detail resep (keamanan obat), walau apoteker tidak memegang `rme.lihat`.
- **Siapa mengubah:** tenaga yang melakukan anamnesis — `pemeriksaan.vital` (perawat), `pemeriksaan.dokter`, atau `rme.tindakan`
  (terapis). Pendaftaran tidak lagi mengisi alergi (field dihapus dari form pasien). Data klinis milik pasien (bukan kunjungan), jadi
  tidak ikut terkunci saat RME ditandatangani; setiap perubahan & pembacaan tercatat audit dengan `pasien_id` (jejak akses pasien).
- **Alergi terstruktur:** kategori (`obat`/`makanan`/`lingkungan`/`lainnya` — padanan FHIR medication/food/environment), zat, reaksi,
  keparahan (`ringan`/`sedang`/`berat`). Alergi obat bisa **ditautkan ke master obat** → peringatan saat obat itu diresepkan / disiapkan
  farmasi. Pencocokan di frontend (`lib/klinis.js`): `obat_id` sama, atau nama obat memuat nama zat (alergi obat/lainnya). Peringatan
  tidak memblokir resep (keputusan klinis dokter). Replace-all per baris (dicocokkan `id`) sehingga audit hanya berisi perubahan nyata;
  zat ganda (tanpa beda huruf besar/spasi) 422.
- **Hamil/menyusui** (`tidak`/`hamil`/`menyusui`, null = belum ditanyakan) hanya untuk perempuan (422 untuk laki-laki), selalu disertai
  **tanggal dicatat** (`status_kehamilan_at`) karena statusnya berubah; tanggal diperbarui saat status berubah atau dikonfirmasi ulang
  (`konfirmasi_kehamilan`). Pengingat "belum ditanyakan" di UI hanya untuk perempuan usia 12–55 th. Fitzpatrick I–VI dengan keterangan; riwayat obat (mis. isotretinoin, antikoagulan) & riwayat penyakit (mis.
  keloid) teks bebas.
- **Persetujuan UU PDP = dua jenis terpisah** di `persetujuan_datas`: `pemrosesan` (data pribadi & kesehatan untuk pelayanan, rekam medis,
  penagihan, kewajiban hukum) dan `marketing` (opt-in promosi + kanal WhatsApp/SMS/Email/Telepon). Satu formulir bertanda tangan:
  pemrosesan wajib dicentang (`accepted`); marketing pilihan terpisah, **bawaan tidak bersedia**. Masing-masing paling banyak satu `berlaku`
  per pasien; formulir baru membuat yang lama `diganti`; formulir yang menyatakan tidak bersedia promosi **mencabut** opt-in yang berlaku.
  Pencabutan per jenis (hak subjek data); mencabut pemrosesan ikut mencabut marketing. Tidak pernah dihapus (bukti persetujuan).
- **Pola sama dengan persetujuan foto (F1-06):** naskah dari pengaturan di-render server & di-snapshot, tanda tangan PNG terenkripsi,
  checksum SHA-256, `ip_address`, route key `uuid`, lihat dokumen tercatat audit.
- **Wajib persetujuan bersifat opsional** (`pdp.wajib_persetujuan`, bawaan **mati**): bila aktif, `POST /kunjungans` dan check-in booking
  ditolak 422 `persetujuan_data` tanpa persetujuan pemrosesan yang berlaku. Bila mati, pasien tetap bisa didaftarkan tetapi ditandai di daftar
  pasien & form pendaftaran (dan bisa ditandatangani langsung di sana).
- Daftar & detail pasien menyertakan `pdp_pemrosesan` / `pdp_marketing` (boolean) — marketing dapat menyaring pasien yang opt-in tanpa
  melihat data klinis.

## Skema

Migration `2026_10_01_150001_create_data_klinis_dan_persetujuan_data_tables`. Diuji migrate → rollback → migrate di PostgreSQL 17, termasuk
konversi teks alergi lama.

| Tabel | Kolom |
|-------|-------|
| `pasien_klinis` | pasien_id (unik, cascade), fitzpatrick (I–VI), status_kehamilan, status_kehamilan_at (date), riwayat_obat, riwayat_penyakit, diperbarui_oleh |
| `pasien_alergis` | pasien_id (cascade), kategori, zat, obat_id (null on delete), reaksi, keparahan, dicatat_oleh |
| `persetujuan_datas` | uuid, pasien_id (restrict), cabang_id, jenis (`pemrosesan`/`marketing`), kanal (json), isi (snapshot), status (`berlaku`/`diganti`/`dicabut`), penandatangan_nama, hubungan, ttd (**terenkripsi**), dibuat_oleh, ditandatangani_at, berakhir_at, dicabut_oleh, alasan_cabut, checksum, ip_address; index (pasien_id, jenis, status) |
| `pasiens` (−) | **alergi** dihapus |

Konversi `pasiens.alergi`: dipecah per `,` `;` `/` baris baru; "-", "tidak ada" dilewati; nama yang menjadi awalan nama master obat →
alergi obat bertaut (mis. "Amoxicillin" → Amoxicillin 500 mg); kata makanan umum (seafood, udang, telur, susu, kacang, …) → makanan;
debu/lateks/serbuk/bulu/tungau/nikel → lingkungan; sisanya lainnya. Rollback: teks alergi disusun ulang dari nama zat (reaksi, keparahan,
data klinis & persetujuan data hilang).

Model baru: `PasienKlinis`, `PasienAlergi` (Auditable, `auditPasienId`), `PersetujuanData` (Auditable; ttd/isi/checksum tidak disalin ke
audit; `hitungChecksum()`, `checksumValid()`). Relasi `Pasien::klinis()`, `alergis()`, `persetujuanDatas()`. Enum baru: `KategoriAlergi`,
`KeparahanAlergi`, `TipeKulitFitzpatrick` (`keterangan()`), `StatusKehamilan`, `JenisPersetujuanData`, `StatusPersetujuanData`,
`KanalMarketing`. Service `DataKlinisService` (data, simpan, syncAlergi), `PersetujuanDataService` (aktif, naskah, simpan, cabut,
`pastikanBolehDaftar`).

## API

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/pasiens/{id}/klinis` | rme.lihat | `{ klinis: {fitzpatrick, status_kehamilan, status_kehamilan_at, riwayat_obat, riwayat_penyakit, updated_at, pembaru} \| null, alergis: [{id, kategori, zat, obat_id, obat, reaksi, keparahan}] }`; tercatat audit `lihat` tipe `pasien_klinis` |
| PUT | `/pasiens/{id}/klinis` | pemeriksaan.vital, pemeriksaan.dokter, rme.tindakan | kunci profil yang dikirim saja + `konfirmasi_kehamilan`; `alergis[]{id?, kategori*, zat*, obat_id, reaksi, keparahan}` replace-all. 422 `status_kehamilan` (laki-laki), `alergis.{i}.zat` (ganda), `alergis.{i}.id` (bukan milik pasien). Respons = bentuk GET |
| GET | `/pasiens/{id}/persetujuan-data` | pasien.lihat | `{ pemrosesan, marketing (berlaku atau null), riwayat[], kanal[{value,label}] }` tanpa naskah & tanda tangan |
| GET | `/pasiens/{id}/persetujuan-data/pratinjau` | pasien.kelola | `?kanal[]=` → `{ pemrosesan, marketing }` naskah |
| POST | `/pasiens/{id}/persetujuan-data` | pasien.kelola | `{ setuju_pemrosesan* (accepted), marketing* (bool), kanal (wajib bila marketing), penandatangan_nama*, hubungan*, ttd* }` → 201 array persetujuan baru |
| GET | `/persetujuan-datas/{uuid}` | pasien.kelola, rme.lihat | + `isi`, `ttd`, `pasien`, `cabang`, `checksum_valid`; tercatat audit |
| POST | `/persetujuan-datas/{uuid}/cabut` | pasien.kelola | `{ alasan* }`; pemrosesan ikut mencabut marketing; selain `berlaku` → 422 `status` |

Perubahan endpoint lama: `GET/POST/PUT /pasiens` tanpa `alergi`; daftar + `pdp_pemrosesan`, `pdp_marketing`, filter
`persetujuan=belum|ada|marketing`; detail (termasuk `?ringkas=1`) + dua boolean itu. Detail kunjungan ber-RME (`loadDetail`) +
`pasien.klinis`, `pasien.alergis[].obat`. Detail resep + `kunjungan.pasien.alergis`, `kunjungan.pasien.klinis{status_kehamilan,
status_kehamilan_at}`. `POST /kunjungans` & `POST /appointments/{id}/checkin` → 422 `persetujuan_data` bila diwajibkan.

## Pengaturan & data demo

`pdp.wajib_persetujuan` (bool, bawaan false), `pdp.naskah_pemrosesan`, `pdp.naskah_marketing` (placeholder {nama_pasien} {no_rm} {klinik}
{tanggal}; marketing + {kanal}) — Pengaturan → Data Pribadi (UU PDP). Seeder: Fitzpatrick untuk 6 pasien demo pertama, alergi Amoxicillin
(berat, bertaut OBT-002) & udang, satu pasien perempuan 20–45 th berstatus menyusui. Tidak ada persetujuan data bawaan.

## Frontend

Detail: `frontend/AI-Context/08-fitur-fase-1.md` bagian F1-10. Ringkas: komponen `klinis/PeringatanKlinis` (chip peringatan),
`klinis/DataKlinisModal` (ubah), `klinis/DataKlinisCard` (detail pasien), `pdp/PersetujuanDataPanel` (status, formulir bertanda tangan,
cabut, riwayat, lihat/cetak; mode `ringkas` di pendaftaran); peringatan klinis di pemeriksaan & farmasi; peringatan alergi saat obat
ditambahkan ke resep; daftar pasien + filter persetujuan; Pengaturan → Data Pribadi.

## Test

`tests/Feature/DataKlinisPdpTest.php` (4 test): akses terpisah (kasir/pendaftaran 403, perawat ubah, dokter baca + audit `lihat`), tautan obat
hanya untuk alergi obat, validasi (hamil laki-laki, alergi ganda, id alergi pasien lain, kategori), replace-all per id, tanggal status
kehamilan (berubah & konfirmasi), audit `pasien_alergi`/`pasien_klinis`, identitas tanpa data klinis; peringatan di detail kunjungan (dokter
ya, kasir tidak) & resep untuk apoteker; persetujuan (kasir lihat tidak tanda tangan, pratinjau kanal, `accepted`, kanal wajib, ttd valid, dua
baris terpisah, ttd terenkripsi, naskah snapshot, filter & boolean daftar, formulir tanpa marketing mencabut opt-in, dokumen + checksum +
audit, cabut pemrosesan ikut mencabut marketing); wajib persetujuan menolak pendaftaran lalu lolos setelah ditandatangani.

E2E browser (Chrome headless, stack dev terisolasi): pendaftaran memilih pasien → "Belum ada persetujuan pemrosesan data" → formulir
(centang, bersedia promosi WhatsApp + SMS, naskah marketing ikut berubah, tanda tangan) → chip "✓ Persetujuan data · Opt-in promosi" →
daftar kunjungan; pengaturan wajib aktif → pasien lain ditolak dengan pesan di form; daftar pasien tanpa alergi, chip opt-in; kasir melihat
status persetujuan tanpa kartu Data Klinis & tanpa tombol tanda tangan; dokter: peringatan (alergi Amoxicillin berat, menyusui, Fitzpatrick,
riwayat obat) → modal data klinis (tambah alergi udang, riwayat obat & penyakit) → chip diperbarui → menambah Amoxicillin ke resep memunculkan
peringatan toast & di baris resep; detail pasien: kartu Data Klinis & lihat dokumen persetujuan (checksum valid); apoteker: alergi, status
menyusui & peringatan item resep; mobile 390 px tanpa scroll horizontal (detail pasien, pemeriksaan, daftar pasien, modal data klinis,
pendaftaran — sekalian memperbaiki grid pendaftaran yang sebelumnya melebar 451 px). Tanpa error konsol selain 422 yang memang diuji.
