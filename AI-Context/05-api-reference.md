# 05 — API Reference

Base URL: `http://localhost:8000/api`. Header: `Accept: application/json`, `Authorization: Bearer <token>`.
Kolom **Role**: siapa yang boleh (admin selalu boleh). "semua" = user login apa pun.
Sumber kebenaran: `routes/api.php`.

## Auth
| Method | Path | Role | Keterangan |
|--------|------|------|------------|
| POST | `/login` | publik (throttle 10/menit) | `{ email, password, device_name? }` → `{ token, user }` (user + `poli` + `role_label`) |
| GET | `/me` | semua | user saat ini (bentuk sama dengan `user` di login) |
| POST | `/logout` | semua | cabut token saat ini |
| GET | `/dashboard` | semua | ringkasan hari ini: kunjungan per status/per poli, pasien, resep menunggu, tagihan belum bayar, pendapatan, obat stok menipis |

## Referensi (read-only)
| Method | Path | Role | Query |
|--------|------|------|-------|
| GET | `/polis` | semua | `aktif=1` — **array**, termasuk `dokters_count` |
| GET | `/polis/{poli}` | semua | + `dokters` |
| GET | `/dokters` | semua | `poli_id` — **array** `[id, name, poli_id, sip]` |
| GET | `/icd10s` | semua | `q` (kode prefix / nama) |
| GET | `/tindakans` | semua | `q`, `aktif=1` |
| GET | `/obats` | semua | `q`, `aktif=1`, `menipis=1` |
| GET | `/obats/{obat}` | semua | |

## Pasien
| Method | Path | Role | Keterangan |
|--------|------|------|------------|
| GET | `/pasiens` | semua | `q` = nama (ilike) / no_rm / nik / no_bpjs (prefix) |
| GET | `/pasiens/{pasien}` | semua | + 50 kunjungan terakhir (poli, dokter, diagnosa) |
| GET | `/pasiens/{pasien}/riwayat` | dokter, perawat | 20 kunjungan selesai terakhir dengan rekam medis lengkap |
| POST | `/pasiens` | pendaftaran | lihat field di bawah |
| PUT | `/pasiens/{pasien}` | pendaftaran | |
| DELETE | `/pasiens/{pasien}` | admin | ditolak bila punya kunjungan |

Field pasien: `nama*`, `jenis_kelamin*` (L/P), `tanggal_lahir*` (≤ hari ini), `nik` (16 digit, unik), `no_bpjs` (13 digit),
`tempat_lahir`, `golongan_darah` (A/B/AB/O/-), `alamat`, `no_hp`, `pekerjaan`, `alergi`.

## Kunjungan & pemeriksaan
| Method | Path | Role | Keterangan |
|--------|------|------|------------|
| GET | `/kunjungans` | semua | `tanggal` (default hari ini), `poli_id`, `dokter_id`, `status` (bisa koma: `menunggu,diperiksa`), `q`; urut poli, no_antrian; per_page default 50 |
| GET | `/kunjungans/{id}` | semua | detail lengkap (`loadDetail`) |
| POST | `/kunjungans` | pendaftaran | `{ pasien_id*, poli_id*, dokter_id?, penjamin*, no_penjamin?, keluhan? }` |
| POST | `/kunjungans/{id}/batal` | pendaftaran | hanya status menunggu |
| POST | `/kunjungans/{id}/panggil` | dokter, perawat | menunggu → diperiksa |
| PUT | `/kunjungans/{id}/pemeriksaan` | dokter, perawat | upsert, lihat payload |
| POST | `/kunjungans/{id}/selesai` | dokter | diperiksa → menunggu_pembayaran + buat tagihan |

Payload `PUT /pemeriksaan` (semua opsional):
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

## Farmasi
| Method | Path | Role | Keterangan |
|--------|------|------|------------|
| GET | `/reseps` | apoteker | `status`, `tanggal`, `q`; termasuk `items_count`, `kunjungan.tagihan.status` |
| GET | `/reseps/{id}` | apoteker | items.obat (dengan stok), pasien, tagihan |
| POST | `/reseps/{id}/serahkan` | apoteker | wajib tagihan lunas & stok cukup |
| POST | `/obats` | apoteker | `{ kode*, nama*, satuan*, harga*, stok_minimum*, is_active, stok_awal? }` |
| PUT | `/obats/{id}` | apoteker | sama tanpa `stok_awal` (stok tidak bisa diubah di sini) |
| DELETE | `/obats/{id}` | admin | ditolak bila pernah diresepkan |
| GET | `/obats/{id}/mutasi` | apoteker | kartu stok (paginated, terbaru dulu, + user) |
| POST | `/obats/{id}/mutasi` | apoteker | `{ jenis*: masuk/keluar/penyesuaian, jumlah*, keterangan? }` → `{ mutasi, obat }` |

## Kasir
| Method | Path | Role | Keterangan |
|--------|------|------|------------|
| GET | `/tagihans` | kasir | `status`, `tanggal`, `q` |
| GET | `/tagihans/{id}` | kasir | items, kunjungan.pasien/poli/dokter, kasir |
| POST | `/tagihans/{id}/bayar` | kasir | `{ metode_bayar*, dibayar (wajib jika tunai), diskon? }` |

## Master (admin)
| Method | Path |
|--------|------|
| POST / PUT / DELETE | `/polis`, `/polis/{poli}` — `{ kode*, nama*, tarif_konsultasi*, is_active }` |
| apiResource (kecuali index) | `/tindakans` — `{ kode*, nama*, tarif*, is_active }` |
| apiResource (kecuali index) | `/icd10s` — `{ kode*, nama* }` |
| apiResource penuh | `/users` — `{ name*, email*, password* (opsional saat update, min 8), role*, poli_id (wajib jika dokter), sip, is_active }`, filter `role`, `q` |
