# 05 — API Reference

Base URL: `http://localhost:8000/api`. Header: `Accept: application/json`, `Authorization: Bearer <token>`,
opsional `X-Cabang-Id: <id>` (cabang aktif untuk user lintas cabang; diabaikan untuk user terikat cabang).
Kolom **Izin**: izin yang dibutuhkan (salah satu bila lebih dari satu; administrator selalu boleh). "login" = user login apa pun.
Semua route login kecuali grup Profil juga melewati middleware `wajib2fa`. Sumber kebenaran: `routes/api.php`.
Peta peran bawaan → izin: [modul/F0-01-rbac-peran-izin.md](modul/F0-01-rbac-peran-izin.md).

## Publik
| Method | Path | Keterangan |
|--------|------|------------|
| GET | `/info` | pengaturan publik: `{ klinik: {nama, alamat, telepon, email, npwp}, struk: {catatan_kaki}, cetak: {lebar_struk} }` |
| POST | `/login` (throttle 10/menit) | `{ email, password, device_name? }` → `{ token, user }` atau `{ two_factor: true, tantangan }` |
| POST | `/login/2fa` (throttle 6/menit) | `{ tantangan, kode }` (kode TOTP 6 digit atau kode pemulihan) → `{ token, user }` |
| GET | `/berkas/{uuid}/unduh` | **signed URL** dari `/berkas/{uuid}/tautan` (tanpa token). Isi terdekripsi, `Cache-Control: no-store` |

## Profil (login, di luar `wajib2fa`)
| Method | Path | Keterangan |
|--------|------|------------|
| GET | `/me` | bentuk `user` (lihat bawah) |
| POST | `/logout` | cabut token saat ini |
| PUT | `/me/password` | `{ password_lama, password, password_confirmation }`; token lain dicabut |
| POST | `/me/2fa` | mulai: `{ secret, otpauth_url }` (belum aktif) |
| POST | `/me/2fa/konfirmasi` | `{ kode }` → `{ kode_pemulihan: [8] }` — 2FA aktif |
| POST | `/me/2fa/kode-pemulihan` | `{ password }` → kode pemulihan baru |
| DELETE | `/me/2fa` | `{ password }` → nonaktifkan |

Bentuk `user` (login, `/me`):
```json
{ "id": 4, "name": "dr. Andi", "email": "...", "role": "dokter", "role_label": "Dokter", "poli_id": 1, "cabang_id": 1, "sip": "...",
  "is_active": true, "two_factor_confirmed_at": null, "poli": {"id","kode","nama"}, "cabang": {"id","kode","nama"} | null,
  "izin": ["pasien.lihat", "..."], "tercatat_dokter": true,
  "cabangs": [{"id","kode","nama"}], "two_factor": {"aktif": false, "wajib": false}, "sesi": {"idle_timeout_menit": 15} }
```
`cabangs` = cabang yang boleh dipilih (user terikat cabang: hanya cabangnya).

