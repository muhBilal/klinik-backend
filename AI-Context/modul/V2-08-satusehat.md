# V2-08 — Integrasi SATUSEHAT (Kemenkes)

**PRD v2:** 5.14 SS-01..SS-05, PS-05 · **Status:** kode selesai & teruji dengan HTTP tiruan; **aktivasi menunggu kredensial** (Organization ID,
Client ID & Secret dari portal SATUSEHAT — mulai dari sandbox). Belum: SS-06 (addendum sebagai pembaruan resource), MedicationRequest racikan.

## Konfigurasi (SS-01)

`.env` (rahasia, tidak pernah di database / UI): `SATUSEHAT_AKTIF`, `SATUSEHAT_ENV` (sandbox|production), `SATUSEHAT_CLIENT_ID`,
`SATUSEHAT_CLIENT_SECRET`, `SATUSEHAT_ORGANIZATION_ID` (+ opsional `SATUSEHAT_AUTH_URL`, `SATUSEHAT_BASE_URL`, `SATUSEHAT_TIMEOUT`).
Base URL default: sandbox `https://api-satusehat-stg.dto.kemkes.go.id/{oauth2,fhir-r4}/v1`, produksi `https://api-satusehat.kemkes.go.id/...`.
Data master: `cabangs.satusehat_location_id` (Master Cabang), `users.nik` (Master Pengguna, dokter), `pasiens.nik`.

## Alur (SS-02..05)

1. RME ditandatangani (`PemeriksaanService::selesai`) → `SatuSehatService::antrekan` membuat/menyetel `satusehat_kirims` (unik per kunjungan)
   dan men-dispatch job `KirimSatuSehat` **setelah commit**. Integrasi nonaktif → tidak ada efek.
2. Job → `SatuSehatService::kirim`: token OAuth2 client-credentials (di-cache 50 menit; 401 → token baru sekali), IHS pasien
   (`GET /Patient?identifier=https://fhir.kemkes.go.id/id/nik|{NIK}`, disimpan `pasiens.ihs_id`), IHS praktisi (`/Practitioner`, disimpan
   `users.ihs_id`), Location cabang, lalu **Bundle transaksi** ke `POST {base}/` (`FhirMapper`):
   - Encounter (identifier `http://sys-ids.kemkes.go.id/encounter/{org}` = no. registrasi, class AMB, statusHistory arrived/in-progress/finished,
     participant ATND, location, diagnosis → Condition, serviceProvider Organization)
   - Condition per diagnosa ICD-10 (`http://hl7.org/fhir/sid/icd-10`, encounter-diagnosis)
   - Observation tanda vital: nadi 8867-4, respirasi 9279-1, suhu 8310-5, sistolik 8480-6 & diastolik 8462-4 (dari "120/80"), BB 29463-7,
     TB 8302-2 (LOINC + UCUM)
   - Procedure per tindakan ber-ICD-9-CM (`http://hl7.org/fhir/sid/icd-9-cm`)
   - MedicationRequest untuk obat jadi ber-`obats.kode_kfa` (KFA); racikan dilewati
3. Respons `entry[].response.location` → `hasil` {Resource: [id]}, `encounter_id`, `kunjungans.satusehat_encounter_id`, status `terkirim`.
4. **Galat data** (NIK kosong/tidak ditemukan, Location kosong, RME belum ditandatangani, 4xx) → `gagal` + pesan (OperationOutcome diringkas),
   tidak dicoba ulang otomatis. **Galat sementara** (jaringan, 5xx, 429) → tetap `menunggu`, job `release()` dengan jeda 60/300/900/3600 dtk
   (maks. 5 percobaan), lalu `gagal` (`failed()`). Job tidak pernah melempar ke request pemicunya.

## API (izin baru `integrasi.kelola`; admin)

```
GET  /api/satusehat/status            aktif, env, terkonfigurasi, organization_id, per_status, kepatuhan_30_hari{ditandatangani, terkirim, persen}
GET  /api/satusehat/kirims?status=    antrean + kunjungan, pasien, dokter
POST /api/satusehat/kirims/{id}/ulang · /api/satusehat/kirim-ulang-gagal · /api/satusehat/tes-koneksi
POST /api/pasiens/{id}/satusehat      lookup IHS via NIK (PS-05) — pasien.kelola / integrasi.kelola
```

## Frontend

`/admin/integrasi` (`admin/IntegrasiView`, menu Administrasi → Integrasi) tab SATUSEHAT: status & langkah aktivasi, kepatuhan 30 hari
(target > 98%), antrean + galat + kirim ulang. Detail pasien: baris **IHS SATUSEHAT** + "cek via NIK". Master Cabang: Location ID.
Master Pengguna: NIK dokter.

## Test

`tests/Feature/SatuSehatTest.php` (Http::fake): bundle lengkap & header Bearer, IHS tersimpan, status & kepatuhan; galat Location → gagal →
kirim ulang; 503 → menunggu + `release(60)` tanpa menggagalkan pemeriksaan, percobaan habis → gagal; nonaktif → tanpa antrean; lookup IHS.
