# V2-09 — WhatsApp: Reminder Booking & Follow-up

**PRD v2:** BK-06 (reminder H-1 & 2 jam dengan tombol konfirmasi/ubah jadwal), CR-01 (WhatsApp Business API: reminder, konfirmasi,
follow-up H+1/H+7) · **Status:** kode selesai & teruji dengan HTTP tiruan; **aktivasi menunggu kredensial** WhatsApp Cloud API + template
yang disetujui Meta. Belum: broadcast/CR-03 (butuh opt-in marketing → sudah tersedia di V2-05), fallback SMS/email.

## Konfigurasi

`.env`: `WHATSAPP_AKTIF`, `WHATSAPP_DRIVER` (`log` = hanya dicatat di log, untuk dev; `cloud` = WhatsApp Cloud API), `WHATSAPP_TOKEN`,
`WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_VERIFY_TOKEN`, `WHATSAPP_APP_SECRET` (+ `WHATSAPP_API_VERSION`, `WHATSAPP_TEMPLATE_LANGUAGE`).
Pengaturan (UI): `wa.reminder_h1`, `wa.jam_reminder_h1` (09:00), `wa.reminder_2jam`, `wa.followup_h1`, `wa.followup_h7`,
`wa.template_reminder` (`pengingat_booking`), `wa.template_followup` (`tindak_lanjut_perawatan`).

Template Meta yang perlu dibuat:
- **pengingat**: `{{1}}` nama pasien, `{{2}}` hari & jam ("Selasa, 6 Okt pukul 14:00"), `{{3}}` treatment, `{{4}}` cabang;
  dua tombol balasan cepat: "Konfirmasi", "Ubah jadwal" (payload diisi sistem: `KONFIRMASI:{id}`, `UBAH:{id}`).
- **tindak lanjut**: `{{1}}` nama, `{{2}}` treatment, `{{3}}` hari ke- (1/7), `{{4}}` nama klinik.

## Alur

- `php artisan eklinik:wa-jadwal` (scheduler tiap 10 menit, `withoutOverlapping`): reminder H-1 untuk booking besok setelah jam yang diatur,
  reminder ±2 jam (jendela 90–150 menit), follow-up H+1/H+7 untuk kunjungan yang ditutup dan berisi tindakan. Hanya booking
  `dijadwalkan`/`dikonfirmasi` dan pasien ber-no. HP valid (`WhatsAppService::nomor`: 0812… → 62812…). **Sekali** per jenis per
  booking/kunjungan (unik di DB). Pesan layanan, tidak bergantung opt-in marketing.
- Outbox `pesan_whatsapps` → job `KirimWhatsApp` → `WhatsAppGateway` (`LogGateway` / `CloudGateway`: `POST {graph}/{versi}/{phone_id}/messages`
  dengan komponen body & tombol quick-reply). Booking yang sudah batal/hadir saat dikirim → tidak dikirim. Galat sementara → `antre` + release
  (60/300/900 dtk), galat permanen → `gagal`.
- **Webhook** publik `GET/POST /api/webhook/whatsapp`: GET verifikasi `hub.verify_token`; POST wajib `X-Hub-Signature-256` HMAC-SHA256
  (`WHATSAPP_APP_SECRET`). Status sent/delivered/read/failed → `terkirim/diterima/dibaca/gagal` (tidak mundur). Tombol `KONFIRMASI:{id}` →
  booking `dikonfirmasi` (`dikonfirmasi_via = wa`); `UBAH:{id}` → `appointments.minta_ubah_at` (badge "UBAH?" di kalender; hilang saat
  staf mengubah booking). Balasan hanya diterima dari nomor pasien pemilik booking.

## API (izin `integrasi.kelola`)

```
GET  /api/whatsapp/status       aktif, driver, terkonfigurasi, webhook_siap, per_status_7_hari
GET  /api/whatsapp/pesan        ?status=&jenis=
POST /api/whatsapp/pesan/{id}/ulang · /api/whatsapp/jadwalkan (jalankan penjadwal sekarang)
```

## Frontend

`/admin/integrasi` tab WhatsApp (status, statistik 7 hari, jalankan penjadwal, daftar pesan + balasan + kirim ulang); Pengaturan → kartu
**WhatsApp Otomatis**; kalender booking: badge "UBAH?" & info konfirmasi via WhatsApp di detail.

## Test

`tests/Feature/WhatsAppTest.php`: penjadwal (H-1 setelah jam, 2 jam, follow-up H+1, tidak dobel, booking batal & pasien tanpa HP dilewati);
nonaktif; driver cloud (payload template + tombol, galat 400 → gagal); webhook (tanda tangan, status tidak mundur, tombol dari nomor lain
diabaikan, konfirmasi & ubah jadwal); verifikasi webhook & pemantauan admin.
