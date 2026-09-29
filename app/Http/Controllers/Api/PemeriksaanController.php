<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Kunjungan;
use App\Services\PemeriksaanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PemeriksaanController extends Controller
{
    public function __construct(private PemeriksaanService $service) {}

    public function update(Request $request, Kunjungan $kunjungan): JsonResponse
    {
        $data = $request->validate([
            'tekanan_darah' => ['nullable', 'string', 'regex:/^\d{2,3}\/\d{2,3}$/'],
            'nadi' => ['nullable', 'integer', 'between:20,250'],
            'suhu' => ['nullable', 'numeric', 'between:30,45'],
            'respirasi' => ['nullable', 'integer', 'between:5,80'],
            'berat_badan' => ['nullable', 'numeric', 'between:0.5,400'],
            'tinggi_badan' => ['nullable', 'numeric', 'between:20,250'],
            'subjektif' => ['nullable', 'string', 'max:5000'],
            'objektif' => ['nullable', 'string', 'max:5000'],
            'asesmen' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:5000'],

            'diagnosas' => ['sometimes', 'array'],
            'diagnosas.*.icd10_id' => ['required', 'distinct', 'exists:icd10s,id'],
            'diagnosas.*.jenis' => ['nullable', Rule::in(['primer', 'sekunder'])],

            'tindakans' => ['sometimes', 'array'],
            'tindakans.*.tindakan_id' => ['required', Rule::exists('tindakans', 'id')->where('is_active', true)],
            'tindakans.*.jumlah' => ['nullable', 'integer', 'min:1', 'max:100'],
            'tindakans.*.keterangan' => ['nullable', 'string', 'max:255'],

            'resep' => ['sometimes', 'array'],
            'resep.*.obat_id' => ['required', 'distinct', Rule::exists('obats', 'id')->where('is_active', true)],
            'resep.*.jumlah' => ['required', 'integer', 'min:1', 'max:1000'],
            'resep.*.aturan_pakai' => ['required', 'string', 'max:255'],
            'catatan_resep' => ['nullable', 'string', 'max:1000'],
        ], [
            'tekanan_darah.regex' => 'Format tekanan darah harus sistolik/diastolik, contoh 120/80.',
        ]);

        return response()->json($this->service->simpan($kunjungan, $data, $request->user()));
    }

    public function selesai(Request $request, Kunjungan $kunjungan): JsonResponse
    {
        return response()->json($this->service->selesai($kunjungan, $request->user()));
    }
}
