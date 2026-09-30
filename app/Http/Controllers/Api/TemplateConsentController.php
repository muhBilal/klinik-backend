<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TemplateConsent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Master naskah informed consent (PRD RM-03). Placeholder: lihat TemplateConsent::PLACEHOLDER.
 */
class TemplateConsentController extends Controller
{
    /** **Array**. `aktif=1`: `{id, nama}` untuk pilihan; tanpa filter: lengkap + `tindakans_count` + `placeholder`. */
    public function index(Request $request): JsonResponse
    {
        if ($request->boolean('aktif')) {
            return response()->json(TemplateConsent::where('is_active', true)->orderBy('nama')->get(['id', 'nama']));
        }

        $templates = $this->filterAktif(TemplateConsent::query(), $request)
            ->withCount('tindakans')
            ->when($request->filled('q'), fn ($q) => $q->whereLike('nama', '%'.$request->string('q')->trim().'%'))
            ->orderBy('nama')
            ->get();

        return response()->json($templates);
    }

    public function show(TemplateConsent $templateConsent): JsonResponse
    {
        return response()->json([...$templateConsent->toArray(), 'placeholder' => TemplateConsent::PLACEHOLDER]);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(TemplateConsent::create($this->validated($request)), 201);
    }

    public function update(Request $request, TemplateConsent $templateConsent): JsonResponse
    {
        $templateConsent->update($this->validated($request));

        return response()->json($templateConsent);
    }

    /** Soft delete. Template yang masih diwajibkan treatment tidak bisa dihapus (consent-nya tidak bisa diambil lagi). */
    public function destroy(TemplateConsent $templateConsent): JsonResponse
    {
        abort_if($templateConsent->tindakans()->exists(), 422,
            'Template masih dipakai treatment. Lepaskan dari treatment terlebih dahulu atau nonaktifkan saja.');

        $templateConsent->delete();

        return response()->json(['message' => 'Template informed consent dihapus.']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'nama' => ['required', 'string', 'max:150'],
            'isi' => ['required', 'string', 'max:20000'],
            'is_active' => ['boolean'],
        ]);
    }
}
