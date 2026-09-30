<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pemakaian: ->middleware('izin:pemeriksaan.vital,pemeriksaan.dokter') — lolos bila punya SALAH SATU izin.
 * Peran berakses penuh (administrator) selalu lolos.
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$izin): Response
    {
        $user = $request->user();

        if (! $user || ! $user->punyaIzin(...$izin)) {
            abort(403, 'Anda tidak memiliki akses ke fitur ini.');
        }

        return $next($request);
    }
}
