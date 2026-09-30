<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Peran yang tercantum di pengaturan `keamanan.wajib_2fa` harus mengaktifkan 2FA sebelum memakai fitur lain.
 * Route profil (`me`, `me/2fa`, `me/password`, `logout`) sengaja berada di luar middleware ini.
 */
class EnsureTwoFactorEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->wajib2fa() && ! $user->twoFactorAktif()) {
            return response()->json([
                'message' => 'Peran Anda wajib memakai autentikasi dua langkah (2FA). Aktifkan di halaman Profil terlebih dahulu.',
                'kode' => 'wajib_2fa',
            ], 403);
        }

        return $next($request);
    }
}