## Umum & referensi
| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/dashboard` | login | ringkasan hari ini **cabang aktif** (+`cabang_id`): kunjungan per status/poli, pasien, resep menunggu, tagihan belum bayar, pendapatan (`null` tanpa `laporan.keuangan`), obat stok menipis |
| GET | `/cabangs` | login | **array**. Pemegang `cabang.kelola`: semua cabang + `users_count` (`status`, `q`); lainnya: cabang aktif yang boleh diakses |
| GET | `/polis` | login | **array**. Tanpa filter: data lengkap + `dokters_count`. `aktif=1`: ringkas `{id, kode, nama, spesialisasi}` |
| GET | `/polis/{poli}` | login | + `dokters` |
| GET | `/dokters` | login | `poli_id` — **array** `[id, name, poli_id, cabang_id, sip]`; dengan cabang aktif: dokter cabang itu + dokter lintas cabang |
| GET | `/icd10s` | login | `q`, `huruf`, `favorit=1`; + `sensitif`, `favorit` (favorit dokter tampil paling atas) |
| GET | `/icd9cms` | login | `q`, `favorit=1`; + `favorit` |
| GET | `/template-soaps` | login | **array**; `poli_id` (template poli itu + umum), `aktif=1`, `status`, `q`; + `poli`, `tindakan`, `diagnosas` |
| GET | `/petugas` | login | **array** petugas medis (dokter/perawat/terapis) cabang aktif + lintas cabang |
| GET | `/tindakans` | login | `q`, `aktif=1` (juga sembunyikan yang tidak dilayani di cabang), `status`, `kategori_id`, `cabang_id` (default cabang aktif). + `kategori`, `icd9cm`, `icd9cm_id`, `template_consent_id`, `jenis_catatan`, `per_gigi`, `kondisi_gigi_hasil`, `tarif_cabang`, `tersedia`, `hargas_count`, `bhps_count` |
| GET | `/kategori-tindakans` | login | **array**. `aktif=1`: `{id, nama}` aktif; tanpa filter: lengkap + `tindakans_count`. `status`, `q` |
| GET | `/obats`, `/obats/{obat}` | login | `q`, `aktif=1`, `menipis=1`, `satuan`, `status` |

## Pasien (master pusat, lintas cabang)
| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/pasiens` | pasien.lihat | `q` = nama (ilike) / no_rm / nik / no_bpjs (prefix); `jenis_kelamin`, `golongan_darah`, `bpjs=ya/tidak` |
| GET | `/pasiens/{pasien}` | pasien.lihat | + 50 kunjungan terakhir **semua cabang** (poli, dokter, cabang; diagnosa hanya bila `rme.lihat`). `ringkas=1`: identitas saja. Tercatat audit `lihat` |
| GET | `/pasiens/{pasien}/riwayat` | rme.lihat | 20 kunjungan selesai terakhir semua cabang dengan rekam medis + `cabang`. `kecuali={kunjungan_id}`. Tercatat audit |
| POST / PUT | `/pasiens`, `/pasiens/{pasien}` | pasien.kelola | lihat field di bawah |
| DELETE | `/pasiens/{pasien}` | pasien.hapus | soft delete; ditolak bila punya kunjungan |

Field pasien: `nama*`, `jenis_kelamin*` (L/P), `tanggal_lahir*` (≤ hari ini), `nik` (16 digit, unik), `no_bpjs` (13 digit),
`tempat_lahir`, `golongan_darah` (A/B/AB/O/-), `alamat`, `no_hp`, `pekerjaan`, `alergi`.

