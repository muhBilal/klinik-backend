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
| GET | `/polis` | login | **array**. Tanpa filter: data lengkap + `dokters_count`. `aktif=1`: ringkas `{id, kode, nama}` |
| GET | `/polis/{poli}` | login | + `dokters` |
| GET | `/dokters` | login | `poli_id` — **array** `[id, name, poli_id, cabang_id, sip]`; dengan cabang aktif: dokter cabang itu + dokter lintas cabang |
| GET | `/icd10s` | login | `q`, `huruf` |
| GET | `/tindakans` | login | `q`, `aktif=1` (juga sembunyikan yang tidak dilayani di cabang), `status`, `kategori_id`, `cabang_id` (default cabang aktif). + `kategori`, `tarif_cabang`, `tersedia`, `hargas_count`, `bhps_count` |
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
| GET | `/kunjungans/{id}` | login | detail (`loadDetail`), **termasuk cabang lain**. Tanpa `rme.lihat`: tanpa pemeriksaan/tindakans/resep. Dengan `rme.lihat`: tercatat audit `lihat` |
| POST | `/kunjungans` | kunjungan.daftar | `{ pasien_id*, poli_id*, dokter_id?, penjamin*, no_penjamin?, keluhan? }` → + `cabang`. 422 `cabang` bila cabang aktif belum dipilih |
| POST | `/kunjungans/{id}/batal` | kunjungan.daftar | hanya status menunggu |
| POST | `/kunjungans/{id}/panggil` | pemeriksaan.panggil | menunggu → diperiksa |
| PUT | `/kunjungans/{id}/pemeriksaan` | pemeriksaan.vital, pemeriksaan.dokter | upsert, lihat payload |
| POST | `/kunjungans/{id}/selesai` | pemeriksaan.dokter | diperiksa → menunggu_pembayaran + buat tagihan |

Payload `PUT /pemeriksaan` (semua opsional; tanpa `pemeriksaan.dokter` hanya vital + `subjektif` yang dipakai):
```json
{
  "tekanan_darah": "120/80", "nadi": 88, "suhu": 37.5, "respirasi": 20, "berat_badan": 60, "tinggi_badan": 165,
  "subjektif": "...", "objektif": "...", "asesmen": "...", "plan": "...",
  "diagnosas": [{ "icd10_id": 5, "jenis": "primer" }],
  "tindakans": [{ "tindakan_id": 1, "jumlah": 1, "keterangan": null }],
  "resep": [{ "obat_id": 1, "jumlah": 10, "aturan_pakai": "3 x 1 sesudah makan" }],
  "catatan_resep": "..."
}
```
Respons: kunjungan lengkap (`loadDetail`).

## Berkas klinis terenkripsi
| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/berkas` | rme.lihat | `pasien_id` atau `kunjungan_id` wajib, `kategori` → **array** (maks. 200, terbaru dulu) + `pengunggah` |
| POST | `/berkas` | berkas.kelola | multipart: `file*` (jpg/jpeg/png/webp/pdf, maks. 10 MB), `kategori*`, `pasien_id*`, `kunjungan_id?`, `keterangan?` → 201 |
| GET | `/berkas/{uuid}/tautan` | rme.lihat | `{ url, kedaluwarsa }` — signed URL berlaku 5 menit. Tercatat audit `akses_berkas` |
| DELETE | `/berkas/{uuid}` | berkas.kelola | soft delete |

Bentuk berkas: `{ uuid, cabang_id, pasien_id, kunjungan_id, kategori, keterangan, nama_file, mime, ukuran, diunggah_oleh, pengunggah: {id, name}, created_at }` (tanpa `id`, `path`, `checksum`).

## Farmasi
| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/reseps` | farmasi.resep | `status`, `tanggal`, `poli_id`, `pembayaran=lunas/belum`, `q`; cabang aktif |
| GET | `/reseps/{id}` | farmasi.resep | items.obat (dengan stok), pasien, tagihan, `cabang` (kop etiket) |
| POST | `/reseps/{id}/serahkan` | farmasi.resep | wajib tagihan lunas & stok cukup; respons = bentuk detail resep |
| POST / PUT | `/obats`, `/obats/{id}` | farmasi.obat | `{ kode*, nama*, satuan*, harga*, stok_minimum*, is_active, stok_awal? (hanya POST) }` |
| GET / POST | `/obats/{id}/mutasi` | farmasi.obat | kartu stok / `{ jenis*: masuk/keluar/penyesuaian, jumlah*, keterangan? }` → `{ mutasi, obat }` |
| DELETE | `/obats/{id}` | master.kelola | soft delete; ditolak bila pernah diresepkan atau menjadi BHP standar treatment |

