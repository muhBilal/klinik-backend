<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Keamanan akun milik user yang sedang login: ganti password & autentikasi dua langkah (2FA).
 * Route-nya berada di luar middleware `wajib2fa` agar user yang wajib 2FA tetap bisa mengaktifkannya.
 */
class ProfilController extends Controller
{
    public function __construct(private AuditService $audit, private TwoFactorService $twoFactor) {}

    public function ubahPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password_lama' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', 'different:password_lama', Password::min(8)],
        ]);

        $user = $request->user();
        $this->pastikanPassword($user->password, $data['password_lama'], 'password_lama');

        $user->update(['password' => $data['password']]);

        // Sesi di perangkat lain dicabut; sesi saat ini tetap.
        $saatIni = $user->currentAccessToken();
        $user->tokens()
            ->when($saatIni instanceof PersonalAccessToken && $saatIni->exists, fn ($q) => $q->whereKeyNot($saatIni->getKey()))
            ->delete();

        $this->audit->catat('ubah_password', 'user', $user->id, ['label' => $user->email]);

        return response()->json(['message' => 'Password berhasil diubah. Sesi di perangkat lain telah diakhiri.']);
    }

    /** Langkah 1: buat secret baru (belum aktif sampai dikonfirmasi dengan kode dari aplikasi authenticator). */
    public function mulai2fa(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_if($user->twoFactorAktif(), 422, 'Autentikasi dua langkah sudah aktif.');

        $secret = $this->twoFactor->buatSecret();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        return response()->json([
            'secret' => $secret,
            'otpauth_url' => $this->twoFactor->otpauthUrl($user, $secret),
        ]);
    }

    /** Langkah 2: konfirmasi kode pertama, 2FA aktif, kode pemulihan ditampilkan SEKALI. */
    public function konfirmasi2fa(Request $request): JsonResponse
    {
        $data = $request->validate(['kode' => ['required', 'string', 'max:10']]);
        $user = $request->user();

        abort_if($user->twoFactorAktif() || blank($user->two_factor_secret), 422, 'Mulai pengaturan 2FA terlebih dahulu.');

        $langkah = $this->twoFactor->cocokkan($user->two_factor_secret, $data['kode']);

        if ($langkah === null) {
            throw ValidationException::withMessages(['kode' => 'Kode salah. Pastikan jam di perangkat Anda sudah tepat.']);
        }

        $kodes = $this->twoFactor->buatKodePemulihan();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_last_step' => $langkah,
            'two_factor_recovery_codes' => $kodes,
        ])->save();

        $this->audit->catat('2fa_aktif', 'user', $user->id, ['label' => $user->email]);

        return response()->json(['kode_pemulihan' => $kodes]);
    }

    public function kodePemulihanBaru(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();

        abort_unless($user->twoFactorAktif(), 422, 'Autentikasi dua langkah belum aktif.');
        $this->pastikanPassword($user->password, $data['password']);

        $kodes = $this->twoFactor->buatKodePemulihan();
        $user->forceFill(['two_factor_recovery_codes' => $kodes])->save();

        $this->audit->catat('2fa_kode_baru', 'user', $user->id, ['label' => $user->email]);

        return response()->json(['kode_pemulihan' => $kodes]);
    }

    public function nonaktifkan2fa(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();

        $this->pastikanPassword($user->password, $data['password']);

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();

        $this->audit->catat('2fa_nonaktif', 'user', $user->id, ['label' => $user->email]);

        return response()->json(['message' => 'Autentikasi dua langkah dinonaktifkan.']);
    }

    private function pastikanPassword(string $hash, string $password, string $field = 'password'): void
    {
        if (! Hash::check($password, $hash)) {
            throw ValidationException::withMessages([$field => 'Password salah.']);
        }
    }
}
