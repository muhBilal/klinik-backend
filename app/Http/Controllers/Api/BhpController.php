<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KunjunganTindakan;
use App\Services\BhpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Koreksi pemakaian BHP aktual per tindakan (PRD IN-02).
 */
class BhpController extends Controller
{
    public function __construct(private BhpService $service) {}

    public function index(KunjunganTindakan $kunjunganTindakan): JsonResponse
    {
        return response()->json(
            $kunjunganTindakan->bhps()->with('obat:id,kode,nama,satuan,fraksional')->get(),
        );
    }

    /** Replace-all: kirim seluruh baris pemakaian tindakan ini. */
    public function update(Request $request, KunjunganTindakan $kunjunganTindakan): JsonResponse
    {
        $data = $request->validate([
            'bhps' => ['present', 'array', 'max:50'],
            'bhps.*.obat_id' => ['required', 'distinct', Rule::exists('obats', 'id')->whereNull('deleted_at')],
            'bhps.*.jumlah' => ['required', 'numeric', 'min:0', 'max:99999', 'decimal:0,3'],
            'bhps.*.batch_id' => ['nullable', Rule::exists('stok_batches', 'id')],
        ]);

        return response()->json($this->service->koreksi($kunjunganTindakan, $data['bhps'], $request->user()));
    }
}
