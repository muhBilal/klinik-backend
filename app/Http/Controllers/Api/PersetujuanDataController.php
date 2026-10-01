<?php

namespace App\Http\Controllers\Api;

use App\Enums\HubunganPenandatangan;
use App\Enums\JenisPersetujuanData;
use App\Http\Controllers\Controller;
use App\Models\Pasien;
use App\Models\PersetujuanData;
use App\Services\AuditService;
use App\Services\InformedConsentService;
use App\Services\PengaturanService;
use App\Services\PersetujuanDataService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Consent UU PDP (PRD PS-04): pemrosesan data & opt-in marketing, terpisah. Status dibaca pemegang `pasien.lihat`;
 * tanda tangan & pencabutan oleh front office (`pasien.kelola`) atau tenaga tindakan (`rme.tindakan`).
 */
class PersetujuanDataController extends Controller
{
    public function __construct(private PersetujuanDataService $service) {}

    /** `{ pemrosesan, marketing, riwayat[], wajib }` — riwayat tanpa naskah & tanda tangan. */
    public function index(Pasien $pasien, PengaturanService $pengaturan): JsonResponse
    {
        $riwayat = PersetujuanData::where('pasien_id', $pasien->id)
            ->with(['pembuat:id,name', 'pencabut:id,name'])
            ->latest('id')
            ->get(['id', 'uuid', 'pasien_id', 'jenis', 'setuju', 'status', 'penandatangan_nama', 'hubungan', 'dibuat_oleh',
                'ditandatangani_at', 'berakhir_at', 'dicabut_oleh', 'alasan_cabut']);

        $aktif = fn (JenisPersetujuanData $j) => $riwayat->first(fn ($p) => $p->jenis === $j && $p->status->value === 'berlaku');

        return response()->json([
            'pemrosesan' => $aktif(JenisPersetujuanData::Pemrosesan),
            'marketing' => $aktif(JenisPersetujuanData::Marketing),
            'riwayat' => $riwayat,
            'wajib' => (bool) $pengaturan->get('pdp.wajib_consent'),
        ]);
    }

    public function pratinjau(Request $request, Pasien $pasien): JsonResponse
    {
        $data = $request->validate([
            'jenis' => ['required', Rule::enum(JenisPersetujuanData::class)],
            'setuju' => ['required', 'boolean'],
        ]);

        return response()->json(['isi' => $this->service->naskah($pasien, JenisPersetujuanData::from($data['jenis']), (bool) $data['setuju'])]);
    }

    public function store(Request $request, Pasien $pasien): JsonResponse
    {
        $data = $request->validate([
            'jenis' => ['required', Rule::enum(JenisPersetujuanData::class)],
            'setuju' => ['required', 'boolean'],
            'penandatangan_nama' => ['required', 'string', 'max:150'],
            'hubungan' => ['required', Rule::enum(HubunganPenandatangan::class)],
            'ttd' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! InformedConsentService::ttdValid($value)) {
                    $fail('Tanda tangan tidak valid atau terlalu besar. Ulangi tanda tangan.');
                }
            }],
        ]);

        return response()->json($this->service->simpan($pasien, $data, $request->user())->load('pembuat:id,name'), 201);
    }

    /** Naskah + tanda tangan (lihat/cetak). Tercatat audit. */
    public function show(PersetujuanData $persetujuanData, AuditService $audit): JsonResponse
    {
        $audit->catat('lihat', 'persetujuan_data', $persetujuanData->id, [
            'pasien_id' => $persetujuanData->pasien_id, 'label' => $persetujuanData->auditLabel(),
        ]);

        $persetujuanData->load(['pasien:id,no_rm,nama,tanggal_lahir', 'cabang:id,kode,nama,alamat', 'pembuat:id,name', 'pencabut:id,name']);

        return response()->json([
            ...$persetujuanData->makeVisible('ttd')->toArray(),
            'checksum_valid' => $persetujuanData->checksumValid(),
        ]);
    }

    public function cabut(Request $request, PersetujuanData $persetujuanData): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:255']]);

        return response()->json($this->service->cabut($persetujuanData, $data['alasan'], $request->user()));
    }
}
