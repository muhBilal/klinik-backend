<?php

namespace App\Http\Controllers\Api;

use App\Enums\KategoriAlergi;
use App\Enums\KeparahanAlergi;
use App\Enums\StatusKehamilan;
use App\Enums\TipeKulitFitzpatrick;
use App\Http\Controllers\Controller;
use App\Models\Pasien;
use App\Services\AuditService;
use App\Services\DataKlinisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Data klinis pasien (PRD PS-03): alergi terstruktur, riwayat obat & penyakit, Fitzpatrick, hamil/menyusui.
 * Baca: `rme.lihat` (tercatat audit). Ubah: tenaga yang melakukan anamnesis (`pemeriksaan.vital`, `pemeriksaan.dokter`, `rme.tindakan`).
 */
class DataKlinisController extends Controller
{
    public function __construct(private DataKlinisService $service) {}

    public function show(Pasien $pasien, AuditService $audit): JsonResponse
    {
        $audit->catat('lihat', 'pasien_klinis', $pasien->id, ['pasien_id' => $pasien->id, 'label' => "Data klinis {$pasien->auditLabel()}"]);

        return response()->json($this->service->data($pasien));
    }

    public function update(Request $request, Pasien $pasien): JsonResponse
    {
        $data = $request->validate([
            'fitzpatrick' => ['nullable', Rule::enum(TipeKulitFitzpatrick::class)],
            'status_kehamilan' => ['nullable', Rule::enum(StatusKehamilan::class)],
            'konfirmasi_kehamilan' => ['boolean'],
            'riwayat_obat' => ['nullable', 'string', 'max:2000'],
            'riwayat_penyakit' => ['nullable', 'string', 'max:2000'],
            'alergis' => ['sometimes', 'array', 'max:50'],
            'alergis.*.id' => ['nullable', 'integer'],
            'alergis.*.kategori' => ['required', Rule::enum(KategoriAlergi::class)],
            'alergis.*.zat' => ['required', 'string', 'max:150'],
            'alergis.*.obat_id' => ['nullable', Rule::exists('obats', 'id')],
            'alergis.*.reaksi' => ['nullable', 'string', 'max:255'],
            'alergis.*.keparahan' => ['nullable', Rule::enum(KeparahanAlergi::class)],
        ]);

        $this->service->simpan($pasien, $data, $request->user());

        return response()->json($this->service->data($pasien));
    }
}
