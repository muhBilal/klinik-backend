# F1-02 — Booking & Penjadwalan

**PRD:** BK-01 (kalender multi-resource), BK-02 (durasi slot dari treatment + buffer), BK-03 (jadwal praktik, shift & cuti),
AN-01 (tahap booking), temuan teknis 8.3 #4 · **Fase:** 1 · **Status:** selesai untuk kalender, slot, jadwal & check-in.
Belum: booking online pasien (BK-04, Fase 2), DP booking (BK-05, Fase 2), reminder WhatsApp (BK-06 → butuh kredensial
WhatsApp Business API), waiting list (BK-07, Fase 3).

## Konsep

`appointments` **terpisah** dari `kunjungans`. Kunjungan tetap berarti "pasien yang hadir hari ini" (nomor antrian,
rekam medis, tagihan), sedangkan booking boleh bertanggal kapan saja. Check-in mengubah booking menjadi kunjungan —
inilah yang menutup temuan 8.3 #4 tanpa mengubah arti tabel lama.

## Tabel

| Tabel | Isi |
|-------|-----|
| `appointments` | booking: pasien, poli, petugas, `mulai_at`/`selesai_at`, status, `kunjungan_id` (terisi saat check-in) |
| `appointment_tindakans` | treatment yang dibooking; `durasi_menit` & `buffer_menit` di-**snapshot** |
| `appointment_sumber_dayas` | ruang/alat yang dipesan booking itu |
| `sumber_dayas` | ruang & alat per cabang (`tipe` = ruang / alat) |
| `tindakan_sumber_dayas` | ruang/alat yang boleh dipakai suatu treatment; kosong = tidak butuh |
| `jadwal_praktiks` | pola mingguan per petugas per cabang (`hari` 0–6 mengikuti `Carbon::dayOfWeek`) |
| `jadwal_pengecualians` | `cuti` (jam null = sehari penuh) & `tambahan` (jadwal di luar pola) |

Snapshot durasi disengaja: mengubah durasi treatment di katalog tidak boleh menggeser booking yang sudah dibuat.

## Aturan

- **Panjang slot** = Σ (`durasi_menit` + `buffer_menit`) treatment yang dipilih. Klien tidak mengirim `selesai_at`.
- **Jam kerja efektif** satu petugas pada satu tanggal = pola mingguan ∪ jadwal tambahan, lalu **dikurangi** cuti.
  Cuti sehari penuh mengosongkan hari itu termasuk jadwal tambahannya.
- **Bentrok** diperiksa untuk petugas **dan** setiap ruang/alat. Yang dihitung hanya booking berstatus
  `dijadwalkan`, `dikonfirmasi`, `hadir` (lihat `StatusAppointment::aktif()`), sehingga booking batal membebaskan slot.
- Booking di luar jam praktik petugas ditolak.
- **Check-in** hanya untuk booking hari ini dan yang sudah punya poli. Treatment yang dibooking disalin ke
  `kunjungan_tindakans` dengan **tarif cabang saat check-in** (bukan tarif saat booking dibuat).
- Booking yang sudah `hadir` / `batal` / `tidak_hadir` tidak bisa diubah lagi.
- `tidak_hadir` hanya untuk booking yang jadwalnya sudah lewat.

## Status

`dijadwalkan` → `dikonfirmasi` → `hadir` · `batal` · `tidak_hadir` (no-show, dasar KPI di PRD bagian 2).

## Izin

| Izin | Untuk |
|------|-------|
| `booking.lihat` | kalender & daftar booking, slot, jadwal, ruang/alat |
| `booking.kelola` | buat, ubah, konfirmasi, batal, tidak hadir, check-in |
| `jadwal.kelola` | jadwal praktik, cuti, master ruang & alat |

Peran bawaan: pendaftaran (lihat + kelola), perawat/dokter/terapis (lihat), manajer (lihat + jadwal).

## Endpoint

```
GET    /api/appointments               ?dari=&sampai=&petugas_id=&poli_id=&status=&q=
GET    /api/appointments/{id}
GET    /api/appointments-slot          ?petugas_id=&tanggal=&tindakan_ids[]=&sumber_daya_ids[]=
POST   /api/appointments               pasien_id, poli_id?, petugas_id?, mulai_at, tindakan_ids[], sumber_daya_ids[]?
PUT    /api/appointments/{id}          field yang dikirim saja
POST   /api/appointments/{id}/konfirmasi
POST   /api/appointments/{id}/batal            alasan_batal?
POST   /api/appointments/{id}/tidak-hadir
POST   /api/appointments/{id}/checkin          -> { appointment, kunjungan }
DELETE /api/appointments/{id}

GET    /api/jadwals                    ?user_id=      -> { praktiks, pengecualians }
POST   /api/jadwals                    user_id, hari, jam_mulai, jam_selesai
PUT    /api/jadwals/{id}
DELETE /api/jadwals/{id}
POST   /api/jadwal-pengecualians       user_id, tanggal, tipe, jam_mulai?, jam_selesai?, keterangan?
DELETE /api/jadwal-pengecualians/{id}

GET    /api/sumber-dayas               ?tipe=&status=&q=
POST   /api/sumber-dayas               kode, nama, tipe
PUT    /api/sumber-dayas/{id}
DELETE /api/sumber-dayas/{id}
```

`appointments-slot` mengembalikan `{ durasi_menit, jam_kerja[], slot[] }`. Slot ditawarkan tiap 15 menit
(`JadwalService::LANGKAH_MENIT`) dan slot yang sudah lewat dibuang.

## Kode

- `app/Services/JadwalService.php` — jam kerja efektif (gabung/kurangi rentang), generator slot, deteksi bentrok.
- `app/Services/BookingService.php` — buat, ubah, konfirmasi, batal, no-show, check-in.
- `app/Http/Controllers/Api/{AppointmentController, JadwalController, SumberDayaController}.php`

## Data demo

5 sumber daya di cabang utama (3 ruang, 2 alat). Jadwal praktik dokter & terapis: Senin–Jumat 09:00–17:00,
Sabtu 09:00–13:00.

## Belum dikerjakan

- Kebutuhan ruang/alat **wajib** per treatment: tabel `tindakan_sumber_dayas` sudah ada tetapi belum diisi lewat UI
  katalog, dan booking belum memaksa memilih ruang yang kompatibel.
- Reminder H-1 & 2 jam (BK-06) — butuh job + kredensial WhatsApp Business API.
- Booking online pasien & DP (BK-04, BK-05).

## Test

`tests/Feature/BookingTest.php` — slot mengikuti jadwal & durasi treatment, cuti sehari penuh mengosongkan slot,
bentrok petugas & ruang, booking di luar jadwal ditolak, check-in membuat kunjungan + nomor antrian + tindakan,
batal membebaskan slot, pemisahan izin booking vs jadwal, jadwal praktik tidak boleh beririsan.