## Kunjungan & pemeriksaan (cabang aktif)
| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/kunjungans` | login | `tanggal` (default hari ini), `poli_id`, `dokter_id`, `status` (bisa koma), `penjamin`, `q`; + `cabang`; per_page default 50 |
| GET | `/kunjungans/{id}` | login | detail (`loadDetail`), **termasuk cabang lain**. Tanpa `rme.lihat` — atau kunjungan berakses terbatas yang tidak boleh dibaca (`rme_disembunyikan: true`) — tanpa pemeriksaan/tindakans/informed_consents/resep. Dengan RME: tercatat audit `lihat` |
| POST | `/kunjungans` | kunjungan.daftar | `{ pasien_id*, poli_id*, dokter_id?, penjamin*, no_penjamin?, keluhan? }` → + `cabang`. 422 `cabang` bila cabang aktif belum dipilih |
| POST | `/kunjungans/{id}/batal` | kunjungan.daftar | hanya status menunggu |
| POST | `/kunjungans/{id}/panggil` | pemeriksaan.panggil | menunggu → diperiksa |
| PUT | `/kunjungans/{id}/pemeriksaan` | pemeriksaan.vital, pemeriksaan.dokter | upsert, lihat payload |
| POST | `/kunjungans/{id}/selesai` | pemeriksaan.dokter | diperiksa → menunggu_pembayaran + buat tagihan + **tanda tangan RME**. 422 `sip` (SIP tidak aktif), `informed_consent` (consent wajib kurang) |
| POST | `/kunjungans/{id}/addendum` | pemeriksaan.dokter | `{ bagian*, isi*, alasan* }`; hanya RME yang sudah ditandatangani → 201 |
| GET | `/kunjungans/{id}/verifikasi` | rme.lihat | `{ ditandatangani, valid, ditandatangani_at, penandatangan }` — cocokkan hash tanda tangan |
| POST / DELETE | `/kode-favorits` | pemeriksaan.dokter | `{ jenis*: icd10/icd9cm, kode_id* }` |

Payload `PUT /pemeriksaan` (semua opsional; tanpa `pemeriksaan.dokter` hanya vital + `subjektif` yang dipakai):
```json
{
  "tekanan_darah": "120/80", "nadi": 88, "suhu": 37.5, "respirasi": 20, "berat_badan": 60, "tinggi_badan": 165,
  "subjektif": "...", "objektif": "...", "asesmen": "...", "plan": "...",
  "diagnosas": [{ "icd10_id": 5, "jenis": "primer" }],
  "akses_terbatas": false,
  "tindakans": [{ "id": 12, "tindakan_id": 1, "jumlah": 1, "keterangan": null, "petugas_id": 9, "icd9cm_id": 58,
                  "gigi": 16, "permukaan": "MO", "rencana_item_id": null }],
  "resep": [{ "obat_id": 1, "jumlah": 10, "aturan_pakai": "3 x 1 sesudah makan" }],
  "catatan_resep": "..."
}
```
Respons: kunjungan lengkap (`loadDetail`). `tindakans[].id` = id baris tindakan kunjungan yang sudah ada (upsert); tanpa `id`
baris dicocokkan lewat `tindakan_id` + `gigi`. `gigi` (FDI) wajib untuk treatment `per_gigi`; `permukaan` wajib bila kondisi hasilnya
per permukaan; `rencana_item_id` = item rencana perawatan gigi yang dikerjakan (gigi/permukaan diambil dari item bila kosong).

## RME estetika
Detail & payload: [modul/F1-05](modul/F1-05-rme-estetika.md)

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET / PUT | `/kunjungan-tindakans/{id}/catatan` | rme.lihat / rme.tindakan | catatan tindakan: area, catatan, petugas, `parameter` (energi), `sumber_daya_id`, `titiks[]` (injeksi, replace-all) |
| GET | `/kunjungans/{id}/informed-consents/pratinjau` | rme.tindakan | `template_consent_id*`, `kunjungan_tindakan_id?` → naskah ter-render |
| POST | `/kunjungans/{id}/informed-consents` | rme.tindakan | `{ template_consent_id*, kunjungan_tindakan_id?, keputusan*, penandatangan_nama*, hubungan*, ttd_penandatangan* (PNG data URL), saksi_nama?, ttd_saksi? }` |
| GET | `/informed-consents/{uuid}` | rme.lihat | naskah + tanda tangan + `checksum_valid`; tercatat audit |
| POST | `/informed-consents/{uuid}/cabut` | rme.tindakan | `{ alasan* }` |
| GET | `/template-consents` | rme.tindakan, master.kelola | **array**; `aktif=1` ringkas |

## Berkas klinis terenkripsi
| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/berkas` | rme.lihat | `pasien_id` atau `kunjungan_id` wajib, `kategori` → **array** (maks. 200, terbaru dulu) + `pengunggah` |
| POST | `/berkas` | berkas.kelola | multipart: `file*` (jpg/jpeg/png/webp/pdf, maks. 10 MB), `kategori*`, `pasien_id*`, `kunjungan_id?`, `keterangan?` → 201 |
| GET | `/berkas/{uuid}/tautan` | rme.lihat | `{ url, kedaluwarsa }` — signed URL berlaku 5 menit. Tercatat audit `akses_berkas` |
| DELETE | `/berkas/{uuid}` | berkas.kelola | soft delete |
| POST | `/berkas/tautan` | rme.lihat | `{ uuids*[] (≤60), pratinjau? }` → `[{ uuid, url, kedaluwarsa }]`; berkas terbatas dilewati; tiap tautan tercatat |

