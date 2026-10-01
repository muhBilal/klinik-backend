<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Observabilitas operasional (PRD v2 7.2): detak scheduler, antrean job, job gagal (lihat, coba ulang, hapus).
 * Payload job tidak dikirim utuh ke UI (bisa memuat data pasien) — hanya nama kelas & ringkasan galat.
 */
class SistemController extends Controller
{
    public function status(): JsonResponse
    {
        $detak = Cache::get('eklinik.scheduler.detak');
        $menitLalu = $detak ? (int) Carbon::parse($detak)->diffInMinutes(now()) : null;

        return response()->json([
            'scheduler' => ['detak_terakhir' => $detak, 'menit_lalu' => $menitLalu, 'sehat' => $menitLalu !== null && $menitLalu <= 5],
            'antrean' => [
                'menunggu' => DB::table('jobs')->count(),
                'tertunda' => DB::table('jobs')->where('available_at', '>', now()->getTimestamp())->count(),
                'gagal' => DB::table('failed_jobs')->count(),
            ],
            'versi' => ['php' => PHP_VERSION, 'laravel' => app()->version(), 'lingkungan' => app()->environment()],
        ]);
    }

    public function jobGagal(Request $request): JsonResponse
    {
        $rows = DB::table('failed_jobs')->orderByDesc('id')->limit(min($request->integer('per_page', 50), 200))
            ->get(['id', 'uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(fn ($r) => [
                'id' => $r->id,
                'uuid' => $r->uuid,
                'queue' => $r->queue,
                'job' => json_decode($r->payload, true)['displayName'] ?? '-',
                'galat' => mb_substr(strtok((string) $r->exception, "\n"), 0, 300),
                'failed_at' => $r->failed_at,
            ]);

        return response()->json($rows);
    }

    public function ulang(string $uuid): JsonResponse
    {
        abort_unless(DB::table('failed_jobs')->where('uuid', $uuid)->exists(), 404);
        Artisan::call('queue:retry', ['id' => [$uuid]]);

        return response()->json(['message' => 'Job dijadwalkan ulang.']);
    }

    public function hapus(string $uuid): JsonResponse
    {
        DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        return response()->json(['message' => 'Job gagal dihapus.']);
    }
}