## Kasir
| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| GET | `/tagihans` | kasir.tagihan | `status`, `tanggal`, `metode_bayar`, `penjamin`, `poli_id`, `q`; cabang aktif |
| GET | `/tagihans/{id}` | kasir.tagihan | items, kunjungan.pasien/poli/dokter, kasir, `cabang` (kop struk: nama, alamat, telepon) |
| POST | `/tagihans/{id}/bayar` | kasir.tagihan | `{ metode_bayar*, dibayar (wajib jika tunai), diskon? }`; respons = bentuk detail tagihan |

## Master data
| Method | Path | Izin |
|--------|------|------|
| POST / PUT / DELETE | `/polis`, `/polis/{poli}` — `{ kode*, nama*, tarif_konsultasi*, is_active }` | master.kelola |
| apiResource (kecuali index) | `/tindakans` — `{ kode*, nama*, kategori_id, durasi_menit* (1–720), buffer_menit (0–240), tarif* (harga dasar), is_active, hargas?: [{cabang_id*, tarif*, tersedia}], bhps?: [{obat_id*, jumlah* (desimal ≤3)}] }`; `hargas`/`bhps` replace-all bila dikirim. Show/store/update → + `kategori`, `hargas[].cabang`, `bhps[].obat`. Detail: [modul/F1-01](modul/F1-01-katalog-treatment.md) | master.kelola |
| POST / PUT / DELETE | `/kategori-tindakans`, `/kategori-tindakans/{kategori}` — `{ nama* (unik), deskripsi, is_active }`; hapus ditolak bila masih dipakai | master.kelola |
| apiResource (kecuali index) | `/icd10s` — `{ kode*, nama* }` | master.kelola |
| POST / GET / PUT / DELETE | `/cabangs`, `/cabangs/{cabang}` — `{ kode* (A-Z0-9-, disimpan huruf besar), nama*, alamat, telepon, email, jam_buka (H:i), jam_tutup (H:i, > jam_buka), is_active }` | cabang.kelola |

## Administrasi
| Method | Path | Izin | Keterangan |
|--------|------|------|------------|
| apiResource | `/users` | pengguna.kelola | `{ name*, email*, password* (opsional saat update, min 8), role* (kode peran), poli_id (wajib bila peran berizin pemeriksaan.dokter), cabang_id (null = semua cabang), sip, is_active }`; filter `role`, `poli_id`, `cabang_id`, `status`, `q`. Relasi `poli`, `cabang`, `peran` |
| GET | `/perans` | peran.kelola, pengguna.kelola | **array** peran + `izin` (array kode) + `users_count` |
| GET | `/izins` | peran.kelola, pengguna.kelola | katalog `[{ grup, izin: [{ kode, label }] }]` |
| POST / PUT | `/perans`, `/perans/{peran}` | peran.kelola | `{ kode* (^[a-z][a-z0-9_]*$; diabaikan untuk peran sistem), nama*, deskripsi, izin: [kode] }` |
| DELETE | `/perans/{peran}` | peran.kelola | ditolak untuk peran sistem / peran yang dipakai |
| GET | `/pengaturan` | pengaturan.kelola | semua pengaturan bertingkat (lihat [modul/F0-06-pengaturan-klinik.md](modul/F0-06-pengaturan-klinik.md)) |
| PUT | `/pengaturan` | pengaturan.kelola | payload bertingkat parsial, mis. `{ "klinik": { "nama": "..." }, "keamanan": { "wajib_2fa": ["admin"] } }` → semua pengaturan |
| GET | `/audit-logs` | audit.lihat | `aksi` (bisa koma), `tipe`, `subjek_id`, `user_id`, `pasien_id`, `cabang_id`, `dari`, `sampai`, `q` (label); paginated 30, terbaru dulu, + `user`, `cabang` (tanpa `perubahan`) |
| GET | `/audit-logs/{id}` | audit.lihat | satu baris lengkap dengan `perubahan`, `user_agent` |
