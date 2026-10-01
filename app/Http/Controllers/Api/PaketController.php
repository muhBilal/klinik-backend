<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Paket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Katalog paket multi-sesi (PRD TR-02). `nilai_normal` = jumlah tarif dasar × sesi (pembanding harga paket).
 */
class PaketController extends Controller
{
    private const RELASI = ['items:id,paket_id,tindakan_id,jumlah_sesi', 'items.tindakan:id,kode,nama,tarif,is_active'];

    /** **Array**. `aktif=1` = paket aktif untuk dijual; `q`, `status`. */
    public function index(Request $request): JsonResponse
    {
        $pakets = $this->filterAktif(Paket::query(), $request)
            ->when($request->boolean('aktif'), fn ($q) => $q->where('is_active', true))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhereLike('kode', "{$q}%"));
            })
            ->with(self::RELASI)
            ->withCount('terjual')
            ->orderBy('nama')
            ->get()
            ->each(fn (Paket $p) => $this->nilaiNormal($p));

        return response()->json($pakets);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->simpan($this->validated($request)), 201);
    }

    public function show(Paket $paket): JsonResponse
    {
        return response()->json($this->nilaiNormal($paket->load(self::RELASI)->loadCount('terjual')));
    }

    public function update(Request $request, Paket $paket): JsonResponse
    {
        return response()->json($this->simpan($this->validated($request, $paket), $paket));
    }

    public function destroy(Paket $paket): JsonResponse
    {
        abort_if($paket->terjual()->exists(), 422, 'Paket sudah pernah dijual. Nonaktifkan saja, jangan dihapus.');

        $paket->delete();

        return response()->json(['message' => 'Paket dihapus.']);
    }

    /** Isi paket diganti per model (tercatat audit); paket yang sudah terjual tidak terpengaruh (di-snapshot). */
    private function simpan(array $data, ?Paket $paket = null): Paket
    {
        return DB::transaction(function () use ($data, $paket) {
            $atribut = collect($data)->except('items')->all();
            $paket ? $paket->update($atribut) : $paket = Paket::create($atribut);

            $lama = $paket->items()->get()->keyBy('tindakan_id');
            $baru = collect($data['items'])->keyBy('tindakan_id');

            $lama->each(fn ($item, $tindakanId) => $baru->has($tindakanId) || $item->delete());
            foreach ($baru as $tindakanId => $item) {
                $lama->has($tindakanId)
                    ? $lama[$tindakanId]->update(['jumlah_sesi' => $item['jumlah_sesi']])
                    : $paket->items()->create(['tindakan_id' => $tindakanId, 'jumlah_sesi' => $item['jumlah_sesi']]);
            }

            return $this->nilaiNormal($paket->load(self::RELASI)->loadCount('terjual'));
        });
    }

    private function nilaiNormal(Paket $paket): Paket
    {
        return $paket->setAttribute('nilai_normal', (int) $paket->items->sum(fn ($i) => ($i->tindakan?->tarif ?? 0) * $i->jumlah_sesi));
    }

    private function validated(Request $request, ?Paket $paket = null): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:20', Rule::unique('pakets')->ignore($paket)],
            'nama' => ['required', 'string', 'max:150'],
            'deskripsi' => ['nullable', 'string', 'max:2000'],
            'harga' => ['required', 'integer', 'min:0'],
            'masa_berlaku_hari' => ['nullable', 'integer', 'between:1,3650'],
            'lintas_cabang' => ['boolean'],
            'is_active' => ['boolean'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.tindakan_id' => ['required', 'distinct', Rule::exists('tindakans', 'id')->whereNull('deleted_at')],
            'items.*.jumlah_sesi' => ['required', 'integer', 'between:1,100'],
        ], [
            'items.required' => 'Paket harus berisi minimal satu treatment.',
            'items.*.tindakan_id.distinct' => 'Treatment yang sama hanya boleh satu baris; atur jumlah sesinya.',
        ]);
    }
}
