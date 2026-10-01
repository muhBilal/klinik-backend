<?php

namespace App\Http\Controllers\Api;

use App\Enums\KeparahanAlergi;
use App\Http\Controllers\Controller;
use App\Models\Pasien;
use App\Models\PasienAlergi;
use App\Models\ProfilKlinis;
use App\Services\AuditService;
use App\Services\ProfilKlinisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Profil klinis & alergi terstruktur pasien (PRD PS-03). Data klinis: baca `rme.lihat`, ubah tenaga pemeriksaan.
 */
class ProfilKlinisController extends Controller
{
    public function __construct(private ProfilKlinisService $service) {}

    public function show(Pasien $pasien, AuditService $audit): JsonResponse
    {
        $audit->catat('lihat', 'profil_klinis', $pasien->id, ['pasien_id' => $pasien->id, 'label' => $pasien->auditLabel()]);

        return response()->json($this->service->lihat($pasien));
    }

    public function update(Request $request, Pasien $pasien): JsonResponse
    {
        $data = $request->validate([
            'fitzpatrick' => ['sometimes', 'nullable', 'integer', 'between:1,6'],
            'status_kehamilan' => ['sometimes', 'nullable', Rule::in(ProfilKlinis::STATUS_KEHAMILAN)],
            'hpht' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            'riwayat_obat' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'riwayat_penyakit' => ['sometimes', 'nullable', 'string', 'max:2000'],

            'alergis' => ['sometimes', 'array', 'max:30'],
            'alergis.*.id' => ['nullable', 'integer'],
            'alergis.*.jenis' => ['required', Rule::in(PasienAlergi::JENIS)],
            'alergis.*.zat' => ['required', 'string', 'max:150'],
            'alergis.*.obat_id' => ['nullable', Rule::exists('obats', 'id')],
            'alergis.*.reaksi' => ['nullable', 'string', 'max:255'],
            'alergis.*.keparahan' => ['required', Rule::enum(KeparahanAlergi::class)],
            'alergis.*.catatan' => ['nullable', 'string', 'max:255'],
        ]);

        if (in_array($data['status_kehamilan'] ?? null, ['hamil', 'menyusui'], true) && $pasien->jenis_kelamin !== 'P') {
            throw ValidationException::withMessages(['status_kehamilan' => 'Status hamil/menyusui hanya untuk pasien perempuan.']);
        }

        return response()->json($this->service->simpan($pasien, $data, $request->user()));
    }
}
