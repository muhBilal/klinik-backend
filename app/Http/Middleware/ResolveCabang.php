<?php

namespace App\Http\Middleware;

use App\Models\Cabang;
use App\Support\CabangAktif;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Menentukan cabang aktif untuk request (lihat App\Support\CabangAktif).
 * User terikat cabang tidak bisa berpindah cabang lewat header; header dari user lintas cabang yang
 * menunjuk cabang tidak dikenal/nonaktif diabaikan (tampilan semua cabang).
 */
class ResolveCabang
{
    public const HEADER = 'X-Cabang-Id';

    public function __construct(private CabangAktif $cabang) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->cabang_id) {
            $this->cabang->set($user->cabang_id);
        } else {
            $header = (int) $request->header(self::HEADER);
            $this->cabang->set($header > 0 ? Cabang::where('is_active', true)->whereKey($header)->value('id') : null);
        }

        return $next($request);
    }
}
