<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KunjunganTindakan;
use App\Models\Tindakan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TindakanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tindakans = $this->filterAktif(Tindakan::query(), $request)
            ->select(['id', 'kode', 'nama', 'tarif', 'is_active'])
            ->when($request->boolean('aktif'), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhereLike('kode', "{$q}%"));
            })
            ->orderBy('nama');

        return response()->json($this->paginate($tindakans, $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Tindakan::create($this->validated($request)), 201);
    }

    public function show(Tindakan $tindakan): JsonResponse
    {
        return response()->json($tindakan);
    }

    public function update(Request $request, Tindakan $tindakan): JsonResponse
    {
        $tindakan->update($this->validated($request, $tindakan));

        return response()->json($tindakan);
    }

    public function destroy(Tindakan $tindakan): JsonResponse
    {
        abort_if(KunjunganTindakan::where('tindakan_id', $tindakan->id)->exists(), 422,
            'Tindakan sudah pernah digunakan. Nonaktifkan saja, jangan dihapus.');

        $tindakan->delete();

        return response()->json(['message' => 'Tindakan dihapus.']);
    }

    private function validated(Request $request, ?Tindakan $tindakan = null): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:20', Rule::unique('tindakans')->ignore($tindakan)],
            'nama' => ['required', 'string', 'max:255'],
            'tarif' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
