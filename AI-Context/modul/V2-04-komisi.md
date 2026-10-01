# V2-04 — Komisi & Jasa Medis, Petugas Tambahan per Tindakan

**PRD v2:** KM-01 (aturan komisi per treatment per peran, persen/nominal, split dokter–terapis–asisten), KM-03 (rekap & slip per
periode, dikunci setelah disetujui), AN-03 (beberapa petugas per tindakan), TR-01 (aturan komisi katalog) · **Status:** selesai.
Belum: KM-02 komisi penjualan produk & paket untuk CS/beautician (Fase 2).

## Keputusan desain

- **Komisi = kejadian, bukan hitungan ulang terus-menerus.** Baris `komisis` dibuat saat tagihan kunjungan **lunas** (`sumber = bayar`, +)
  dan dibalik saat **refund** (`sumber = refund`, −). Aturan, dasar, dan pembagi di-snapshot per baris.
- **Periode = bulan kejadian** (`YYYY-MM`), per cabang. `periode_komisis.status = disetujui` mengunci periode: kejadian baru yang jatuh di
  bulan terkunci masuk ke **periode terbuka berikutnya** (`KomisiService::periodeTerbuka`). Rekap yang sudah dibayar tidak pernah berubah.
- **Dasar persen** (`komisi.dasar`, default `setelah_diskon`): subtotal baris tindakan × (total − diskon − promo) / total. Pajak tidak
  pernah masuk. `sebelum_diskon` = subtotal katalog. **Sesi paket** (baris Rp 0) memakai `paket_pasien_items.nilai_per_sesi × jumlah`.
  Nominal = nilai × jumlah tindakan.
- **Pemilihan aturan**: aturan aktif untuk peran itu; skor treatment (4) > kategori (2) > semua (0), +1 bila khusus cabang; skor
  tertinggi menang (`AturanKomisi::skor`). Satu cakupan + peran hanya satu aturan (422 `peran`).
- **Peserta tindakan**: pelaksana utama `kunjungan_tindakans.petugas_id` (peran **dokter** bila memegang `pemeriksaan.dokter` dan bukan
  akses penuh, selain itu **terapis**) + `kunjungan_tindakan_petugas` (peran eksplisit: dokter/terapis/asisten). Petugas dengan peran
  sama dalam satu tindakan **berbagi rata** (`dibagi`).
- **Hanya tagihan kunjungan** yang menghasilkan komisi tindakan; penjualan paket/produk (tagihan mandiri) menunggu KM-02.
- **Hitung ulang** (periode draf saja): hapus semua baris periode itu, buat ulang baris bayar dari tagihan yang tercatat + tagihan kunjungan
  yang dibayar di bulan itu dan belum punya baris bayar di periode lain, lalu buat ulang baris refund. Dipakai setelah aturan diubah
  atau saat aturan baru dibuat setelah transaksi.
- `tagihan_items.kunjungan_tindakan_id` kini diisi `TagihanService::buatDariKunjungan`; tagihan lama dicocokkan lewat `tindakan_id`.

## Skema

| Tabel | Isi |
|-------|-----|
| `kunjungan_tindakan_petugas` | `kunjungan_tindakan_id`, `user_id`, `peran` (unik per tindakan+user) |
| `aturan_komisis` | `tindakan_id?`, `kategori_id?`, `cabang_id?`, `peran`, `jenis` (persen/nominal), `nilai`, `is_active`, soft delete |
| `komisis` | `cabang_id`, `periode`, `user_id`, `peran`, `sumber`, `tagihan_id`, `tagihan_item_id`, `kunjungan_tindakan_id`, `tindakan_id`, `aturan_id`, `dasar`, `jenis`, `nilai_aturan`, `dibagi`, `jumlah` |
| `periode_komisis` | `cabang_id`, `periode` (unik), `status` draf/disetujui, `disetujui_oleh`, `disetujui_at`, `catatan` |

## API

```
PUT  /api/kunjungan-tindakans/{id}/catatan   + petugas_tambahan[]{user_id, peran}  (replace-all; petugas medis cabang kunjungan)
GET  /api/kunjungan-tindakans/{id}/catatan   kunjungan_tindakan.petugas_tambahan[] (+ user)

GET|POST|PUT|DELETE /api/aturan-komisis      izin komisi.kelola
GET  /api/komisi/rekap?periode=YYYY-MM       izin komisi.kelola / laporan.keuangan; cabang aktif (null = semua cabang)
GET  /api/komisi/rincian?periode=&user_id=   tanpa user_id = slip sendiri (semua cabang); user lain butuh komisi.kelola
POST /api/komisi/hitung-ulang {periode}      izin komisi.kelola; 422 periode bila terkunci
POST /api/komisi/setujui {periode, catatan?} izin komisi.setujui; periode masa depan ditolak
```

Izin baru `komisi.kelola`, `komisi.setujui` (migration memberi ke `manajer`). Pengaturan `komisi.dasar`.

## Frontend

- Modal catatan tindakan (tab Catatan): **Petugas tambahan** + peran.
- `/keuangan/komisi` (`kasir/KomisiView`, menu Keuangan → Komisi): rekap per bulan, hitung ulang, setujui & kunci, slip per petugas
  (cetak), tab Aturan komisi (CRUD).
- `/komisi-saya` (menu Beranda → Komisi Saya, petugas medis): slip sendiri per bulan (`components/komisi/SlipKomisi.vue`).
- Pengaturan → Keuangan & Kasir: dasar komisi persen.

## Test

`tests/Feature/KomisiTest.php` — split dokter (10%) + dua asisten berbagi nominal, rekap & slip & izin; aturan paling spesifik + dasar
setelah diskon; sesi paket memakai nilai per sesi & penjualan paket tanpa komisi; setujui mengunci & refund masuk periode berikutnya;
hitung ulang memakai aturan terbaru & idempoten; petugas tambahan harus petugas medis & terkunci setelah pemeriksaan ditutup.
