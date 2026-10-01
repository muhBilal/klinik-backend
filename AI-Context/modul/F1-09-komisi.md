# F1-09 — Komisi & Jasa Medis

**PRD:** KM-01 (komisi per treatment per peran: persen atau nominal, split dokter–terapis–asisten), KM-03 (rekap & slip per periode,
dikunci setelah disetujui), komisi di master treatment TR-01, sebagian AN-03 (asisten per tindakan) · **Fase:** 1 (roadmap #10) ·
**Status:** selesai (backend + frontend, diuji E2E di browser).

**Revisi 1 Okt 2026 (permintaan user):** komisi diatur **langsung di master treatment** dan **jasa konsultasi dokter dibuat sebagai
treatment**. Mesin aturan terpisah (halaman Aturan Komisi: per kategori/umum/khusus cabang) dihapus; data lama dikonversi oleh migration
`2026_10_01_140001_pindah_komisi_dan_konsultasi_ke_treatment`.

Belum: KM-02 komisi penjualan produk & paket untuk CS/beautician (Fase 2), komisi khusus per cabang, target/tiering komisi,
pembayaran/payroll (status "dibayar"), ekspor Excel (LP-06), komisi beberapa pelaksana pada satu peran (satu orang per peran per tindakan),
salin komisi massal ke banyak treatment.

## Keputusan desain

- **Peran per tindakan:** `dokter` = dokter kunjungan (`kunjungans.dokter_id`), `terapis` = pelaksana (`kunjungan_tindakans.petugas_id`, sudah
  ada sejak F1-05), `asisten` = kolom `kunjungan_tindakans.asisten_id`. Satu orang boleh menempati dua peran (dokter yang mengerjakan
  sendiri) dan menerima keduanya bila treatment mengaturnya — klinik cukup tidak mengisi komisi terapis untuk treatment itu.
- **Komisi per treatment** (`tindakan_komisis`, satu baris per treatment × peran): persen dari dasar atau nominal × jumlah tindakan.
  Diisi di form Katalog Treatment (bagian "Komisi & jasa medis"); peran yang dikosongkan = tanpa komisi (baris tidak disimpan).
  Replace-all bila `komisis` dikirim, tidak berubah bila tidak dikirim; perubahan tercatat audit (`tindakan_komisi`).
- **Hak akses:** komisi hanya terlihat (detail, `?komisi=1` di daftar) & bisa diubah oleh pemegang `komisi.kelola`; form treatment sendiri
  butuh `master.kelola`. Bawaan: hanya administrator yang memegang keduanya. Manajer (`komisi.kelola` saja) mengelola rekap, bukan
  komisi treatment — beri `master.kelola` lewat Peran & Izin bila manajer juga harus mengatur komisi.
- **Jasa konsultasi dokter = treatment** (kategori "Konsultasi", mis. "Konsultasi dokter estetika"). Master Poli memilih treatment itu
  (`polis.tindakan_konsultasi_id`, menggantikan `polis.tarif_konsultasi`) → ditagihkan otomatis tiap kunjungan poli sebagai item
  `konsultasi` yang menunjuk treatment-nya. Keuntungan: harga per cabang, ICD-9-CM (89.07), promo per treatment, dan komisi dokter ikut
  katalog. Tidak ditagihkan bila poli tanpa jasa konsultasi, treatment nonaktif/terhapus, atau ditandai "tidak dilayani" di cabang itu;
  **tidak ditagih dua kali** bila dokter sudah mencatat treatment itu sebagai tindakan (mis. konsultasi ×2). Treatment yang dipakai
  poli tidak bisa dihapus (422).
- **Sumber data = tagihan kunjungan yang lunas** pada rentang `dibayar_at` periode (cabang periode). Tagihan direfund (batal) tidak dihitung.
  Satu kunjungan dihitung sekali. Konsultasi dari item tagihan `konsultasi` (komisi dokter treatment-nya); tindakan dari `kunjungan_tindakans`.
- **Dasar** (pengaturan `komisi.dasar`, default `neto`): `neto` = harga × jumlah × (total − diskon − promo) / total tagihan (proporsional);
  `bruto` = harga × jumlah. Sesi paket (ditagih Rp 0) memakai **nilai per sesi paket** (F1-08) di kedua mode.
- **Periode per cabang, tidak tumpang tindih** (422 `mulai`) → satu tagihan hanya masuk satu rekap. Alur: buat → hitung (bisa diulang selama
  `draf`; baris otomatis diganti, penyesuaian manual dipertahankan) → **setujui & kunci** (`komisi.setujui`, terpisah dari `komisi.kelola` —
  pemisahan tugas manajer vs pemilik). Setelah disetujui: tidak bisa dihitung ulang, diberi penyesuaian, dihapus; model `KomisiPeriode` &
  `KomisiBaris` menolak ubah/hapus (`LogicException`). Refund setelahnya tidak mengubah slip (koreksi lewat penyesuaian periode berikutnya).
- **Baris = snapshot**: treatment (`tindakan_id`), dasar, jenis & nilai komisi, komisi — slip tetap terbaca walau komisi treatment diubah.
- **Penyesuaian manual** (bonus/potongan, boleh negatif) = baris `sumber=penyesuaian`, tercatat audit (`penyesuaian_komisi`, `hapus_penyesuaian_komisi`);
  hitung ulang tercatat `hitung_komisi`. Baris hitung otomatis disimpan massal (tidak diaudit per baris).
- **Slip sendiri** (`/komisi-saya`): semua user login melihat baris miliknya dari periode **disetujui** (lintas cabang).
- **Tagihan Rp 0** (mis. seluruhnya sesi paket di poli tanpa jasa konsultasi) bisa dilunasi tanpa pembayaran — agar masuk rekap.

## Skema

| Migration | Isi |
|-----------|-----|
| `2026_10_01_130001_create_komisi_tables` | `komisi_periodes`, `komisi_barises`, `kunjungan_tindakans.asisten_id`, izin `komisi.kelola` → manajer (+ `aturan_komisis` versi awal, dihapus revisi) |
| `2026_10_01_140001_pindah_komisi_dan_konsultasi_ke_treatment` | `tindakan_komisis`; `polis.tindakan_konsultasi_id` (− `tarif_konsultasi`); `komisi_barises.tindakan_id` (− `aturan_komisi_id`); drop `aturan_komisis`; **konversi data** (di bawah) |

Konversi data (diuji PostgreSQL 17: data seeder → rollback → aturan kategori/umum + aturan khusus cabang → migrate; hasil identik 33 baris):
- Tarif konsultasi poli > 0 → treatment `KNS-<kode poli>` "Konsultasi <nama poli>" (kategori Konsultasi, ICD-9-CM 89.07) + dipasang ke poli.
- Aturan aktif tanpa cabang → komisi per treatment: per treatment & peran dipakai aturan paling spesifik (treatment > kategori > umum;
  treatment konsultasi: poli > semua poli, dokter saja). Nilai 0 → tidak ada baris. Aturan khusus cabang tidak punya padanan (dilewati).
- `tagihan_items.tindakan_id` item konsultasi lama & `komisi_barises.tindakan_id` lama diisi (dari tindakan kunjungan / jasa konsultasi poli).
- Rollback: `tarif_konsultasi` dari tarif treatment terpasang, komisi treatment → aturan khusus treatment / konsultasi poli; treatment
  konsultasi hasil konversi dibiarkan.

| Tabel | Kolom |
|-------|-------|
| `tindakan_komisis` | tindakan_id, peran (`dokter`/`terapis`/`asisten`), jenis (`persen`/`nominal`), nilai `decimal(12,2)`; unik (tindakan_id, peran) |
| `polis` (±) | **tindakan_konsultasi_id** (null on delete) menggantikan `tarif_konsultasi` |
| `kunjungan_tindakans` (+) | **asisten_id** (null on delete) |
| `komisi_periodes` | cabang_id, nama, mulai, selesai, status (`draf`/`disetujui`), dasar (snapshot), total, dihitung_at/_oleh, disetujui_at/_oleh, catatan, created_by — `DalamCabang` |
| `komisi_barises` | komisi_periode_id, user_id, peran (+ `penyesuaian`), sumber (`tindakan`/`konsultasi`/`penyesuaian`), kunjungan_id, kunjungan_tindakan_id, tagihan_id, **tindakan_id**, tanggal, deskripsi, dasar, jenis, nilai, komisi (boleh negatif), dibuat_oleh |

Model: `TindakanKomisi` (Auditable; `hitung()`, `teksNilai()`), `Tindakan::komisis()`, `Poli::tindakanKonsultasi()` + `jasaKonsultasi($cabangId)`
(treatment + `tarif_cabang`, null bila tidak ditagihkan), `KomisiPeriode` (Auditable, DalamCabang, kunci), `KomisiBaris` (kunci).
Enum: `PeranKomisi` (`perTreatment()`), `SumberKomisi`, `JenisKomisi`, `StatusKomisiPeriode`. Izin: `komisi.kelola`, `komisi.setujui`.
Service `KomisiService` (buatPeriode, hitung, setujui, penyesuaian, hapusPenyesuaian, ringkasan); `TindakanService::syncKomisi`;
`TagihanService::buatDariKunjungan` (item konsultasi dari `Poli::jasaKonsultasi`).

## API

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| POST / PUT | `/tindakans`, `/{id}` | master.kelola (+ komisi.kelola untuk `komisis`) | + `komisis?: [{ peran* (dokter/terapis/asisten, unik), jenis* (persen/nominal), nilai* (≥ 0, persen ≤ 100, 2 desimal) }]` replace-all; dikirim tanpa `komisi.kelola` → 403. 422 `komisis.{i}.nilai` / `.peran` |
| GET | `/tindakans/{id}` · `/tindakans?komisi=1` | master.kelola · login | + `komisis[]{peran, jenis, nilai}` hanya untuk pemegang `komisi.kelola` |
| DELETE | `/tindakans/{id}` | master.kelola | 422 bila dipakai sebagai jasa konsultasi poli |
| POST / PUT | `/polis`, `/{id}` | master.kelola | `tindakan_konsultasi_id` (nullable, treatment belum dihapus) menggantikan `tarif_konsultasi`; daftar lengkap & respons + `tindakan_konsultasi{id, kode, nama, tarif, is_active}` |
| GET | `/kunjungans/{id}` (semua respons `loadDetail`) | login | + `konsultasi: {id, kode, nama, tarif, tarif_cabang, tersedia} \| null` (jasa konsultasi poli di cabang kunjungan, untuk estimasi) |
| GET | `/komisi-periodes` | komisi.kelola, komisi.setujui | paginated (cabang aktif); + `barises_count` |
| POST | `/komisi-periodes` | komisi.kelola | `{ nama*, mulai*, selesai*, catatan }` (cabang aktif) |
| GET | `/komisi-periodes/{id}` | komisi.kelola, komisi.setujui | + `ringkasan[]{user, total, jumlah_baris, per_peran}`, `barises[]` (`?user_id=` satu petugas) |
| POST | `/komisi-periodes/{id}/hitung` | komisi.kelola | draf; bentuk sama dengan detail |
| POST | `/komisi-periodes/{id}/setujui` | komisi.setujui | draf & sudah dihitung |
| POST / DELETE | `/komisi-periodes/{id}/penyesuaian`, `/penyesuaian/{baris}` | komisi.kelola | `{ user_id*, komisi* (≠ 0, boleh negatif), keterangan* }` |
| DELETE | `/komisi-periodes/{id}` | komisi.kelola | hanya draf |
| GET | `/komisi-saya` | login | periode disetujui yang memuat komisi user + `total_saya`; `?periode_id=` → slip (`barises`, `total`, `user`) |

`/aturan-komisis` **dihapus**. Endpoint lama lain: `PUT /kunjungans/{id}/pemeriksaan` + `tindakans[].asisten_id` (petugas medis di cabang
kunjungan; 422 `tindakans.{i}.asisten_id`); detail kunjungan `tindakans[].asisten`; bayar tagihan Rp 0 tanpa pembayaran. Asisten masuk hash
RME (hanya bila diisi) dan dihitung sebagai tim yang menangani untuk kunjungan berakses terbatas.

## Pengaturan, izin & data demo

`komisi.dasar` (`neto`/`bruto`, Pengaturan → Komisi). Izin `komisi.kelola` (manajer: rekap; komisi treatment butuh juga `master.kelola`),
`komisi.setujui` (hanya administrator di data bawaan — berikan lewat Peran & Izin). Treatment konsultasi demo: KNS-001 Konsultasi dokter
estetika Rp 100.000 (Poli Estetika Medis), KNS-002 spesialis kulit & kelamin Rp 150.000 (Poli Kulit & Kelamin), KNS-003 dokter gigi
Rp 75.000 (Poli Gigi). Komisi demo (wajib disesuaikan klinik): Konsultasi dokter 40%; bawaan treatment dokter 10%; Facial & Peeling
terapis 10% (dokter tanpa komisi); Laser & Energy Device dokter 5% + terapis Rp 50.000; Perawatan Gigi dokter 30% + asisten Rp 10.000;
Botox (TRT-001) dokter 15% + asisten Rp 25.000.

## Frontend

Detail: `frontend/AI-Context/08-fitur-fase-1.md` bagian F1-09. Ringkas: Master Data → **Treatment** bagian "Komisi & jasa medis" (+ kolom
Komisi di daftar); Master Data → **Poli** pilihan "Jasa konsultasi dokter"; Keuangan → **Komisi** (`/komisi`, `/komisi/:id`, tombol
"Komisi per treatment"); Beranda → **Komisi Saya** (`/slip-komisi`); komponen `komisi/SlipKomisi` (cetak); pilihan Asisten per tindakan
di pemeriksaan; estimasi biaya memakai `kunjungan.konsultasi`; Pengaturan → Komisi; kasir "Tandai lunas (Rp 0)".

## Test

`tests/Feature/KomisiTest.php` (7 test): komisi di master treatment (detail hanya untuk `komisi.kelola`, validasi persen ≤ 100 / peran dobel /
peran penyesuaian, replace-all + audit, tanpa key = tetap, pemegang `master.kelola` tanpa `komisi.kelola` → tidak terlihat & 403); jasa
konsultasi = treatment poli (item konsultasi menunjuk treatment, harga khusus cabang, `kunjungan.konsultasi`, tidak dobel bila dicatat sebagai
tindakan, treatment nonaktif → tanpa item, validasi poli, larangan hapus); rekap dari tagihan lunas per peran (botox dokter + asisten, facial
terapis, laser dengan diskon → neto, tagihan belum dibayar diabaikan, bruto, komisi treatment diubah); sesi paket (dasar nilai per sesi) &
tagihan Rp 0; persetujuan mengunci (izin terpisah, belum dihitung ditolak, penyesuaian ±, hapus penyesuaian, periode tumpang tindih, kunci
model, refund setelah disetujui tidak mengubah total); slip sendiri; asisten divalidasi, dipertahankan saat simpan ulang, masuk hash RME.
Ikut disesuaikan: `AlurKlinikTest`, `KatalogTreatmentTest` (total = `konsultasi.tarif_cabang` + tindakan), `OdontogramTest`.

E2E browser (Chrome headless, stack dev terisolasi), revisi: admin mengubah komisi laser di form treatment (contoh "≈ Rp 96.000 dari harga
dasar"), kolom Komisi di daftar; hapus treatment jasa konsultasi ditolak dengan pesan poli; Master Poli memilih/mengganti jasa konsultasi;
dokter: estimasi "Konsultasi dokter estetika Rp 100.000", berubah "Tanpa jasa konsultasi" saat treatment konsultasi dicatat sebagai tindakan;
tagihan: item konsultasi menunjuk treatment; rekap manajer dokter 40% konsultasi + 5% laser, terapis 8%, asisten Rp 20.000; tombol "Komisi
per treatment" hanya untuk pemegang `master.kelola`; `/aturan-komisi` → 404; mobile 390 px tanpa scroll horizontal (daftar & form treatment,
master poli). Tanpa error konsol selain 422 yang memang diuji. E2E versi awal (asisten, periode, penyesuaian, setujui, Komisi Saya) tetap berlaku.
