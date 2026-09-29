<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pasien;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PasienController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pasiens = Pasien::query()
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w
                    ->whereLike('nama', "%{$q}%")
                    ->orWhere('no_rm', 'like', "{$q}%")
                    ->orWhere('nik', 'like', "{$q}%")
                    ->orWhere('no_bpjs', 'like', "{$q}%"));
            })
            ->latest('id')
            ->paginate(min($request->integer('per_page', 15), 100));

        return response()->json($pasiens);
    }

    public function store(Request $request): JsonResponse
    {
        $pasien = Pasien::create($this->validated($request));

        return response()->json($pasien, 201);
    }

    public function show(Pasien $pasien): JsonResponse
    {
        $pasien->load(['kunjungans' => fn ($q) => $q
            ->with(['poli:id,nama', 'dokter:id,name', 'pemeriksaan.diagnosas.icd10'])
            ->latest('tanggal')->latest('id')
            ->limit(50)]);

        return response()->json($pasien);
    }

    public function update(Request $request, Pasien $pasien): JsonResponse
    {
        $pasien->update($this->validated($request, $pasien));

        return response()->json($pasien);
    }

    public function destroy(Pasien $pasien): JsonResponse
    {
        abort_if($pasien->kunjungans()->exists(), 422, 'Pasien yang sudah memiliki riwayat kunjungan tidak dapat dihapus.');

        $pasien->delete();

        return response()->json(['message' => 'Data pasien dihapus.']);
    }

    private function validated(Request $request, ?Pasien $pasien = null): array
    {
        return $request->validate([
            'nik' => ['nullable', 'digits:16', Rule::unique('pasiens')->ignore($pasien)],
            'no_bpjs' => ['nullable', 'digits:13'],
            'nama' => ['required', 'string', 'max:255'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date', 'before_or_equal:today'],
            'golongan_darah' => ['nullable', Rule::in(['A', 'B', 'AB', 'O', '-'])],
            'alamat' => ['nullable', 'string', 'max:500'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'pekerjaan' => ['nullable', 'string', 'max:100'],
            'alergi' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
