# F1-07 — Odontogram, Rencana Perawatan & Tindakan per Gigi

**PRD:** DG-01 (odontogram interaktif notasi FDI, status per gigi & per permukaan M/O/D/B/L), DG-02 (treatment plan per gigi
dengan fase & estimasi biaya), DG-07 (tindakan per gigi otomatis masuk tagihan), bagian 6 (modul spesialisasi per poli) ·
**Fase:** 1 (roadmap #6) · **Status:** selesai (backend + frontend, diuji E2E di browser).

Belum: DG-03 charting periodontal (Fase 3), DG-04 viewer radiografi (Fase 2), DG-05 perawatan jangka panjang/cicilan (Fase 2),
DG-06 order lab gigi (Fase 2), garis jembatan antar-gigi di gambar (abutment/pontik dicatat per gigi), cetak odontogram.

## Keputusan desain

- **Odontogram milik pasien, dicatat per kunjungan.** Satu baris `odontogram_kondisis` = satu kondisi pada satu gigi (seluruh gigi)
  atau satu permukaan, dicatat di kunjungan K. Kondisi yang tidak berlaku lagi **diakhiri** (`berakhir_kunjungan_id`), tidak ditimpa,
  sehingga status pada kunjungan mana pun bisa disusun ulang (`scopeBerlakuPada`: dicatat ≤ K dan belum diakhiri sampai K; urutan
  kunjungan = urutan id).
- **Gigi sehat tidak dicatat.** Tanpa baris = sehat (sou). Tidak ada tabel "status 32 gigi".
- **Kode kondisi = enum `KondisiGigi`** (kode tiga huruf yang lazim di odontogram Indonesia: car, amf, cof, gif, fis, inl, onl, une, pre,
  imv, mis, rrx, ipx, fmc, poc, mpc, gmc, abu, pon, prd, fld, nvt, rct, cfr, att, abr, ano, dia, mig). Label, cakupan, kelompok & warna
  dikirim `GET /odontogram/referensi` — frontend tidak menduplikasi. **Daftar & warna wajib ditinjau drg. penanggung jawab klinik.**
- **Aturan penggantian** (`OdontogramService::konflik`):
  - satu permukaan hanya satu kondisi (karies → tambalan menggantikan);
  - kondisi seluruh gigi dalam kelompok eksklusif saling menggantikan: `keberadaan` (une/pre/imv/mis/rrx/ipx), `mahkota`
    (fmc/poc/mpc/gmc), `jembatan` (abu/pon), `protesa` (prd/fld), `pulpa` (nvt/rct); kelompok `lain` (cfr/att/abr/ano/dia/mig) bebas,
    hanya tidak duplikat;
  - `mengakhiri()`: mis → permukaan, mahkota, pulpa, lain; rrx → permukaan, mahkota; ipx & pon → permukaan, pulpa; mahkota → permukaan;
  - gigi berstatus `mis` hanya boleh dicatat pengganti gigi (kelompok keberadaan, jembatan, protesa) → selain itu 422 `gigi`.
- **Koreksi vs riwayat.** Kondisi dari kunjungan sebelumnya yang tergantikan → diakhiri (`berakhir_karena_id` = kondisi baru). Kondisi
  manual yang dicatat di kunjungan yang sama pada **tempat yang sama** (permukaan sama / kelompok eksklusif sama) → dianggap koreksi,
  **dihapus**. Yang tergeser lewat `mengakhiri()` di kunjungan yang sama → diakhiri saja. Menghapus kondisi baru memulihkan semua yang
  diakhirinya (`berakhir_karena_id`), sehingga "batalkan" selalu tepat.
- **Tindakan per gigi memperbarui odontogram** (`tindakans.kondisi_gigi_hasil`, mis. tambal komposit → `cof` pada permukaan yang ditambal,
  cabut → `mis`). Kondisi turunan punya `kunjungan_tindakan_id`; dibuat/diperbarui `sinkronDariTindakan` setiap pemeriksaan disimpan
  (hanya bila set berubah — tidak membuat ulang baris) dan sekali lagi sebelum RME ditandatangani. Turunan tidak bisa dihapus/ditimpa
  manual di kunjungan yang sama (422 `kondisi`: "ubah tindakannya"); tindakan dihapus → turunannya ikut terhapus.
- **Bagian dari RME kunjungan.** Hanya bisa diubah saat kunjungan `diperiksa`; setelah ditutup isi kondisi terkunci di model
  (`LogicException`, seperti `Pemeriksaan`) — kunjungan berikutnya hanya boleh mengisi kolom pengakhiran. Hash tanda tangan mencakup
  kondisi yang dicatat (gigi, permukaan, kode, keterangan, sumber) dan yang diakhiri di kunjungan itu; kolom pengakhiran kondisi yang
  dicatat **tidak** ikut hash (boleh diisi kunjungan berikutnya). Kunci `odontogram` & `[gigi, permukaan]` tindakan hanya ditambahkan
  bila ada, sehingga hash RME lama tetap cocok.
- **Modul spesialisasi per poli** (PRD bagian 6): `polis.spesialisasi` (`umum`/`gigi`/`kulit`/`estetika`/`lainnya`). Odontogram & rencana
  tampil di pemeriksaan poli `gigi`, atau bila kunjungan punya tindakan per gigi / kondisi gigi. Klinik tanpa poli gigi tidak melihatnya.
- **Rencana perawatan** milik pasien (dibaca lintas cabang), diubah di cabang penyusun. Fase = angka 1–9 (saran label di frontend).
  Estimasi = harga cabang saat item disusun (di-snapshot `tarif`); tagihan tetap memakai harga saat dikerjakan. Item dikerjakan lewat
  `kunjungan_tindakans.rencana_item_id` (satu arah; `pelaksanaan` = tindakan kunjungan terbaru) dan menjadi `selesai` saat kunjungan
  ditutup; rencana `selesai` otomatis bila tak ada item `rencana` tersisa.
- **Tagihan per gigi (DG-07)**: deskripsi item tindakan = `"{nama} — gigi 16 (MO)"` (`App\Support\Gigi::format`). Satu baris tindakan =
  satu gigi; tindakan yang sama pada gigi berbeda = baris berbeda (upsert pemeriksaan mencocokkan `tindakan_id` + `gigi`).

## Skema

Migration `2026_10_01_110001_create_odontogram_tables`. Diuji migrate → rollback → migrate di PostgreSQL 17 dengan data demo.

| Tabel | Kolom |
|-------|-------|
| `polis` (+) | **spesialisasi** (default `umum`) |
| `tindakans` (+) | **per_gigi** (bool), **kondisi_gigi_hasil** (kode `KondisiGigi`, nullable) |
| `odontogram_kondisis` | pasien_id, cabang_id, kunjungan_id (dicatat), kunjungan_tindakan_id (turunan, null on delete), gigi (FDI), permukaan (M/O/D/B/L, null = seluruh gigi), kondisi, keterangan, dicatat_oleh, berakhir_kunjungan_id, berakhir_at, berakhir_oleh, berakhir_karena_id. Index `(pasien_id, gigi)`, `berakhir_kunjungan_id` |
| `rencana_perawatans` | pasien_id, cabang_id, kunjungan_id, dokter_id, judul, catatan, status (`draf`/`disetujui`/`selesai`/`dibatalkan`), disetujui_at, disetujui_oleh, penyetuju_nama, selesai_at, dibatalkan_at/_oleh, alasan_batal, created_by |
| `rencana_perawatan_items` | rencana_perawatan_id (cascade), fase (1–9), urutan, gigi, permukaan (mis. `MO`), tindakan_id, jumlah, tarif (estimasi), keterangan, status (`rencana`/`selesai`/`batal`), selesai_at |
| `kunjungan_tindakans` (+) | **gigi**, **permukaan**, **rencana_item_id** (null on delete) |

Data lama (instalasi yang sudah berjalan): poli bernama/berkode gigi/dental → `gigi`, kulit/derma → `kulit`, estetik → `estetika`;
treatment ber-ICD-9-CM `23.xx` → `per_gigi`, kondisi hasil diisi bila jelas (23.0x/23.1x → mis, 23.2 + "komposit"/"amalgam"/"GIC" →
cof/amf/gif, 23.3 → inl, 23.5/23.6 → ipx, 23.70/23.71 → rct). Sisanya diatur admin di katalog.

Model baru: `OdontogramKondisi` (Auditable; scope `aktif`, `berlakuPada`; kunci model), `RencanaPerawatan`, `RencanaPerawatanItem`
(Auditable). Enum baru: `KondisiGigi`, `Spesialisasi`, `StatusRencanaPerawatan`, `StatusItemRencana`. Helper `App\Support\Gigi`
(validasi FDI 11–48 & 51–85, normalisasi permukaan ke urutan M-O-D-B-L, label permukaan per jenis gigi, format tagihan).
Relasi: `Kunjungan::odontogramDicatat/odontogramDiakhiri` (masuk `relasiRekamMedis` & `RELASI_RME` — ikut disembunyikan untuk akses
terbatas), `KunjunganTindakan::rencanaItem/kondisiGigi`, `Pasien::odontogramKondisis/rencanaPerawatans`.

## Aturan

### Odontogram (DG-01)
- Baca: `rme.lihat`. Kondisi dari kunjungan berakses terbatas yang tidak boleh dibaca user disaring (`kunjunganTersembunyi`).
  Setiap pembacaan tercatat audit `lihat` tipe `odontogram`.
- Ubah: `pemeriksaan.dokter` **atau** `rme.tindakan` (perawat/asisten boleh mencatat), kunjungan cabang aktif berstatus `diperiksa`
  (belum dipanggil / sudah ditutup → 422 `status`), dan `bolehLihat` RME kunjungan itu.
- Validasi: gigi FDI (11–18, 21–28, 31–38, 41–48, 51–55, 61–65, 71–75, 81–85); kondisi per permukaan wajib ≥ 1 permukaan, kondisi seluruh
  gigi tanpa permukaan (422 `permukaan`). Kondisi sama persis yang sudah berlaku tidak dicatat ulang.
- Hapus = hanya kondisi manual yang dicatat di kunjungan ini. Akhiri = kondisi dari kunjungan sebelumnya. Pulihkan = hanya pengakhiran
  manual di kunjungan ini dan tidak bentrok dengan kondisi yang berlaku (422 `kondisi`).

### Tindakan per gigi (DG-07)
- Treatment `per_gigi` wajib `tindakans[].gigi` (422 `tindakans.{i}.gigi`); treatment dengan kondisi hasil per permukaan wajib
  `permukaan` (422 `tindakans.{i}.permukaan`). Permukaan dinormalkan (`om` → `MO`). Gigi boleh juga diisi untuk treatment lain.
- Kondisi hasil pada gigi hilang ditolak (422 `tindakans.{i}.gigi`, seluruh penyimpanan dibatalkan).
- Admin mengisi kondisi hasil → treatment otomatis `per_gigi`.

### Rencana perawatan (DG-02)
- Susun/ubah/revisi/batal: `pemeriksaan.dokter`. Catat persetujuan pasien: `pemeriksaan.dokter` atau `rme.tindakan`. Baca: `rme.lihat`.
- Ubah item hanya saat `draf`. `disetujui` → 422 `status` (revisi dulu = kembali ke draf, persetujuan dihapus). Item `selesai` tidak bisa
  diubah/dihapus; item yang sedang dikerjakan di kunjungan terbuka tidak bisa dihapus dan rencana tidak bisa dibatalkan (422).
- Item baru/berganti treatment: treatment aktif & tersedia di cabang rencana; per gigi wajib nomor gigi (422 `items.{i}.gigi`).
- Dikerjakan: `tindakans[].rencana_item_id` → item milik pasien kunjungan, rencana `draf`/`disetujui`, item `rencana`, belum dipakai
  tindakan lain (422 `tindakans.{i}.rencana_item_id`). Gigi & permukaan diambil dari item bila tidak diisi.

## API

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/odontogram/referensi` | login | `{ kondisi: [{kode, label, cakupan, kelompok, warna}], permukaan }` |
| GET | `/pasiens/{id}/odontogram` | rme.lihat | `?kunjungan_id=` → status pada kunjungan itu. `{ kondisis, perubahan: {dicatat, diakhiri} \| null, kunjungan_id, bisa_diubah, kunjungans[] }` |
| POST | `/kunjungans/{id}/odontogram` | pemeriksaan.dokter, rme.tindakan | `{ gigi*, kondisi*, permukaan[], keterangan }` → 201 + data odontogram kunjungan |
| DELETE | `/kunjungans/{id}/odontogram/{kondisi}` | pemeriksaan.dokter, rme.tindakan | hapus koreksi (memulihkan yang digantikan) |
| POST | `/kunjungans/{id}/odontogram/{kondisi}/akhiri` | pemeriksaan.dokter, rme.tindakan | akhiri kondisi kunjungan sebelumnya |
| POST | `/kunjungans/{id}/odontogram/{kondisi}/pulihkan` | pemeriksaan.dokter, rme.tindakan | batalkan pengakhiran manual |
| GET | `/pasiens/{id}/rencana-perawatans` | rme.lihat | **array** terbaru dulu; `aktif=1`; + `estimasi_total`, `estimasi_selesai`, `estimasi_per_fase[]` |
| POST | `/pasiens/{id}/rencana-perawatans` | pemeriksaan.dokter | `{ judul*, catatan, kunjungan_id, dokter_id, items*[]{fase*, gigi, permukaan, tindakan_id*, jumlah, keterangan} }` → 201 |
| GET | `/rencana-perawatans/{id}` | rme.lihat | + pasien |
| PUT | `/rencana-perawatans/{id}` | pemeriksaan.dokter | sama dengan POST tanpa `kunjungan_id`; `items[].id` = upsert |
| POST | `/rencana-perawatans/{id}/setujui` | pemeriksaan.dokter, rme.tindakan | `{ penyetuju_nama? }` (default nama pasien) |
| POST | `/rencana-perawatans/{id}/revisi` | pemeriksaan.dokter | disetujui → draf |
| POST | `/rencana-perawatans/{id}/batal` | pemeriksaan.dokter | `{ alasan* }`; item tersisa → batal |

Perubahan endpoint lama:
- `PUT /kunjungans/{id}/pemeriksaan`: `tindakans[].gigi`, `.permukaan` (`^[MODBL]{1,5}$` tanpa pengulangan), `.rencana_item_id`.
- Detail kunjungan: `poli.spesialisasi`; `tindakans` + `gigi`, `permukaan`, `rencana_item_id`, `tindakan.per_gigi/kondisi_gigi_hasil`;
  `odontogram_dicatat`, `odontogram_diakhiri` (dilepas untuk akses terbatas).
- `GET /polis?aktif=1` + `spesialisasi`; payload poli + `spesialisasi`. `/tindakans` + `per_gigi`, `kondisi_gigi_hasil` (payload juga).
- `GET /pasiens/{id}`: `kunjungans[].poli.spesialisasi`; `data_gigi` (bool, punya odontogram/rencana) untuk pemegang `rme.lihat`.
- Tagihan dari kunjungan: deskripsi tindakan per gigi + `— gigi N (PERMUKAAN)`.

## Frontend

Detail: `frontend/AI-Context/08-fitur-fase-1.md` bagian F1-07. Ringkas: `components/gigi/*` (OdontogramChart SVG FDI, OdontogramCard,
PilihGigi, RencanaPerawatanCard + cetak estimasi, RencanaPerawatanModal), `lib/gigi.js`; PemeriksaanView (odontogram + rencana untuk
poli gigi, input gigi per tindakan, "+ Tindakan untuk gigi ini", "Kerjakan" dari rencana), KunjunganDetail, PasienDetail (status per
kunjungan), RekamMedisRingkas (gigi & perubahan odontogram), master Poli (spesialisasi) & Treatment (per gigi, kondisi hasil).

## Data demo

Poli GIGI = `gigi`, KULIT = `kulit`, ESTETIKA = `estetika`. Treatment gigi: TND-101 tambal komposit (cof), TND-102 cabut permanen (mis),
TND-103 scaling (bukan per gigi), **baru** TND-104 tambal GIC (gif), TND-105 PSA per kunjungan (rct), TND-106 mahkota porselen (poc),
TND-107 cabut sulung (mis), TND-108 fissure sealant (fis). Seeder hanya berjalan di database kosong.

## Test

`tests/Feature/OdontogramTest.php` (8 test): referensi + poli spesialisasi + katalog per gigi; validasi FDI/permukaan/status & hak akses
(perawat boleh, kasir tidak) + audit; aturan penggantian (koreksi di kunjungan sama, pengakhiran antar-kunjungan, mahkota, gigi hilang &
pengganti, hapus → pulihkan, akhiri/pulihkan & bentrok, status pada kunjungan lama); kunci kunjungan tertutup + hash; tindakan per gigi
→ odontogram (idempoten, ubah permukaan, hapus tindakan) & tagihan per gigi; tindakan pada gigi hilang ditolak; rencana berfase (estimasi
per fase, setujui/revisi, upsert item, kerjakan → selesai otomatis, kunci item); pembatalan & item pasien lain. `kunjunganGigi()`
memajukan hari (`travel`) karena pasien tidak boleh terdaftar dua kali di poli yang sama pada hari yang sama.

E2E browser (Chrome headless + playwright-core, stack dev terisolasi): drg. mencatat karies 16 MO, 26 hilang, 46 rct → "+ Tindakan untuk
gigi ini" tambal komposit 16 MO + PSA 36 lewat input baris → simpan (odontogram jadi cof/rct) → rencana 2 fase (scaling, crown 36) →
pasien setuju → kerjakan scaling → selesai & tanda tangani → tagihan per gigi, item rencana selesai, hash valid → detail kunjungan,
detail pasien (status per kunjungan), mobile 390 px tanpa scroll horizontal, master poli & treatment. Tanpa error konsol.
