# V2-07 — Resep Racikan, STR/SIP, Nomor BPOM, Impor Master Data & Kop Dokumen

**PRD v2:** FR-01 (racikan), AD-05 (STR & SIP + peringatan), AD-06 (nomor notifikasi BPOM), AD-10 (impor master resmi), AD-04 (template/kop
dokumen) · **Status:** selesai. Belum: resume medis & surat (RM-08, Fase 2), interaksi obat (FR-02 sisa).

## Resep racikan (FR-01)

- `resep_items.racikan = true`, `obat_id` null, `nama_racikan`, `bentuk` (krim/salep/gel/losion/kapsul/puyer/sirup/lainnya),
  `jumlah_racikan` + `satuan_racikan` (isi, untuk etiket), `jumlah` = **banyaknya racikan**, `biaya_racik` (snapshot).
- `resep_item_komponens`: obat + jumlah **per satu racikan** (satuan stok; desimal hanya untuk obat fraksional) + harga satuan snapshot.
- **Harga satu racikan** = Σ ceil(jumlah komponen × harga) + `farmasi.biaya_racik` (pengaturan, default 0). Tagihan: baris
  "Racikan X (krim 30 g)" × banyaknya racikan (`ResepItem::label()`).
- **Penyerahan** memotong stok tiap komponen × banyaknya racikan (FEFO), dicek bersama obat jadi; kartu stok berketerangan "Racikan X".
- Komponen ikut **pemeriksaan alergi** (V2-05): 422 `resep.{i}.komponen.{k}.obat_id` + `konfirmasi_alergi`.
- Payload `PUT /kunjungans/{id}/pemeriksaan` → `resep[]`: obat jadi `{obat_id, jumlah, aturan_pakai}`; racikan `{racikan: true,
  nama_racikan, bentuk, jumlah_racikan?, satuan_racikan?, jumlah, aturan_pakai, komponen[]{obat_id, jumlah}}`.

## STR & SIP (AD-05)

- `users.str`, `users.str_berlaku_sampai` (null = seumur hidup, sesuai UU 17/2023). SIP sudah ada (`sip`, `sip_berlaku_sampai`).
- Dashboard `izin_praktik[]`: SIP/STR berakhir ≤ `regulasi.peringatan_izin_hari` hari (default 60) atau sudah lewat, dan dokter tanpa SIP.
  Pemegang `pengguna.kelola` melihat semua petugas medis; petugas lain hanya miliknya.
- **Booking** dokter yang SIP-nya kosong atau berakhir sebelum tanggal booking → 422 `petugas_id`. (Tanda tangan RME sudah mensyaratkan
  SIP aktif sejak F1-05.)

## Jenis produk & BPOM (AD-06)

- `obats.jenis` (obat/skincare/bhp/alkes, default obat), `obats.no_bpom`. Skincare/kosmetik **wajib** nomor notifikasi kosmetik
  `N[A-E]` + 11 digit (dinormalkan: tanpa spasi, huruf besar). Form obat kini juga mengatur `fraksional` & `jam_pakai_setelah_buka`.

## Impor master (AD-10)

- `POST /api/impor-master/{icd10|icd9cm|obat}` (multipart `berkas`, izin `master.kelola`) dan `php artisan eklinik:impor {jenis} {berkas}`.
- CSV `;` atau `,` (BOM & baris judul `kode;...` dilewati): icd10 `kode;nama[;sensitif]`, icd9cm `kode;nama`,
  obat `kode;nama;satuan;harga[;jenis;no_bpom;stok_minimum]`.
- Idempoten per model (ter-audit): kode ada → diperbarui bila berubah, kode baru → ditambah, **tidak ada yang dihapus**; stok obat tidak
  pernah diubah lewat impor. Respons `{baru, diperbarui, sama, galat[]{baris, pesan}}` (galat maks. 50, baris lain tetap diproses).

## Kop dokumen (AD-04)

- Pengaturan publik `dokumen.kop_tambahan` (mis. nomor izin klinik), `dokumen.penanggung_jawab`, `dokumen.kaki`.
- Frontend `components/KopDokumen.vue` & `KakiDokumen.vue` dipakai cetakan informed consent, persetujuan foto, persetujuan data, dan rencana
  perawatan gigi.

## Frontend

`components/rme/RacikanModal.vue` (tombol **+ Racikan** di resep pemeriksaan; estimasi harga komponen), daftar resep & etiket farmasi
memakai `labelResepItem` / `jumlahResepItem` (`lib/format.js`) dan menampilkan komponen + kecukupan stok; `ImporMasterButton` di ICD-10,
ICD-9-CM, Obat & Stok (slot `#aksi` baru di `MasterCrud`); form obat (jenis, BPOM, fraksional); form pengguna (STR); banner izin praktik
di dashboard; kartu Pengaturan "Dokumen, Farmasi & Izin Praktik".

## Test

`tests/Feature/RegulasiRacikanTest.php` — racikan: validasi, harga, tagihan, potong stok komponen; alergi komponen; peringatan SIP/STR &
booking ditolak; BPOM wajib & format; impor idempoten + galat per baris + artisan + izin; kop dokumen publik.
