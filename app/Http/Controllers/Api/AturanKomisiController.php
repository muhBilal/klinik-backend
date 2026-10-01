<?php

namespace App\Http\Controllers\Api;

use App\Enums\JenisPotongan;
use App\Enums\PeranKomisi;
use App\Http\Controllers\Controller;
use App\Models\AturanKomisi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Aturan komisi per treatment / kategori / semua, per peran, opsional per cabang (PRD KM-01, TR-01).
 */
class AturanKomisiController extends Controller
{
    private const RELASI = ['tindakan:id,kode,nama', 'kategori:id,nama', 'cabang:id,kode,nama'];

    public function index(Request $request): JsonResponse
    {
        $aturans = $this->filterAktif(AturanKomisi::query(), $request)
            ->with(self::RELASI)
            ->when($request->filled('tindakan_id'), fn ($q) => $q->where('tindakan_id', $request->integer('tindakan_id')))
            ->when($request->filled('peran'), fn ($q) => $q->where('peran', $request->input('peran')))
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($w) => $w
                ->whereHas('tindakan', fn ($t) => $t->whereLike('nama', '%'.$request->string('q')->trim().'%'))
                ->orWhereHas('kategori', fn ($k) => $k->whereLike('nama', '%'.$request->string('q')->trim().'%'))))
            // Urut: umum dulu, lalu kategori, lalu treatment
            ->orderByRaw('CASE WHEN tindakan_id IS NOT NULL THEN 2 WHEN kategori_id IS NOT NULL THEN 1 ELSE 0 END')
            ->orderBy('peran')
            ->orderBy('id');

        return response()->json($this->paginate($aturans, $request, 50, 200));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(AturanKomisi::create($this->validated($request))->load(self::RELASI), 201);
    }

    public function update(Request $request, AturanKomisi $aturanKomisi): JsonResponse
    {
        $aturanKomisi->update($this->validated($request, $aturanKomisi));

        return response()->json($aturanKomisi->load(self::RELASI));
    }

    public function destroy(AturanKomisi $aturanKomisi): JsonResponse
    {
        $aturanKomisi->delete();

        return response()->json(['message' => 'Aturan komisi dihapus.']);
    }

    private function validated(Request $request, ?AturanKomisi $aturan = null): array
    {
        $data = $request->validate([
            'tindakan_id' => ['nullable', Rule::exists('tindakans', 'id')->whereNull('deleted_at')],
            'kategori_id' => ['nullable', Rule::exists('kategori_tindakans', 'id')->whereNull('deleted_at')],
            'cabang_id' => ['nullable', Rule::exists('cabangs', 'id')->whereNull('deleted_at')],
            'peran' => ['required', Rule::enum(PeranKomisi::class)],
            'jenis' => ['required', Rule::enum(JenisPotongan::class)],
            'nilai' => ['required', 'numeric', 'min:0', 'max:999999999'],
            'is_active' => ['boolean'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        // Treatment tertentu sudah menentukan kategorinya sendiri.
        if (! empty($data['tindakan_id'])) {
            $data['kategori_id'] = null;
        }

        if ($data['jenis'] === JenisPotongan::Persen->value && $data['nilai'] > 100) {
            throw ValidationException::withMessages(['nilai' => 'Persen komisi maksimal 100.']);
        }

        // Satu cakupan + peran hanya satu aturan aktif agar pemilihan aturan tidak ambigu.
        $kembar = AturanKomisi::query()
            ->where('peran', $data['peran'])
            ->where('tindakan_id', $data['tindakan_id'] ?? null)
            ->where('kategori_id', $data['kategori_id'] ?? null)
            ->where('cabang_id', $data['cabang_id'] ?? null)
            ->when($aturan, fn ($q) => $q->whereKeyNot($aturan->id))
            ->exists();

        if ($kembar) {
            throw ValidationException::withMessages(['peran' => 'Sudah ada aturan untuk cakupan & peran yang sama. Ubah aturan itu saja.']);
        }

        return $data;
    }
}
