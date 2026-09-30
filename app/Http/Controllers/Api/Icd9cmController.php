<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Icd9cm;
use App\Models\KunjunganTindakan;
use App\Models\Tindakan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master kode tindakan ICD-9-CM (PRD RM-02). `favorit` = kode favorit dokter yang login.
 */
class Icd9cmController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $icd9cms = Icd9cm::query()
            ->select(['id', 'kode', 'nama'])
            ->withExists(['favorits as favorit' => fn ($q) => $q->where('user_id', $userId)])
            ->when($request->boolean('favorit'), fn ($q) => $q->whereHas('favorits', fn ($f) => $f->where('user_id', $userId)))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('kode', "{$q}%")->orWhereLike('nama', "%{$q}%"));
            })
            ->orderByDesc('favorit')
            ->orderBy('kode');

        return response()->json($this->paginate($icd9cms, $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Icd9cm::create($this->validated($request)), 201);
    }

    public function show(Icd9cm $icd9cm): JsonResponse
    {
        return response()->json($icd9cm);
    }

    public function update(Request $request, Icd9cm $icd9cm): JsonResponse
    {
        $icd9cm->update($this->validated($request, $icd9cm));

        return response()->json($icd9cm);
    }

    public function destroy(Icd9cm $icd9cm): JsonResponse
    {
        abort_if(KunjunganTindakan::where('icd9cm_id', $icd9cm->id)->exists(), 422, 'Kode ICD-9-CM sudah dipakai pada rekam medis.');
        abort_if(Tindakan::withTrashed()->where('icd9cm_id', $icd9cm->id)->exists(), 422, 'Kode ICD-9-CM masih menjadi kode default treatment.');

        $icd9cm->delete();

        return response()->json(['message' => 'Kode ICD-9-CM dihapus.']);
    }

    private function validated(Request $request, ?Icd9cm $icd9cm = null): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:10', 'regex:/^\d{2}(\.\d{1,2})?$/', Rule::unique('icd9cms')->ignore($icd9cm)],
            'nama' => ['required', 'string', 'max:255'],
        ], [
            'kode.regex' => 'Format kode ICD-9-CM: dua digit, opsional titik dan 1-2 digit (mis. 86.3 atau 99.29).',
        ]);
    }
}
