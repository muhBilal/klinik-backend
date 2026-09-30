<?php

namespace App\Http\Controllers\Api;

use App\Enums\JenisCatatanTindakan;
use App\Http\Controllers\Controller;
use App\Models\KunjunganTindakan;
use App\Models\Tindakan;
use App\Services\TindakanService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Katalog treatment / tindakan (PRD TR-01): kategori, durasi, harga dasar + harga per cabang, BHP standar.
 */
class TindakanController extends Controller
{
    public function __construct(private TindakanService $service) {}

    /**
     * `tarif` = harga dasar; `tarif_cabang` & `tersedia` = untuk cabang `?cabang_id=` atau cabang aktif.
     * `aktif=1` (pilihan di pemeriksaan) juga menyembunyikan treatment yang tidak dilayani di cabang itu.
     */
    public function index(Request $request, CabangAktif $cabangAktif): JsonResponse
    {
        $cabangId = $request->integer('cabang_id') ?: $cabangAktif->id();

        $tindakans = $this->filterAktif(Tindakan::query(), $request)
            ->select(['id', 'kode', 'nama', 'kategori_id', 'icd9cm_id', 'template_consent_id', 'jenis_catatan', 'durasi_menit', 'buffer_menit', 'tarif', 'is_active'])
            ->denganHargaCabang($cabangId)
            ->with(['kategori:id,nama', 'icd9cm:id,kode,nama'])
            ->withCount(['hargas', 'bhps'])
            ->when($request->boolean('aktif'), fn ($q) => $q->where('is_active', true)->tersediaDi($cabangId))
            ->when($request->filled('kategori_id'), fn ($q) => $q->where('kategori_id', $request->integer('kategori_id')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhereLike('kode', "{$q}%"));
            })
            ->orderBy('nama');

        return response()->json($this->paginate($tindakans, $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->detail($this->service->simpan($this->validated($request))), 201);
    }

    public function show(Tindakan $tindakan): JsonResponse
    {
        return response()->json($this->detail($tindakan));
    }

    public function update(Request $request, Tindakan $tindakan): JsonResponse
    {
        return response()->json($this->detail($this->service->simpan($this->validated($request, $tindakan), $tindakan)));
    }

    public function destroy(Tindakan $tindakan): JsonResponse
    {
        abort_if(KunjunganTindakan::where('tindakan_id', $tindakan->id)->exists(), 422,
            'Tindakan sudah pernah digunakan. Nonaktifkan saja, jangan dihapus.');

        $tindakan->delete();

        return response()->json(['message' => 'Tindakan dihapus.']);
    }

    private function detail(Tindakan $tindakan): Tindakan
    {
        return $tindakan->load([
            'kategori:id,nama',
            'icd9cm:id,kode,nama',
            'templateConsent:id,nama',
            // Harga cabang yang sudah dihapus tidak ditampilkan (whereHas mengikuti soft delete cabang).
            'hargas' => fn ($q) => $q->select(['id', 'tindakan_id', 'cabang_id', 'tarif', 'tersedia'])
                ->whereHas('cabang')->with('cabang:id,kode,nama,is_active'),
            'bhps' => fn ($q) => $q->select(['id', 'tindakan_id', 'obat_id', 'jumlah'])
                ->with('obat:id,kode,nama,satuan,is_active'),
        ]);
    }

    private function validated(Request $request, ?Tindakan $tindakan = null): array
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:20', Rule::unique('tindakans')->ignore($tindakan)],
            'nama' => ['required', 'string', 'max:255'],
            'kategori_id' => ['nullable', Rule::exists('kategori_tindakans', 'id')->whereNull('deleted_at')],
            'icd9cm_id' => ['nullable', Rule::exists('icd9cms', 'id')],
            'template_consent_id' => ['nullable', Rule::exists('template_consents', 'id')->whereNull('deleted_at')],
            'jenis_catatan' => ['nullable', Rule::enum(JenisCatatanTindakan::class)],
            'durasi_menit' => ['required', 'integer', 'between:1,720'],
            'buffer_menit' => ['nullable', 'integer', 'between:0,240'],
            'tarif' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],

            'hargas' => ['sometimes', 'array'],
            'hargas.*.cabang_id' => ['required', 'distinct', Rule::exists('cabangs', 'id')->whereNull('deleted_at')],
            'hargas.*.tarif' => ['required', 'integer', 'min:0'],
            'hargas.*.tersedia' => ['boolean'],

            'bhps' => ['sometimes', 'array', 'max:50'],
            'bhps.*.obat_id' => ['required', 'distinct', Rule::exists('obats', 'id')->whereNull('deleted_at')],
            'bhps.*.jumlah' => ['required', 'numeric', 'gt:0', 'max:99999', 'decimal:0,3'],
        ]);

        return ['buffer_menit' => $data['buffer_menit'] ?? 0, 'jenis_catatan' => $data['jenis_catatan'] ?? JenisCatatanTindakan::Umum->value] + $data;
    }
}
