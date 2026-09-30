<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KodeFavorit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Favorit kode diagnosa (ICD-10) & tindakan (ICD-9-CM) milik dokter yang login (PRD RM-02).
 * Daftarnya diambil lewat `GET /icd10s?favorit=1` / `GET /icd9cms?favorit=1`.
 */
class KodeFavoritController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        KodeFavorit::firstOrCreate(['user_id' => $request->user()->id, ...$data]);

        return response()->json(['favorit' => true, ...$data], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        KodeFavorit::where('user_id', $request->user()->id)->where($data)->delete();

        return response()->json(['favorit' => false, ...$data]);
    }

    /** @return array{jenis: string, kode_id: int} */
    private function validated(Request $request): array
    {
        $jenis = $request->input('jenis');
        $tabel = [KodeFavorit::ICD10 => 'icd10s', KodeFavorit::ICD9CM => 'icd9cms'][$jenis] ?? null;

        $data = $request->validate([
            'jenis' => ['required', Rule::in(array_keys(KodeFavorit::MODEL))],
            'kode_id' => ['required', 'integer', ...($tabel ? [Rule::exists($tabel, 'id')] : [])],
        ]);

        return ['jenis' => $data['jenis'], 'kode_id' => (int) $data['kode_id']];
    }
}
