<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Icd10;
use App\Models\TemplateSoap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Template SOAP per spesialisasi (poli) & per treatment (PRD RM-01, DR-03).
 * Daftar bisa dibaca semua pengguna login (pengetahuan klinis, bukan data pasien); kelola butuh master.kelola.
 */
class TemplateSoapController extends Controller
{
    /**
     * **Array** (maks. 200). `poli_id` = template poli itu + template umum (poli kosong), yang spesifik dulu.
     * `aktif=1` untuk pilihan di pemeriksaan. Setiap template memuat `diagnosas` (ICD-10 dari `icd10_ids`).
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['poli_id' => ['nullable', 'integer']]);

        $templates = $this->filterAktif(TemplateSoap::query(), $request)
            ->with(['poli:id,nama', 'tindakan:id,nama'])
            ->when($request->boolean('aktif'), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('poli_id'), fn ($q) => $q
                ->where(fn ($w) => $w->where('poli_id', $request->integer('poli_id'))->orWhereNull('poli_id'))
                ->orderByRaw('CASE WHEN poli_id IS NULL THEN 1 ELSE 0 END'))
            ->when($request->filled('q'), fn ($q) => $q->whereLike('nama', '%'.$request->string('q')->trim().'%'))
            ->orderBy('nama')
            ->limit(200)
            ->get();

        return response()->json($this->denganDiagnosa($templates));
    }

    public function store(Request $request): JsonResponse
    {
        $template = TemplateSoap::create($this->validated($request));

        return response()->json($this->denganDiagnosa(collect([$template->load(['poli:id,nama', 'tindakan:id,nama'])]))->first(), 201);
    }

    public function update(Request $request, TemplateSoap $templateSoap): JsonResponse
    {
        $templateSoap->update($this->validated($request));

        return response()->json($this->denganDiagnosa(collect([$templateSoap->load(['poli:id,nama', 'tindakan:id,nama'])]))->first());
    }

    public function destroy(TemplateSoap $templateSoap): JsonResponse
    {
        $templateSoap->delete();

        return response()->json(['message' => 'Template SOAP dihapus.']);
    }

    /** Lampirkan objek ICD-10 saran diagnosa (satu query untuk semua template). */
    private function denganDiagnosa(Collection $templates): Collection
    {
        $icd10 = Icd10::whereIn('id', $templates->pluck('icd10_ids')->flatten()->filter()->unique())
            ->get(['id', 'kode', 'nama', 'sensitif'])
            ->keyBy('id');

        return $templates->each(fn (TemplateSoap $t) => $t->setAttribute(
            'diagnosas',
            collect($t->icd10_ids ?? [])->map(fn ($id) => $icd10->get($id))->filter()->values(),
        ));
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:150'],
            'poli_id' => ['nullable', Rule::exists('polis', 'id')->whereNull('deleted_at')],
            'tindakan_id' => ['nullable', Rule::exists('tindakans', 'id')->whereNull('deleted_at')],
            'subjektif' => ['nullable', 'string', 'max:5000'],
            'objektif' => ['nullable', 'string', 'max:5000'],
            'asesmen' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:5000'],
            'icd10_ids' => ['nullable', 'array', 'max:10'],
            'icd10_ids.*' => ['integer', 'distinct', Rule::exists('icd10s', 'id')],
            'akses_terbatas' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        $data['icd10_ids'] = array_values(array_map('intval', $data['icd10_ids'] ?? [])) ?: null;

        return $data;
    }
}
