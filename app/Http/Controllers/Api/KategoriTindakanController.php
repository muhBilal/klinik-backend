<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KategoriTindakan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Kategori treatment (PRD TR-01).
 */
class KategoriTindakanController extends Controller
{
    /**
     * `?aktif=1` = daftar ringkas kategori aktif untuk dropdown (id, nama).
     * Tanpa filter = data lengkap + jumlah treatment untuk halaman master.
     */
    public function index(Request $request): JsonResponse
    {
        $kategoris = $this->filterAktif(KategoriTindakan::query(), $request)
            ->when(
                $request->boolean('aktif'),
                fn ($q) => $q->select(['id', 'nama'])->where('is_active', true),
                fn ($q) => $q->withCount('tindakans'),
            )
            ->when($request->filled('q'), fn ($q) => $q->whereLike('nama', '%'.$request->string('q')->trim().'%'))
            ->orderBy('nama')
            ->get();

        return response()->json($kategoris);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(KategoriTindakan::create($this->validated($request)), 201);
    }

    public function update(Request $request, KategoriTindakan $kategori): JsonResponse
    {
        $kategori->update($this->validated($request, $kategori));

        return response()->json($kategori);
    }

    public function destroy(KategoriTindakan $kategori): JsonResponse
    {
        abort_if($kategori->tindakans()->exists(), 422, 'Kategori masih dipakai treatment. Nonaktifkan saja, jangan dihapus.');

        $kategori->delete();

        return response()->json(['message' => 'Kategori dihapus.']);
    }

    private function validated(Request $request, ?KategoriTindakan $kategori = null): array
    {
        return $request->validate([
            'nama' => ['required', 'string', 'max:100', Rule::unique('kategori_tindakans')->ignore($kategori)],
            'deskripsi' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);
    }
}
