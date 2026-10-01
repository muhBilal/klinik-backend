# F1-05 — RME Estetika

**PRD:** RM-01 (template SOAP per spesialisasi & treatment), RM-02 (ICD-9-CM + favorit dokter), RM-03 (informed consent digital,
tanda tangan di tablet), RM-05 (catatan tindakan: area, dosis, produk & batch, parameter alat), RM-07 (RME dikunci setelah
ditandatangani, koreksi via addendum), DR-03 (template kulit + kasus IMS berakses terbatas), ES-01 (face chart injeksi),
ES-02 (parameter laser/energy device), 7.1 UU 17/2023 (hanya dokter ber-SIP aktif menandatangani RME), sebagian AN-03
(petugas per tindakan) · **Fase:** 1 (roadmap #4) · **Status:** selesai (backend + frontend, diuji E2E di browser).

Belum: RM-04/FT-01..04 foto klinis terstruktur (roadmap #5), RM-06 treatment plan (Fase 2), RM-08 resume/surat PDF (Fase 2),
DR-01 body chart & DR-02 skor klinis (Fase 2), ES-05 adverse event (Fase 2), konversi dosis face chart → BHP otomatis.

## Keputusan desain

- **Selesai = tutup + tanda tangan.** `POST /kunjungans/{id}/selesai` kini juga menandatangani RME: `pemeriksaans.ditandatangani_at`,
  `ditandatangani_oleh`, `hash_ttd` (SHA-256 isi klinis). Tidak ada langkah "tanda tangan" terpisah agar alur dokter tetap satu klik.
- **Kunci di dua lapis.** Service menolak ubah pemeriksaan yang tidak terbuka (seperti sebelumnya), dan model `Pemeriksaan` menolak
  `update`/`delete` bila `ditandatangani_at` sudah terisi (`LogicException`) — jaring pengaman untuk kode baru.
- **Hash keutuhan** (`RekamMedisService::hash`) dihitung dari data tersimpan (vital, SOAP, kode diagnosa, tindakan + ICD-9-CM + petugas,
  catatan tindakan + titik face chart, uuid/status/checksum consent), kunci JSON diurutkan. `GET /kunjungans/{id}/verifikasi`
  membandingkan ulang → mendeteksi perubahan langsung di database. Addendum **tidak** masuk hash (memang ditambahkan setelahnya).
- **Tindakan kunjungan di-upsert, bukan replace-all.** `PUT /pemeriksaan` mencocokkan baris lama lewat `tindakans[].id`, atau —
  untuk klien tanpa `id` — `tindakan_id` yang sama. Baris yang cocok mempertahankan catatan tindakan, consent, dan koreksi BHP-nya;
  baris yang tidak dikirim dihapus per model (catatan & titik ikut terhapus, tercatat audit; consent tetap tersimpan, tautannya
  dilepas). Draft BHP dihitung ulang dari standar hanya bila `jumlah` berubah.
- **Informed consent di-snapshot.** Naskah di-render **di server** dari template (placeholder `{nama_pasien}`, `{tindakan}`, ...),
  disimpan utuh bersama tanda tangan (PNG data URL, cast `encrypted`) dan `checksum` (SHA-256 naskah + keputusan + penanda tangan +
  tanda tangan + waktu). Mengubah template tidak mengubah consent lama. Consent tidak pernah dihapus; penarikan = status `dicabut`
  (checksum tetap valid karena pencabutan dicatat di kolom terpisah).
- **Consent wajib per treatment.** Treatment dengan `template_consent_id` wajib punya consent `disetujui` sebelum pemeriksaan ditutup
  (pengaturan `rme.wajib_informed_consent`, default `true`). Consent `ditolak` → pesan "Pasien menolak X. Hapus tindakan ini".
- **Akses terbatas di level kunjungan** (`kunjungans.akses_terbatas`), bukan di pemeriksaan, agar mudah difilter di riwayat, detail
  pasien, dan berkas. Otomatis `true` bila ada diagnosa `icd10s.sensitif` (IMS/HIV) atau template `akses_terbatas`; tidak bisa dilepas
  selama diagnosa sensitif masih ada.
- **Face chart = koordinat relatif** `x, y` (0..1) pada diagram wajah tampak depan (`tampilan` disiapkan untuk kiri/kanan).
  Kanan/kiri mengikuti sisi **pasien**. Produk & batch per titik (ketertelusuran); batch divalidasi milik produk itu di cabang kunjungan.
- **Parameter alat = JSON dengan kunci tertutup** (`CatatanTindakan::PARAMETER` + aturan validasi); kunci lain dibuang. Alat merujuk
  `sumber_dayas` tipe `alat` di cabang kunjungan.
- **Bentuk catatan ditentukan katalog**: `tindakans.jenis_catatan` = `umum` / `injeksi` (face chart) / `energi` (parameter alat).
  Klinik gigi yang tidak memakai injeksi estetika tidak melihat face chart (PRD bagian 6).
- **ICD-9-CM dasar diisi migration** (62 kode kulit, estetika, gigi, umum) karena data referensi dibutuhkan instalasi baru maupun
  lama; penanda sensitif ICD-10 (A50–A64, B20–B24, Z21, R75) juga diterapkan migration ke kode yang sudah ada.

## Skema

Migration `2026_09_30_150001_create_rme_estetika_tables` (+ `150002_beri_izin_rme_ke_peran`). Diuji migrate → rollback → migrate
di PostgreSQL 17 dengan data demo.

| Tabel | Kolom |
|-------|-------|
| `icd9cms` | kode (unik), nama |
| `icd10s` (+) | **sensitif** (bool) |
| `template_consents` | nama, isi (teks + placeholder), is_active, deleted_at |
| `tindakans` (+) | **icd9cm_id**, **template_consent_id** (diisi = wajib consent), **jenis_catatan** (`umum`/`injeksi`/`energi`) |
| `template_soaps` | nama, poli_id (null = semua poli), tindakan_id, subjektif, objektif, asesmen, plan, **icd10_ids** (JSON, saran diagnosa), akses_terbatas, is_active, deleted_at |
| `kunjungans` (+) | **akses_terbatas** |
| `kunjungan_tindakans` (+) | **petugas_id** (pelaksana, dasar komisi), **icd9cm_id** |
| `pemeriksaans` (+) | **ditandatangani_at**, **ditandatangani_oleh**, **hash_ttd** |
| `pemeriksaan_addendums` | pemeriksaan_id, user_id, bagian, isi, alasan, created_at — append-only |
| `catatan_tindakans` | kunjungan_tindakan_id (unik), jenis, area, catatan, parameter (JSON), sumber_daya_id (alat), dicatat_oleh |
| `catatan_tindakan_titiks` | catatan_tindakan_id, tampilan, x, y `decimal(5,4)`, area, obat_id, batch_id, jumlah `decimal(10,3)`, satuan (U/ml/mg/mcg), kedalaman, alat (jarum/kanula), catatan |
| `informed_consents` | uuid (route key), cabang_id, kunjungan_id, pasien_id, kunjungan_tindakan_id (null on delete), template_consent_id, judul, tindakan_nama, isi, status (`disetujui`/`ditolak`/`dicabut`), penandatangan_nama, hubungan, ttd_penandatangan & ttd_saksi (**terenkripsi**), saksi_nama, dokter_id (pemberi penjelasan), dibuat_oleh, ditandatangani_at, dicabut_at/_oleh, alasan_cabut, checksum, ip_address |
| `kode_favorits` | user_id, jenis (`icd10`/`icd9cm`), kode_id. Unik `(user_id, jenis, kode_id)` |
| `users` (+) | **sip_berlaku_sampai** |

Model baru: `Icd9cm`, `KodeFavorit`, `TemplateSoap`, `TemplateConsent`, `CatatanTindakan`, `CatatanTindakanTitik`, `InformedConsent`
(tanda tangan & checksum `#[Hidden]`, tidak disalin ke audit log), `PemeriksaanAddendum` (menolak update/delete).
Enum baru: `JenisCatatanTindakan`, `StatusConsent`, `HubunganPenandatangan`, `BagianAddendum`.

## Aturan

### Tanda tangan RME (RM-07, UU 17/2023)
- Penutup pemeriksaan harus `sipAktif()`: `users.sip` terisi dan `sip_berlaku_sampai` kosong atau ≥ hari ini. Selain itu 422 `sip`
  — termasuk administrator (akses penuh) yang tidak punya SIP. `GET /me` mengirim `sip_aktif`.
- Setelah ditandatangani: `PUT /pemeriksaan` 422 `status`, model menolak ubah. Koreksi = `POST /kunjungans/{id}/addendum`
  `{ bagian*, isi*, alasan* }` oleh pemegang `pemeriksaan.dokter` ber-SIP aktif, kunjungan cabang aktif, dan boleh membaca RME itu.
  Sebelum ditandatangani addendum ditolak (422 `addendum`).

### Informed consent (RM-03)
- Ambil/cabut: izin `rme.tindakan`, kunjungan cabang aktif yang masih terbuka (`menunggu`/`diperiksa`).
- Satu consent `disetujui` per tindakan; consent kedua 422 `kunjungan_tindakan_id` (cabut dulu bila pasien berubah pikiran).
- Tanda tangan wajib data URL `image/png` base64, 100 B – 300 KB, magic bytes PNG. Saksi opsional (nama wajib bila ada tanda tangan saksi).
- Dokter pemberi penjelasan: `dokter_id` payload, dokter kunjungan, atau user login bila ia dokter.
- `GET /informed-consents/{uuid}` (rme.lihat): naskah + tanda tangan + `checksum_valid`; tercatat audit `lihat`; tunduk akses terbatas.
- Template consent yang masih dipasang ke treatment tidak bisa dihapus (nonaktifkan saja).

### Catatan tindakan (RM-05, ES-01, ES-02, AN-03)
- `PUT /kunjungan-tindakans/{id}/catatan` (rme.tindakan): perawat, dokter, terapis bawaan. Hanya selama pemeriksaan terbuka & cabang aktif.
- `jenis` default dari katalog; parameter & alat hanya disimpan untuk `energi`, titik hanya untuk `injeksi` (jenis lain → titik dikosongkan).
- `titiks` replace-all per model; batch harus milik `obat_id` titik itu di cabang kunjungan (422 `titiks.{i}.batch_id`).
- Petugas (`tindakans[].petugas_id` di pemeriksaan atau `petugas_id` di catatan) harus `User::petugasMedis()` — aktif, peran bukan akses
  penuh, memegang `pemeriksaan.dokter` / `pemeriksaan.vital` / `rme.tindakan` — dan bertugas di cabang kunjungan (atau lintas cabang).
  Default petugas tindakan baru = dokter yang mengisi, atau dokter kunjungan. Check-in booking menyalin petugas booking.

### Akses terbatas (DR-03)
- Boleh membaca isi RME kunjungan terbatas: pemegang `rme.terbatas`; tim tercatat (dokter kunjungan, dokter/perawat pemeriksaan,
  petugas tindakan); atau pemegang `pemeriksaan.vital`/`.dokter` di cabang kunjungan **selama pemeriksaan masih terbuka**.
- Selain itu: `GET /kunjungans/{id}` tanpa relasi RME + `rme_disembunyikan: true`; riwayat pasien & detail pasien melepas RME kunjungan
  itu; berkas kunjungan itu tidak terdaftar (`GET /berkas`) dan tautannya 403; verifikasi, catatan tindakan, detail consent 403.
- `rme.terbatas` tidak diberikan ke peran bawaan mana pun (hanya administrator); berikan lewat menu Peran & Izin bila perlu.

### Template SOAP & favorit (RM-01, RM-02)
- `GET /template-soaps?poli_id=` mengembalikan template poli itu + template umum (poli kosong), yang spesifik lebih dulu, lengkap dengan
  `diagnosas` (objek ICD-10 dari `icd10_ids`). Kelola: `master.kelola`.
- Favorit kode per dokter (`pemeriksaan.dokter`): `POST/DELETE /kode-favorits {jenis, kode_id}` (idempoten). `GET /icd10s` & `/icd9cms`
  mengirim `favorit` dan mengurutkan favorit paling atas; `?favorit=1` = favorit saja.
- ICD-10 baru dengan kode IMS/HIV otomatis `sensitif` bila admin tidak mengisi.

## API

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/icd9cms` | login | `q`, `favorit=1`; paginated; + `favorit` |
| POST / GET / PUT / DELETE | `/icd9cms`, `/icd9cms/{id}` | master.kelola | `{ kode* (^\d{2}(\.\d{1,2})?$), nama* }`; hapus ditolak bila dipakai tindakan kunjungan / treatment |
| POST / DELETE | `/kode-favorits` | pemeriksaan.dokter | `{ jenis*: icd10/icd9cm, kode_id* }` |
| GET | `/template-soaps` | login | **array**; `poli_id`, `aktif=1`, `status`, `q` |
| POST / PUT / DELETE | `/template-soaps`, `/{id}` | master.kelola | `{ nama*, poli_id, tindakan_id, subjektif, objektif, asesmen, plan, icd10_ids[] (≤10), akses_terbatas, is_active }` |
| GET | `/template-consents` | rme.tindakan, master.kelola | **array**; `aktif=1` → `{id, nama}`; lengkap + `tindakans_count` |
| POST / GET / PUT / DELETE | `/template-consents`, `/{id}` | master.kelola | `{ nama*, isi* (≤20.000), is_active }`; GET + `placeholder` |
| GET | `/kunjungans/{id}/informed-consents/pratinjau` | rme.tindakan | `template_consent_id*`, `kunjungan_tindakan_id?`, `dokter_id?` → `{ judul, tindakan_nama, isi, dokter }` |
| POST | `/kunjungans/{id}/informed-consents` | rme.tindakan | `{ template_consent_id*, kunjungan_tindakan_id?, keputusan*: setuju/tolak, penandatangan_nama*, hubungan*, ttd_penandatangan*, saksi_nama?, ttd_saksi?, dokter_id? }` → 201 |
| GET | `/informed-consents/{uuid}` | rme.lihat | + `isi`, `ttd_penandatangan`, `ttd_saksi`, pasien, kunjungan.cabang, dokter, pembuat, pencabut, `checksum_valid` |
| POST | `/informed-consents/{uuid}/cabut` | rme.tindakan | `{ alasan* }` |
| GET / PUT | `/kunjungan-tindakans/{id}/catatan` | rme.lihat / rme.tindakan | GET → `{ kunjungan_tindakan, jenis, catatan, terkunci }`; PUT lihat payload |
| POST | `/kunjungans/{id}/addendum` | pemeriksaan.dokter | `{ bagian*, isi*, alasan* }` → 201 + `user` |
| GET | `/kunjungans/{id}/verifikasi` | rme.lihat | `{ ditandatangani, valid, ditandatangani_at, penandatangan }` (lintas cabang) |
| GET | `/petugas` | login | **array** petugas medis cabang aktif + lintas cabang `{ id, name, role, poli_id, cabang_id, peran }` |

Perubahan endpoint lama:
- `PUT /kunjungans/{id}/pemeriksaan`: + `akses_terbatas`, `tindakans[].id`, `tindakans[].petugas_id`, `tindakans[].icd9cm_id`.
- `POST /kunjungans/{id}/selesai`: + syarat SIP aktif & consent lengkap; respons memuat tanda tangan.
- Detail kunjungan (`loadDetail` / `relasiRekamMedis`): `pemeriksaan` + `ditandatangani_at`, `penandatangan`, `addendums.user`;
  `tindakans` + `petugas`, `icd9cm`, `catatan` (+ `alat`, `titiks.obat`, `titiks.batch`), `tindakan.jenis_catatan/template_consent_id`;
  `informed_consents` (tanpa naskah & tanda tangan); `akses_terbatas`, `rme_disembunyikan`.
- `/tindakans` + `icd9cm_id`, `template_consent_id`, `jenis_catatan`, `icd9cm`; detail + `template_consent`. Payload treatment + tiga
  field itu. `/icd10s` + `sensitif`, `favorit`; payload + `sensitif`. `/users` payload + `sip_berlaku_sampai`.

Payload catatan tindakan:
```json
{
  "jenis": "injeksi", "area": "Dahi & glabella", "catatan": "...", "petugas_id": 9,
  "sumber_daya_id": null, "parameter": { "panjang_gelombang_nm": 1064, "fluence_j_cm2": 2.5, "jumlah_shot": 1500, "reaksi_kulit": "eritema_ringan" },
  "titiks": [{ "x": 0.5, "y": 0.2, "area": "Frontalis (dahi)", "obat_id": 21, "batch_id": 41, "jumlah": 4, "satuan": "U",
               "kedalaman": "Intramuskular", "alat": "Jarum 30G" }]
}
```

## Pengaturan & izin

- `rme.wajib_informed_consent` (bool, default `true`) — menu Pengaturan → Rekam Medis.
- Izin baru: `rme.tindakan` (perawat, dokter, terapis) dan `rme.terbatas` (tidak ada peran bawaan). Lihat [F0-01](F0-01-rbac-peran-izin.md).

## Frontend

Detail: `frontend/AI-Context/08-fitur-fase-1.md` bagian F1-05. Ringkas: `PemeriksaanView` (template, favorit, akses terbatas,
ICD-9-CM & petugas per tindakan, tombol Face chart/Parameter alat/Catatan, consent wajib, "Selesai & tanda tangani", addendum),
komponen `components/rme/*` (FaceChart, CatatanTindakanModal, ConsentFormModal, ConsentLihatModal + cetak, AddendumModal,
TemplateSoapModal), `SignaturePad`, `RekamMedisRingkas` (tanda tangan, catatan, consent, addendum, 🔒), `KunjunganDetail` (cek keutuhan,
addendum), modul rail baru **Rekam Medis** (ICD-10, ICD-9-CM, Template SOAP, Template Consent).

## Data demo

Poli baru `KULIT` (Poli Kulit & Kelamin) & `ESTETIKA`; 27 kode ICD-10 kulit/estetika/IMS (9 sensitif); 10 template SOAP (akne, melasma,
dermatitis, jamur, IMS terbatas, konsultasi estetika, botox, filler, laser, gigi); 5 naskah consent (injeksi, laser, peeling, gigi, umum)
dipasang ke TRT-001/002 (injeksi), TRT-011/012 (energi), TRT-022, TND-102, TND-006; kode ICD-9-CM default untuk hampir semua treatment.
**Naskah consent demo wajib ditinjau penanggung jawab medis/legal klinik sebelum dipakai.**

## Test

`tests/Feature/RmeEstetikaTest.php` (9 test): template per poli & treatment, ICD-9-CM & favorit pribadi, upsert tindakan menjaga catatan &
petugas, catatan laser & face chart (validasi batch), consent wajib/cabut/tolak/terenkripsi/audit, pengaturan consent dimatikan,
tanda tangan butuh SIP aktif + hash terdeteksi berubah + kunci model, addendum append-only, akses terbatas IMS (detail, riwayat, pasien,
berkas, verifikasi, izin rme.terbatas). `InventoriTest` & `KatalogTreatmentTest` mematikan kewajiban consent (fokusnya lain);
`BookingTest` check-in kini memeriksa petugas & ICD-9-CM tersalin.

E2E browser (Chrome headless + playwright-core di stack dev terisolasi): template → tindakan botox → face chart 4 titik (produk & batch
FEFO) → consent + tanda tangan → selesai & tanda tangani → cek keutuhan → addendum → cetak consent; dokter lain melihat kunjungan IMS
tanpa isi RME; halaman master & pengaturan.