Foto klinis (F1-06, detail [modul/F1-06](modul/F1-06-foto-klinis.md)): `POST /berkas` menerima `thumbnail` (JPEG ≤ 1 MB),
`protokol_foto_id`, `posisi`, `tahap`, `kunjungan_tindakan_id`, `diambil_at`, `lebar`, `tinggi`; 422 `consent_foto` bila pasien belum
menyetujui foto. `GET /berkas` + filter `protokol_foto_id`, `posisi`, `tahap` dan item + `protokol`, `kunjungan`, `ada_thumbnail`.
`GET /berkas/{uuid}/tautan?pratinjau=1` = thumbnail.

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/protokol-fotos` | login | **array**; `aktif=1` |
| POST / PUT / DELETE | `/protokol-fotos`, `/{id}` | master.kelola | `{ nama*, deskripsi, posisi*[]{kode?, label*, petunjuk?}, is_active }` |
| GET | `/pasiens/{id}/persetujuan-foto` | pasien.lihat | `{ aktif, riwayat, tingkat }` |
| GET | `/pasiens/{id}/persetujuan-foto/pratinjau` | pasien.kelola, rme.tindakan | `?tingkat=` → `{ isi }` |
| POST | `/pasiens/{id}/persetujuan-foto` | pasien.kelola, rme.tindakan | `{ tingkat*, penandatangan_nama*, hubungan*, ttd*, kunjungan_id? }` |
| GET | `/persetujuan-fotos/{uuid}` | pasien.kelola, rme.lihat | naskah + tanda tangan + `checksum_valid`; tercatat audit |
| POST | `/persetujuan-fotos/{uuid}/cabut` | pasien.kelola, rme.tindakan | `{ alasan* }` |

Bentuk berkas: `{ uuid, cabang_id, pasien_id, kunjungan_id, kategori, keterangan, nama_file, mime, ukuran, diunggah_oleh, pengunggah: {id, name}, created_at }` (tanpa `id`, `path`, `checksum`).

## Kedokteran gigi: odontogram & rencana perawatan
Detail & aturan: [modul/F1-07](modul/F1-07-odontogram.md)

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/odontogram/referensi` | login | `{ kondisi[]{kode, label, cakupan, kelompok, warna}, permukaan }` |
| GET | `/pasiens/{id}/odontogram` | rme.lihat | `?kunjungan_id=` → status pada kunjungan itu + `perubahan{dicatat, diakhiri}`; + `bisa_diubah`, `kunjungans[]` (riwayat); tercatat audit |
| POST | `/kunjungans/{id}/odontogram` | pemeriksaan.dokter, rme.tindakan | `{ gigi*, kondisi*, permukaan[], keterangan }` → 201; kunjungan harus `diperiksa` |
| DELETE | `/kunjungans/{id}/odontogram/{kondisi}` | pemeriksaan.dokter, rme.tindakan | hapus koreksi di kunjungan ini |
| POST | `/kunjungans/{id}/odontogram/{kondisi}/akhiri` · `/pulihkan` | pemeriksaan.dokter, rme.tindakan | akhiri kondisi lama / batalkan pengakhiran manual |
| GET | `/pasiens/{id}/rencana-perawatans` | rme.lihat | **array**; `aktif=1`; + `estimasi_total`, `estimasi_selesai`, `estimasi_per_fase[]` |
| POST | `/pasiens/{id}/rencana-perawatans` | pemeriksaan.dokter | `{ judul*, catatan, kunjungan_id, dokter_id, items*[]{fase*, gigi, permukaan, tindakan_id*, jumlah, keterangan} }` |
| GET / PUT | `/rencana-perawatans/{id}` | rme.lihat / pemeriksaan.dokter | PUT hanya draf; `items[].id` = upsert |
| POST | `/rencana-perawatans/{id}/setujui` | pemeriksaan.dokter, rme.tindakan | `{ penyetuju_nama? }` |
| POST | `/rencana-perawatans/{id}/revisi` · `/batal` | pemeriksaan.dokter | revisi: disetujui → draf; batal: `{ alasan* }` |

