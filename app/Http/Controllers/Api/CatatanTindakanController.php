<?php

namespace App\Http\Controllers\Api;

use App\Enums\JenisCatatanTindakan;
use App\Http\Controllers\Controller;
use App\Models\CatatanTindakan;
use App\Models\CatatanTindakanTitik;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Services\CatatanTindakanService;
use App\Services\RekamMedisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Catatan tindakan (PRD RM-05): area, parameter laser/energy device (ES-02), face chart injeksi (ES-01), petugas.
 */
class CatatanTindakanController extends Controller
{
    public function __construct(private CatatanTindakanService $service) {}

    /** Catatan tindakan (null bila belum diisi) + jenis form dari katalog treatment. */
    public function show(Request $request, KunjunganTindakan $kunjunganTindakan, RekamMedisService $rekamMedis): JsonResponse
    {
        $kunjungan = Kunjungan::withoutGlobalScope('cabang')->findOrFail($kunjunganTindakan->kunjungan_id);
        abort_unless($rekamMedis->bolehLihat($request->user(), $kunjungan), 403, 'Rekam medis kunjungan ini berakses terbatas.');

        $kunjunganTindakan->load(['tindakan:id,nama,jenis_catatan', 'petugas:id,name', 'catatan' => fn ($q) => $q->with(CatatanTindakanService::RELASI)]);

        return response()->json([
            'kunjungan_tindakan' => $kunjunganTindakan->only(['id', 'kunjungan_id', 'tindakan_id', 'jumlah', 'petugas_id']) + [
                'tindakan' => $kunjunganTindakan->tindakan, 'petugas' => $kunjunganTindakan->petugas,
            ],
            'jenis' => $kunjunganTindakan->catatan?->jenis ?? $kunjunganTindakan->tindakan?->jenis_catatan ?? JenisCatatanTindakan::Umum,
            'catatan' => $kunjunganTindakan->catatan,
            'terkunci' => ! $kunjungan->terbuka(),
        ]);
    }

    /** Upsert catatan; `titiks` replace-all bila dikirim. Hanya selama pemeriksaan terbuka di cabang aktif. */
    public function update(Request $request, KunjunganTindakan $kunjunganTindakan): JsonResponse
    {
        $parameter = collect(CatatanTindakan::PARAMETER)->mapWithKeys(fn ($rules, $key) => ["parameter.{$key}" => $rules])->all();

        $data = $request->validate([
            'jenis' => ['nullable', Rule::enum(JenisCatatanTindakan::class)],
            'area' => ['nullable', 'string', 'max:255'],
            'catatan' => ['nullable', 'string', 'max:5000'],
            'sumber_daya_id' => ['nullable', 'integer'],
            'petugas_id' => ['sometimes', 'nullable', 'integer'],
            'parameter' => ['nullable', 'array'],
            ...$parameter,

            'titiks' => ['sometimes', 'array', 'max:200'],
            'titiks.*.tampilan' => ['nullable', Rule::in(CatatanTindakanTitik::TAMPILAN)],
            'titiks.*.x' => ['required', 'numeric', 'between:0,1'],
            'titiks.*.y' => ['required', 'numeric', 'between:0,1'],
            'titiks.*.area' => ['nullable', 'string', 'max:100'],
            'titiks.*.obat_id' => ['nullable', Rule::exists('obats', 'id')],
            'titiks.*.batch_id' => ['nullable', 'integer'],
            'titiks.*.jumlah' => ['nullable', 'numeric', 'min:0', 'max:10000', 'decimal:0,3'],
            'titiks.*.satuan' => ['nullable', Rule::in(CatatanTindakanTitik::SATUAN)],
            'titiks.*.kedalaman' => ['nullable', 'string', 'max:30'],
            'titiks.*.alat' => ['nullable', 'string', 'max:50'],
            'titiks.*.catatan' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->service->simpan($kunjunganTindakan, $data, $request->user()));
    }
}
