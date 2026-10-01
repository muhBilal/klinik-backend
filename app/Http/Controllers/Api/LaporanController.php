<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LaporanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Laporan penjualan (PRD LP-02) & paket (LP-03), izin `laporan.keuangan`. Periode `?mulai=&selesai=` (default awal bulan s.d.
 * hari ini, paling lama 366 hari); cakupan = cabang aktif, atau semua cabang bila user lintas cabang tanpa pilihan cabang.
 */
class LaporanController extends Controller
{
    public function __construct(private LaporanService $service) {}

    public function penjualan(Request $request): JsonResponse
    {
        return response()->json($this->service->penjualan(...$this->periode($request)));
    }

    public function paket(Request $request): JsonResponse
    {
        return response()->json($this->service->paket(...$this->periode($request)));
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function periode(Request $request): array
    {
        $data = $request->validate([
            'mulai' => ['nullable', 'date_format:Y-m-d'],
            'selesai' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:mulai'],
        ]);

        $mulai = Carbon::parse($data['mulai'] ?? today()->startOfMonth());
        $selesai = Carbon::parse($data['selesai'] ?? today());

        if ($mulai->diffInDays($selesai) > 366) {
            throw ValidationException::withMessages(['selesai' => 'Periode laporan paling lama satu tahun.']);
        }

        return [$mulai, $selesai];
    }
}