## Farmasi
| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/reseps` | farmasi.resep | `status`, `tanggal`, `poli_id`, `pembayaran=lunas/belum`, `q`; cabang aktif |
| GET | `/reseps/{id}` | farmasi.resep | items.obat (dengan stok), pasien, tagihan, `cabang` (kop etiket) |
| POST | `/reseps/{id}/serahkan` | farmasi.resep | wajib tagihan lunas & stok cukup; respons = bentuk detail resep |
| POST / PUT | `/obats`, `/obats/{id}` | farmasi.obat | `{ kode*, nama*, satuan*, fraksional?, jam_pakai_setelah_buka?, harga*, stok_minimum*, is_active, stok_awal? (hanya POST) }`. `fraksional` = boleh dipakai sebagian (IN-03) |
| GET / POST | `/obats/{id}/mutasi` | farmasi.obat | kartu stok / `{ jenis*: masuk/keluar/penyesuaian, jumlah*, keterangan? }` → `{ mutasi, obat }`. Masuk/keluar lewat batch tanpa nomor di cabang aktif; `penyesuaian` = stok akhir yang diinginkan **di cabang itu** |
| DELETE | `/obats/{id}` | master.kelola | soft delete; ditolak bila pernah diresepkan atau menjadi BHP standar treatment |

## Booking & jadwal
Detail: [modul/F1-02](modul/F1-02-booking-jadwal.md)

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/appointments` | booking.lihat | `dari`, `sampai` (default hari ini), `petugas_id`, `poli_id`, `status`, `q`; + pasien, poli, petugas, tindakans, sumberDayas |
| GET | `/appointments/{id}` | booking.lihat | bentuk detail booking |
| GET | `/appointments-slot` | booking.lihat | `petugas_id*`, `tanggal*`, `tindakan_ids[]*`, `sumber_daya_ids[]?` → `{ durasi_menit, jam_kerja[], slot[] }` |
| POST | `/appointments` | booking.kelola | `{ pasien_id*, poli_id?, petugas_id?, mulai_at*, tindakan_ids[]*, sumber_daya_ids[]?, catatan? }`; `selesai_at` dihitung server dari durasi + buffer |
| PUT | `/appointments/{id}` | booking.kelola | field yang dikirim saja; jadwal & bentrok dihitung ulang |
| POST | `/appointments/{id}/konfirmasi` · `/batal` · `/tidak-hadir` | booking.kelola | `/batal` menerima `alasan_batal?`; `/tidak-hadir` hanya bila jadwal sudah lewat |
| POST | `/appointments/{id}/checkin` | booking.kelola | hanya booking hari ini & berpoli → `{ appointment, kunjungan }` (201) |
| DELETE | `/appointments/{id}` | booking.kelola | ditolak bila sudah menjadi kunjungan |
| GET | `/jadwals` | booking.lihat | `?user_id=` → `{ praktiks, pengecualians }` |
| POST / PUT / DELETE | `/jadwals`, `/jadwals/{id}` | jadwal.kelola | `{ user_id*, hari* (0=Minggu..6), jam_mulai* (H:i), jam_selesai* (H:i), is_active }`; ditolak bila beririsan |
| POST / DELETE | `/jadwal-pengecualians`, `/{id}` | jadwal.kelola | `{ user_id*, tanggal*, tipe*: cuti/tambahan, jam_mulai?, jam_selesai?, keterangan? }`; cuti tanpa jam = sehari penuh |
| GET | `/sumber-dayas` | booking.lihat | `tipe` (ruang/alat), `status`, `q`; cabang aktif |
| POST / PUT / DELETE | `/sumber-dayas`, `/{id}` | jadwal.kelola | `{ kode* (unik per cabang), nama*, tipe*: ruang/alat, is_active }` |

