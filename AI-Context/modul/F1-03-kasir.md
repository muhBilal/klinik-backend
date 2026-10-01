# F1-03 — Kasir: split payment, shift kas, void & refund

**PRD:** BL-02 (batas diskon per peran), BL-03 (split payment), BL-05 (shift kas), BL-06 (void & refund),
FR-04 (penjualan produk tanpa resep), AD-04 (pajak), temuan teknis 8.3 #3 & #7 · **Fase:** 1 · **Status:** selesai.
Belum: cicilan paket (BL-04, Fase 2), invoice PDF/WA (BL-07, Fase 2), payment gateway (BL-08 → butuh merchant account).

## Perubahan struktur (temuan 8.3 #3)

`tagihans.kunjungan_id` **tidak lagi unique** dan boleh null; `reseps.kunjungan_id` juga tidak lagi unique. Akibatnya:

- satu kunjungan boleh punya beberapa tagihan (mis. tagihan tambahan setelah tindakan) dan beberapa resep;
- tagihan boleh berdiri sendiri tanpa kunjungan — penjualan produk OTC, paket, deposit.

Tagihan mandiri menyimpan `pasien_id` langsung. `Tagihan::pasienId()` mengembalikan pasien dari kolom itu atau dari
kunjungannya. Relasi `Kunjungan::tagihan()` / `resep()` kini mengambil baris **terbaru yang bukan batal**
(bukan `latestOfMany()` — join subquery-nya membuat kolom di eager-load select jadi ambigu).

## Tabel

| Tabel | Isi |
|-------|-----|
| `pembayarans` | satu baris per metode bayar; refund menandai `dikembalikan_at`, bukan menghapus baris |
| `shift_kas` | buka/tutup shift kasir, modal awal, kas fisik, selisih |

Kolom baru `tagihans`: `pasien_id`, `shift_id`, `pajak`, `pajak_persen`, `keterangan`, `dibatalkan_at`,
`dibatalkan_oleh`, `alasan_batal`, `deleted_at`. Kolom baru `reseps`: `dibatalkan_at`, `dibatalkan_oleh`, `alasan_batal`.

## Aturan

- **Split payment**: `pembayarans[]` boleh berisi beberapa metode. Hanya **tunai** yang boleh berlebih (jadi kembalian);
  total non-tunai tidak boleh melebihi tagihan. Bila hanya satu metode, kolom lama `metode_bayar` tetap diisi;
  lebih dari satu metode → `metode_bayar` null.
- **Item konsultasi** = treatment jasa konsultasi poli (`tindakan_id` terisi, harga cabang; F1-09) — bisa jadi target promo per treatment.
- **Tagihan Rp 0** (mis. seluruhnya sesi paket, atau lunas oleh diskon/promo) boleh dilunasi **tanpa** pembayaran (F1-09) — agar kunjungan
  selesai dan masuk rekap komisi. Tagihan di atas Rp 0 tetap wajib minimal satu pembayaran.
- **Pajak** (AD-04): tarif dari pengaturan `keuangan.pajak_persen`, **di-snapshot** ke `tagihans.pajak_persen` saat
  tagihan dibuat. Mengubah tarif tidak mengubah tagihan lama. Pajak dihitung dari nilai *setelah* diskon.
- **Batas diskon** (BL-02): pengaturan `keuangan.batas_diskon_persen` = `{kode_peran: persen}`. Bersifat **opt-in** —
  peran yang tidak tercantum tidak dibatasi, sehingga klinik yang belum mengaturnya berjalan seperti sebelumnya.
  Persen `0` berarti dilarang memberi diskon. Peran `akses_penuh` tidak pernah dibatasi.
- **Void** (`/batal`) hanya untuk tagihan **belum bayar**; **refund** hanya untuk tagihan **lunas**. Keduanya butuh
  izin `kasir.void` yang sengaja tidak diberikan ke kasir biasa (perlu persetujuan manajer/admin).
- Refund menandai seluruh pembayaran sebagai dikembalikan, mengembalikan kunjungan ke `menunggu_pembayaran`,
  dan tidak lagi dihitung di rekap shift (tetapi muncul sebagai `total_refund`).
