<?php

namespace App\Http\Controllers\Api;

use App\Enums\JenisMutasi;
use App\Http\Controllers\Controller;
use App\Models\Obat;
use App\Models\TindakanBhp;
use App\Services\FarmasiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ObatController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $obats = $this->filterAktif(Obat::query(), $request)
            ->select(['id', 'kode', 'nama', 'satuan', 'harga', 'stok', 'stok_minimum', 'is_active'])
            ->when($request->boolean('aktif'), fn ($q) => $q->where('is_active', true))
            ->when($request->boolean('menipis'), fn ($q) => $q->stokMenipis())
            ->when($request->filled('satuan'), fn ($q) => $q->where('satuan', $request->input('satuan')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhereLike('kode', "{$q}%"));
            })
            ->orderBy('nama');

        return response()->json($this->paginate($obats, $request));
    }

    public function store(Request $request, FarmasiService $farmasi): JsonResponse
    {
        $data = $this->validated($request);
        $stokAwal = $request->validate(['stok_awal' => ['nullable', 'integer', 'min:0']])['stok_awal'] ?? 0;

        $obat = DB::transaction(function () use ($data, $stokAwal, $farmasi, $request) {
            $obat = Obat::create($data);

            if ($stokAwal > 0) {
                $farmasi->mutasiManual($obat, JenisMutasi::Masuk, $stokAwal, 'Stok awal', $request->user());
            }

            return $obat->refresh();
        });

        return response()->json($obat, 201);
    }

    public function show(Obat $obat): JsonResponse
    {
        return response()->json($obat);
    }

    public function update(Request $request, Obat $obat): JsonResponse
    {
        $obat->update($this->validated($request, $obat));

        return response()->json($obat);
    }

    public function destroy(Obat $obat): JsonResponse
    {
        abort_if(DB::table('resep_items')->where('obat_id', $obat->id)->exists(), 422,
            'Obat sudah pernah diresepkan. Nonaktifkan saja, jangan dihapus.');
        abort_if(TindakanBhp::where('obat_id', $obat->id)->whereHas('tindakan', fn ($q) => $q->withoutTrashed())->exists(), 422,
            'Obat dipakai sebagai BHP standar treatment. Hapus dari daftar BHP treatment terlebih dahulu.');

        $obat->delete();

        return response()->json(['message' => 'Obat dihapus.']);
    }

    /**
     * Kartu stok.
     */
    public function mutasi(Request $request, Obat $obat): JsonResponse
    {
        $mutasi = $obat->mutasis()
            ->select(['id', 'obat_id', 'jenis', 'jumlah', 'stok_akhir', 'referensi', 'keterangan', 'user_id', 'created_at'])
            ->when($request->filled('jenis'), fn ($q) => $q->where('jenis', $request->input('jenis')))
            ->with('user:id,name');

        return response()->json($this->paginate($mutasi, $request));
    }

    public function storeMutasi(Request $request, Obat $obat, FarmasiService $farmasi): JsonResponse
    {
        $data = $request->validate([
            'jenis' => ['required', Rule::enum(JenisMutasi::class)],
            'jumlah' => ['required', 'integer', 'min:0'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        $mutasi = $farmasi->mutasiManual($obat, JenisMutasi::from($data['jenis']), $data['jumlah'], $data['keterangan'] ?? null, $request->user());

        return response()->json(['mutasi' => $mutasi, 'obat' => $obat->refresh()], 201);
    }

    private function validated(Request $request, ?Obat $obat = null): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:20', Rule::unique('obats')->ignore($obat)],
            'nama' => ['required', 'string', 'max:255'],
            'satuan' => ['required', 'string', 'max:20'],
            'harga' => ['required', 'integer', 'min:0'],
            'stok_minimum' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],
        ]);
    }
}
