<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Poli;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PoliController extends Controller
{
    /**
     * `?aktif=1` = daftar ringkas poli aktif untuk dropdown (id, kode, nama).
     * Tanpa filter = data lengkap + jumlah dokter untuk halaman master.
     */
    public function index(Request $request): JsonResponse
    {
        $polis = $this->filterAktif(Poli::query(), $request)
            ->when(
                $request->boolean('aktif'),
                fn ($q) => $q->select(['id', 'kode', 'nama'])->where('is_active', true),
                fn ($q) => $q->withCount('dokters'),
            )
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhereLike('kode', "{$q}%"));
            })
            ->orderBy('nama')
            ->get();

        return response()->json($polis);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Poli::create($this->validated($request)), 201);
    }

    public function show(Poli $poli): JsonResponse
    {
        return response()->json($poli->load('dokters:id,name,poli_id,sip'));
    }

    public function update(Request $request, Poli $poli): JsonResponse
    {
        $poli->update($this->validated($request, $poli));

        return response()->json($poli);
    }

    public function destroy(Poli $poli): JsonResponse
    {
        abort_if($poli->kunjungans()->withoutGlobalScope('cabang')->exists(), 422, 'Poli sudah memiliki kunjungan. Nonaktifkan saja, jangan dihapus.');

        $poli->delete();

        return response()->json(['message' => 'Poli dihapus.']);
    }

    private function validated(Request $request, ?Poli $poli = null): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:10', Rule::unique('polis')->ignore($poli)],
            'nama' => ['required', 'string', 'max:255'],
            'tarif_konsultasi' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
