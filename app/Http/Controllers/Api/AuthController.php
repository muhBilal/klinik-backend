<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cabang;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PengaturanService;
use App\Services\TwoFactorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Tantangan 2FA berlaku 5 menit dan hangus setelah 5 kali kode salah. */
    private const TANTANGAN_MENIT = 5;

    private const TANTANGAN_MAKS_GAGAL = 5;

    public function __construct(private AuditService $audit) {}

    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            $this->audit->catat('login_gagal', 'user', $user?->id, [
                'user_id' => $user?->id, 'cabang_id' => $user?->cabang_id, 'label' => $credentials['email'],
            ]);

            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Akun Anda dinonaktifkan. Hubungi administrator.']);
        }

        $device = $credentials['device_name'] ?? 'spa';

        // Langkah kedua: token baru diterbitkan setelah kode authenticator diverifikasi di POST /login/2fa.
        if ($user->twoFactorAktif()) {
            $tantangan = Str::random(64);
            Cache::put($this->kunciTantangan($tantangan), ['user_id' => $user->id, 'device' => $device, 'gagal' => 0],
                now()->addMinutes(self::TANTANGAN_MENIT));

            return response()->json(['two_factor' => true, 'tantangan' => $tantangan]);
        }

        return $this->terbitkanToken($user, $device);
    }

    public function login2fa(Request $request, TwoFactorService $twoFactor): JsonResponse
    {
        $data = $request->validate([
            'tantangan' => ['required', 'string', 'size:64'],
            'kode' => ['required', 'string', 'max:20'],
        ]);

        $kunci = $this->kunciTantangan($data['tantangan']);
        $sesi = Cache::get($kunci);
        $user = $sesi ? User::find($sesi['user_id']) : null;

        if (! $user || ! $user->is_active) {
            throw ValidationException::withMessages(['tantangan' => 'Sesi login kedaluwarsa. Silakan login ulang.']);
        }

        if (! $twoFactor->verifikasiUser($user, $data['kode'])) {
            $this->audit->catat('login_gagal', 'user', $user->id, [
                'user_id' => $user->id, 'cabang_id' => $user->cabang_id, 'label' => "{$user->email} (kode 2FA salah)",
            ]);

            $sesi['gagal']++;
            $sesi['gagal'] >= self::TANTANGAN_MAKS_GAGAL
                ? Cache::forget($kunci)
                : Cache::put($kunci, $sesi, now()->addMinutes(self::TANTANGAN_MENIT));

            throw ValidationException::withMessages(['kode' => 'Kode verifikasi salah.']);
        }

        Cache::forget($kunci);

        return $this->terbitkanToken($user, $sesi['device']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->audit->catat('logout', 'user', $user->id, ['label' => $user->email, 'cabang_id' => $user->cabang_id]);
        $user->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil logout.']);
    }

    private function terbitkanToken(User $user, string $device): JsonResponse
    {
        $menit = (int) config('sanctum.expiration');
        $token = $user->createToken($device, ['*'], $menit > 0 ? now()->addMinutes($menit) : null)->plainTextToken;

        $this->audit->catat('login', 'user', $user->id, [
            'user_id' => $user->id, 'cabang_id' => $user->cabang_id,
            'label' => $user->email.($user->twoFactorAktif() ? ' (2FA)' : ''),
        ]);

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    /**
     * Bentuk user yang dipakai frontend: data akun + izin efektif + cabang yang boleh dipilih + status 2FA.
     */
    public static function userPayload(User $user): array
    {
        $user->load(['poli:id,kode,nama', 'cabang:id,kode,nama', 'peran.izins']);

        return [
            ...$user->toArray(),
            'role_label' => $user->peran?->nama ?? $user->role,
            'izin' => $user->izin(),
            'tercatat_dokter' => $user->tercatatSebagaiDokter(),
            'cabangs' => $user->cabang_id
                ? [$user->cabang?->only(['id', 'kode', 'nama'])]
                : Cabang::where('is_active', true)->orderBy('nama')->get(['id', 'kode', 'nama']),
            'two_factor' => ['aktif' => $user->twoFactorAktif(), 'wajib' => $user->wajib2fa()],
            'sesi' => ['idle_timeout_menit' => (int) app(PengaturanService::class)->get('keamanan.idle_timeout_menit')],
        ];
    }

    private function kunciTantangan(string $tantangan): string
    {
        return 'login-2fa:'.hash('sha256', $tantangan);
    }
}