- **Kunjungan selesai** hanya bila tidak ada lagi tagihannya yang berstatus belum bayar.
- **Shift kas**: satu kasir hanya boleh punya satu shift terbuka per cabang. Rekap tunai = baris pembayaran tunai − kembalian tagihan
  (baris menyimpan uang yang diserahkan pasien; diperbaiki F1-11 — sebelumnya kas seharusnya kelebihan sebesar kembalian).
  `selisih = kas_fisik − (modal_awal + tunai berlaku)`; negatif berarti uang kurang.

## Izin

| Izin | Untuk |
|------|-------|
| `kasir.tagihan` | tagihan & pembayaran (sudah ada) |
| `kasir.void` | batalkan tagihan & refund |
| `kasir.shift` | buka & tutup shift kas |

Peran bawaan: kasir mendapat `kasir.shift`, manajer mendapat `kasir.void`.

## Endpoint

```
POST /api/tagihans                     pasien_id?, keterangan?, items[]{kategori,deskripsi,jumlah,harga}
POST /api/tagihans/{id}/bayar          pembayarans[]{metode,jumlah,referensi?}, diskon?
                                       (bentuk lama metode_bayar + dibayar tetap diterima)
POST /api/tagihans/{id}/batal          alasan_batal            izin kasir.void
POST /api/tagihans/{id}/refund         alasan_refund           izin kasir.void
POST /api/reseps/{id}/batal            alasan_batal

GET  /api/shift-kas                    ?kasir_id=&tanggal=&terbuka=1
GET  /api/shift-kas/aktif              shift kasir yang login (null bila belum buka)
GET  /api/shift-kas/{id}
POST /api/shift-kas                    modal_awal
POST /api/shift-kas/{id}/tutup         kas_fisik, catatan?
```

Respons shift menyertakan `rekap`: `per_metode[]`, `total`, `total_refund`, `kas_seharusnya`.

## Paket & promo (F1-08)

- `bayar()` menghitung ulang potongan kode promo terpasang (dikunci, kuota bisa habis → 422 `kode`), menyimpan `diskon_promo`, mencatat
  pemakaian promo, dan mengaktifkan paket yang dijual lewat tagihan itu. Batas diskon per peran hanya untuk diskon manual.
- `batal()` → paket `menunggu_bayar` jadi `dibatalkan`. `refund()` → ditolak bila paket tagihan itu sudah dipakai; kuota promo kembali.
- Rekap shift: `refund_paket` (refund sisa paket di shift ini), `total_refund` termasuk refund paket, `kas_seharusnya` & `selisih`
  dikurangi refund paket tunai.

## Kompatibilitas

API bayar lama (`metode_bayar` + `dibayar`) sengaja dipertahankan supaya frontend tidak perlu diubah serentak.
Pesan error nominal tetap memakai kunci `dibayar` pada bentuk lama, dan `pembayarans` pada bentuk baru.

## Kode

- `app/Services/KasirService.php` — shift, split payment, batas diskon, void, refund, hitung grand total & pajak.
- `app/Services/TagihanService.php` — `buatDariKunjungan()` (kini dengan pajak) & `buatMandiri()` (tagihan tanpa kunjungan).
- `app/Http/Controllers/Api/{TagihanController, ShiftKasController}.php`

### Revisi 1 Okt 2026 (paket dari pemeriksaan)
- Saat lunas, neto tiap baris disimpan (`tagihan_items.neto`): potongan promo hanya ke baris yang memenuhi syarat, diskon manual sebanding
  sisa (pembulatan sisa terbesar). Dipakai nilai paket (F1-08), dasar komisi neto (F1-09), dan laporan per item (F1-11).
- Tagihan kunjungan bisa memuat baris paket yang dipesan di pemeriksaan; **Batalkan paket** (`DELETE /tagihans/{id}/pakets/{paketPasien}`,
  `kasir.tagihan`) menyusun ulang tagihan belum bayar (`TagihanService::susunUlang`; promo dihitung ulang, dilepas bila tidak memenuhi syarat).

## Test

`tests/Feature/KasirTest.php` — tagihan mandiri, split payment, non-tunai tidak boleh berlebih, pajak di-snapshot,
batas diskon per peran, admin tidak dibatasi, rekap & selisih shift, void butuh izin, refund tidak dihitung di rekap,
refund hanya untuk tagihan lunas, satu kunjungan beberapa tagihan.
