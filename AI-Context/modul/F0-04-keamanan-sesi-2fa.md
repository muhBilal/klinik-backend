# F0-04 — Keamanan Sesi & Autentikasi Dua Langkah (2FA)

**PRD:** 7.2 Keamanan (2FA untuk admin & dokter, session timeout 15 menit di perangkat bersama, rate limiting login) ·
**Fase:** 0 · **Status:** selesai. Temuan gap 8.3 #1 (token tidak pernah kedaluwarsa) ditutup.

## Masa berlaku token

| Batas | Nilai | Tempat |
|-------|-------|--------|
| Absolut sejak login | `SANCTUM_EXPIRATION` menit (default **720** = 12 jam) | `config/sanctum.php` + `expires_at` token |
| Idle (tidak dipakai) | pengaturan `keamanan.idle_timeout_menit` (default **15**, 5–480) | `Sanctum::authenticateAccessTokensUsing` di AppServiceProvider |
| Pemilik nonaktif / dihapus | langsung tidak berlaku | callback yang sama (`tokenable->is_active`, soft delete) |

Sanctum memperbarui `last_used_at` setiap request. Token kedaluwarsa dibersihkan scheduler (`sanctum:prune-expired --hours=24`).
Frontend juga mengakhiri sesi setelah N menit tanpa interaksi (`useIdle` di `AppLayout`), karena auto-refresh antrian (30 dtk)
membuat token tetap "dipakai" walau layar ditinggal.

Token dicabut paksa saat: logout, ganti password sendiri (semua token lain), admin mengubah peran/status/cabang/password
pengguna, pengguna dihapus.

## 2FA (TOTP)

`App\Services\TwoFactorService` — implementasi RFC 6238 sendiri (HMAC-SHA1, 6 digit, periode 30 dtk, toleransi ±1 langkah),
kompatibel Google/Microsoft Authenticator & Authy. Diuji dengan vektor RFC di `tests/Unit/TwoFactorServiceTest.php`.

Kolom `users`: `two_factor_secret` (cast `encrypted`), `two_factor_recovery_codes` (`encrypted:array`, 8 kode `xxxxx-xxxxx`),
`two_factor_confirmed_at`, `two_factor_last_step` (anti replay: kode langkah yang sudah dipakai ditolak).

### Aktivasi (profil)
1. `POST /me/2fa` → `{ secret, otpauth_url }` (secret tersimpan, belum aktif).
2. `POST /me/2fa/konfirmasi { kode }` → aktif, `{ kode_pemulihan }` ditampilkan **sekali**.
3. `POST /me/2fa/kode-pemulihan { password }` → kode baru (lama hangus); `DELETE /me/2fa { password }` → nonaktif.

### Login dua langkah
1. `POST /login` benar + 2FA aktif → `{ two_factor: true, tantangan }` (tanpa token). Tantangan disimpan di cache
   (`login-2fa:sha256(tantangan)`) 5 menit.
2. `POST /login/2fa { tantangan, kode }` → token. Kode = TOTP atau kode pemulihan (sekali pakai, tidak peka huruf besar).
   Salah → 422 `kode` + audit `login_gagal`; 5 kali salah → tantangan hangus (422 `tantangan`). Throttle 6/menit per IP.

### Wajib 2FA per peran
Pengaturan `keamanan.wajib_2fa` (array kode peran, default kosong; PRD menyarankan `["admin","dokter"]`). Middleware `wajib2fa`
membalas 403 `{ kode: 'wajib_2fa' }` untuk semua route kecuali grup profil (`me`, `me/password`, `me/2fa*`, `logout`).
`/me` → `two_factor: { aktif, wajib }`.

## Ganti password

`PUT /me/password { password_lama, password, password_confirmation }` — min. 8, harus berbeda; token lain dicabut; audit `ubah_password`.

## Rate limit

`/login` 10/menit, `/login/2fa` 6/menit (per IP, `throttle`).

## Frontend

- `LoginView`: langkah kedua (input kode/kode pemulihan), pesan "Sesi Anda telah berakhir" (`/login?sesi=habis` dari interceptor 401).
- `ProfilView` (`/profil`, klik avatar di header): info akun, ganti password, aktivasi 2FA dengan QR code (paket npm `qrcode`)
  + kunci manual, tampilan kode pemulihan sekali, buat kode baru, nonaktifkan.
- Router: `auth.perlu2fa` → semua halaman dialihkan ke `/profil`; interceptor 403 `wajib_2fa` → `/profil`.
- `AppLayout`: `useIdle(me.sesi.idle_timeout_menit)` → logout + toast.

## Catatan & batasan

- Token masih disimpan di `localStorage` (keputusan lama, risiko XSS; jangan pernah `v-html` data user). Pindah ke Sanctum SPA
  cookie mode bisa dipertimbangkan bila frontend & API satu domain di produksi.
- Secret 2FA terenkripsi dengan `APP_KEY` — mengganti `APP_KEY` tanpa `APP_PREVIOUS_KEYS` membuat 2FA semua user tidak bisa dipakai.
- Admin belum bisa me-reset 2FA pengguna lain dari UI (pengguna yang kehilangan ponsel memakai kode pemulihan). Kandidat backlog.

## Test

`tests/Feature/KeamananTest.php` — idle & masa berlaku token, idle sesuai pengaturan & user nonaktif, alur 2FA lengkap
(aktivasi, login dua langkah, tantangan sekali pakai, kode pemulihan sekali pakai, nonaktifkan), wajib 2FA, ganti password.
