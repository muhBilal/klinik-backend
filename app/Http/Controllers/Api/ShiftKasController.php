<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ShiftKas;
use App\Services\KasirService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shift kas kasir (PRD BL-05).
 */
class ShiftKasController extends Controller
{
    public function __construct(private KasirService $service) {}

    public function index(Request $request): JsonResponse
    {
        $shifts = ShiftKas::query()
            ->select(['id', 'cabang_id', 'kasir_id', 'dibuka_at', 'ditutup_at', 'modal_awal', 'kas_fisik', 'selisih', 'catatan'])
            ->with(['kasir:id,name', 'cabang:id,kode,nama'])
            ->when($request->filled('kasir_id'), fn ($q) => $q->where('kasir_id', $request->integer('kasir_id')))
            ->when($request->filled('tanggal'), fn ($q) => $q->whereDate('dibuka_at', $request->input('tanggal')))
            ->when($request->boolean('terbuka'), fn ($q) => $q->whereNull('ditutup_at'))
            ->latest('dibuka_at');

        return response()->json($this->paginate($shifts, $request));
    }

    /** Shift kasir yang login saat ini, beserta rekapnya. Null bila belum buka shift. */
    public function aktif(Request $request, CabangAktif $cabang): JsonResponse
    {
        $shift = $this->service->shiftTerbuka($cabang->untukDataBaru(), $request->user());

        return response()->json($shift ? $this->detail($shift) : null);
    }

    public function store(Request $request, CabangAktif $cabang): JsonResponse
    {
        $data = $request->validate(['modal_awal' => ['required', 'integer', 'min:0']]);

        $shift = $this->service->bukaShift($cabang->untukDataBaru(), $request->user(), $data['modal_awal']);

        return response()->json($this->detail($shift), 201);
    }

    public function show(ShiftKas $shiftKas): JsonResponse
    {
        return response()->json($this->detail($shiftKas));
    }

    public function tutup(Request $request, ShiftKas $shiftKas): JsonResponse
    {
        $data = $request->validate([
            'kas_fisik' => ['required', 'integer', 'min:0'],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);

        $shift = $this->service->tutupShift($shiftKas, $data['kas_fisik'], $data['catatan'] ?? null);

        return response()->json($this->detail($shift));
    }

    private function detail(ShiftKas $shift): array
    {
        return [
            ...$shift->load(['kasir:id,name', 'cabang:id,kode,nama'])->toArray(),
            'rekap' => $this->service->rekapShift($shift),
        ];
    }
}