## Kasir
Detail: [modul/F1-03](modul/F1-03-kasir.md)

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/tagihans` | kasir.tagihan | `status`, `tanggal`, `metode_bayar`, `penjamin`, `poli_id`, `q`; cabang aktif |
| GET | `/tagihans/{id}` | kasir.tagihan | items, pembayarans, pasien, kunjungan.pasien/poli/dokter, kasir, `cabang` (kop struk) |
| POST | `/tagihans` | kasir.tagihan | tagihan tanpa kunjungan (produk/paket/deposit): `{ pasien_id?, keterangan?, items[]*{kategori*: produk/paket/deposit/lainnya, deskripsi*, jumlah*, harga*} }` |
| POST | `/tagihans/{id}/bayar` | kasir.tagihan | **split payment**: `{ pembayarans[]{metode*, jumlah*, referensi?}, diskon? }`. Bentuk lama `{ metode_bayar*, dibayar, diskon? }` tetap diterima. Non-tunai tidak boleh melebihi tagihan |
| POST | `/tagihans/{id}/batal` | kasir.void | `{ alasan_batal* }`; hanya tagihan belum bayar |
| POST | `/tagihans/{id}/refund` | kasir.void | `{ alasan_refund* }`; hanya tagihan lunas; kunjungan kembali ke `menunggu_pembayaran` |
| POST | `/reseps/{id}/batal` | farmasi.resep | `{ alasan_batal* }`; hanya resep yang belum diserahkan |
| GET | `/shift-kas` | kasir.shift | `kasir_id`, `tanggal`, `terbuka=1` |
| GET | `/shift-kas/aktif` | kasir.shift | shift kasir yang login, atau `null` |
| GET | `/shift-kas/{id}` | kasir.shift | + `rekap`: `per_metode[]`, `total`, `total_refund`, `kas_seharusnya` |
| POST | `/shift-kas` | kasir.shift | `{ modal_awal* }`; ditolak bila masih ada shift terbuka |
| POST | `/shift-kas/{id}/tutup` | kasir.shift | `{ kas_fisik*, catatan? }` → `selisih` (negatif = kurang) |

## Inventori
Detail: [modul/F1-04](modul/F1-04-inventori.md)

| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/stok-batches` | inventori.kelola | `obat_id`, `tersedia=1`, `habis=1`, `q`; urut FEFO; cabang aktif |
| GET | `/stok-batches/kedaluwarsa` | inventori.kelola | `?hari=30`; batch yang sudah/akan kedaluwarsa |
| POST | `/stok-batches` | inventori.kelola | penerimaan: `{ obat_id*, jumlah* (desimal ≤3), no_batch?, kedaluwarsa? (harus > hari ini), keterangan? }` |
| POST | `/stok-batches/{id}/sesuaikan` | inventori.kelola | stok opname: `{ jumlah*, keterangan? }` = jumlah akhir hasil hitung fisik |
| POST | `/stok-batches/{id}/buang` | inventori.kelola | buang sisa batch kedaluwarsa: `{ keterangan? }` |
| GET / PUT | `/kunjungan-tindakans/{id}/bhps` | inventori.kelola | pemakaian BHP; PUT replace-all `{ bhps[]{obat_id*, jumlah*, batch_id?} }`; ditolak setelah stok dipotong |

