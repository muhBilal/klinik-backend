<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipeSumberDaya;
use App\Http\Controllers\Controller;
use App\Models\SumberDaya;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Ruang & alat yang bisa dibooking (PRD BK-01).
 */
class SumberDayaController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sumberDayas = $this->filterAktif(SumberDaya::query(), $request)
            ->select(['id', 'cabang_id', 'kode', 'nama', 'tipe', 'is_active'])
            ->with('cabang:id,kode,nama')
            ->when($request->filled('tipe'), fn ($q) => $q->where('tipe', $request->input('tipe')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhereLike('kode', "{$q}%"));
            })
            ->orderBy('tipe')->orderBy('nama');

        return response()->json($this->paginate($sumberDayas, $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(SumberDaya::create($this->validated($request))->load('cabang:id,kode,nama'), 201);
    }

    public function update(Request $request, SumberDaya $sumberDaya): JsonResponse
    {
        $sumberDaya->update($this->validated($request, $sumberDaya));

        return response()->json($sumberDaya->load('cabang:id,kode,nama'));
    }

    public function destroy(SumberDaya $sumberDaya): JsonResponse
    {
        abort_if($sumberDaya->appointmentsAktif()->exists(), 422,
            'Ruang/alat masih dipakai booking mendatang. Nonaktifkan saja, jangan dihapus.');

        $sumberDaya->delete();

        return response()->json(['message' => 'Ruang/alat dihapus.']);
    }

    private function validated(Request $request, ?SumberDaya $sumberDaya = null): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:20',
                Rule::unique('sumber_dayas')->where('cabang_id', $sumberDaya?->cabang_id ?? app(CabangAktif::class)->untukDataBaru())
                    ->whereNull('deleted_at')->ignore($sumberDaya)],
            'nama' => ['required', 'string', 'max:100'],
            'tipe' => ['required', Rule::enum(TipeSumberDaya::class)],
            'is_active' => ['boolean'],
        ]);
    }
}
