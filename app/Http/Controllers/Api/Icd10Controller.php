<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Icd10;
use App\Models\PemeriksaanDiagnosa;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class Icd10Controller extends Controller
{
    /**
     * `?huruf=J` = bab ICD-10 berdasarkan huruf awal kode; `?favorit=1` = favorit dokter yang login saja.
     * Favorit selalu tampil paling atas (RM-02).
     */
    public function index(Request $request): JsonResponse
    {
        $huruf = strtoupper((string) $request->input('huruf'));
        $userId = $request->user()->id;

        $icd10s = Icd10::query()
            ->select(['id', 'kode', 'nama', 'sensitif'])
            ->withExists(['favorits as favorit' => fn ($q) => $q->where('user_id', $userId)])
            ->when(preg_match('/^[A-Z]$/', $huruf) === 1, fn ($q) => $q->where('kode', 'like', "{$huruf}%"))
            ->when($request->boolean('favorit'), fn ($q) => $q->whereHas('favorits', fn ($f) => $f->where('user_id', $userId)))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('kode', "{$q}%")->orWhereLike('nama', "%{$q}%"));
            })
            ->orderByDesc('favorit')
            ->orderBy('kode');

        return response()->json($this->paginate($icd10s, $request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        // Kode IMS/HIV otomatis ditandai sensitif bila admin tidak memilih (DR-03).
        $data['sensitif'] ??= Icd10::kodeSensitif($data['kode']);

        return response()->json(Icd10::create($data), 201);
    }

    public function show(Icd10 $icd10): JsonResponse
    {
        return response()->json($icd10);
    }

    public function update(Request $request, Icd10 $icd10): JsonResponse
    {
        $icd10->update($this->validated($request, $icd10));

        return response()->json($icd10);
    }

    public function destroy(Icd10 $icd10): JsonResponse
    {
        abort_if(PemeriksaanDiagnosa::where('icd10_id', $icd10->id)->exists(), 422, 'Kode ICD-10 sudah dipakai pada rekam medis.');

        $icd10->delete();

        return response()->json(['message' => 'Kode ICD-10 dihapus.']);
    }

    private function validated(Request $request, ?Icd10 $icd10 = null): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:10', Rule::unique('icd10s')->ignore($icd10)],
            'nama' => ['required', 'string', 'max:255'],
            'sensitif' => ['sometimes', 'nullable', 'boolean'],
        ]);
    }
}