## Master data
| Method | Path | Izin |
|--------|------|------|
| POST / PUT / DELETE | `/polis`, `/polis/{poli}` — `{ kode*, nama*, spesialisasi (umum/gigi/kulit/estetika/lainnya), tarif_konsultasi*, is_active }` | master.kelola |
| apiResource (kecuali index) | `/tindakans` — `{ kode*, nama*, kategori_id, icd9cm_id, template_consent_id (diisi = wajib consent), jenis_catatan (umum/injeksi/energi), per_gigi, kondisi_gigi_hasil (kode odontogram; diisi = per_gigi), durasi_menit* (1–720), buffer_menit (0–240), tarif* (harga dasar), is_active, hargas?: [{cabang_id*, tarif*, tersedia}], bhps?: [{obat_id*, jumlah* (desimal ≤3)}] }`; `hargas`/`bhps` replace-all bila dikirim. Show/store/update → + `kategori`, `hargas[].cabang`, `bhps[].obat`. Detail: [modul/F1-01](modul/F1-01-katalog-treatment.md) | master.kelola |
| POST / PUT / DELETE | `/kategori-tindakans`, `/kategori-tindakans/{kategori}` — `{ nama* (unik), deskripsi, is_active }`; hapus ditolak bila masih dipakai | master.kelola |
| apiResource (kecuali index) | `/icd10s` — `{ kode*, nama*, sensitif? }` (kosong = otomatis untuk kode IMS/HIV) | master.kelola |
| apiResource (kecuali index) | `/icd9cms` — `{ kode* (mis. 86.3), nama* }`; hapus ditolak bila dipakai | master.kelola |
| POST / PUT / DELETE | `/template-soaps`, `/{id}` — `{ nama*, poli_id, tindakan_id, subjektif, objektif, asesmen, plan, icd10_ids[], akses_terbatas, is_active }` | master.kelola |
| POST / GET / PUT / DELETE | `/template-consents`, `/{id}` — `{ nama*, isi*, is_active }`; hapus ditolak bila dipasang ke treatment | master.kelola |
| POST / GET / PUT / DELETE | `/cabangs`, `/cabangs/{cabang}` — `{ kode* (A-Z0-9-, disimpan huruf besar), nama*, alamat, telepon, email, jam_buka (H:i), jam_tutup (H:i, > jam_buka), is_active }` | cabang.kelola |

## Administrasi
| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| apiResource | `/users` | pengguna.kelola | `{ name*, email*, password* (opsional saat update, min 8), role* (kode peran), poli_id (wajib bila peran berizin pemeriksaan.dokter), cabang_id (null = semua cabang), sip, sip_berlaku_sampai, is_active }`; filter `role`, `poli_id`, `cabang_id`, `status`, `q`. Relasi `poli`, `cabang`, `peran` |
| GET | `/perans` | peran.kelola, pengguna.kelola | **array** peran + `izin` (array kode) + `users_count` |
| GET | `/izins` | peran.kelola, pengguna.kelola | katalog `[{ grup, izin: [{ kode, label }] }]` |
| POST / PUT | `/perans`, `/perans/{peran}` | peran.kelola | `{ kode* (^[a-z][a-z0-9_]*$; diabaikan untuk peran sistem), nama*, deskripsi, izin: [kode] }` |
| DELETE | `/perans/{peran}` | peran.kelola | ditolak untuk peran sistem / peran yang dipakai |
| GET | `/pengaturan` | pengaturan.kelola | semua pengaturan bertingkat (lihat [modul/F0-06-pengaturan-klinik.md](modul/F0-06-pengaturan-klinik.md)) |
| PUT | `/pengaturan` | pengaturan.kelola | payload bertingkat parsial, mis. `{ "klinik": { "nama": "..." }, "keamanan": { "wajib_2fa": ["admin"] } }` → semua pengaturan |
| GET | `/audit-logs` | audit.lihat | `aksi` (bisa koma), `tipe`, `subjek_id`, `user_id`, `pasien_id`, `cabang_id`, `dari`, `sampai`, `q` (label); paginated 30, terbaru dulu, + `user`, `cabang` (tanpa `perubahan`) |
| GET | `/audit-logs/{id}` | audit.lihat | satu baris lengkap dengan `perubahan`, `user_agent` |
