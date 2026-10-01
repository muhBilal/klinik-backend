<?php

namespace App\Http\Controllers\Api;

use App\Enums\HubunganPenandatangan;
use App\Enums\JenisPersetujuanData;
use App\Enums\KanalMarketing;
use App\Enums\StatusPersetujuanData;
use App\Http\Controllers\Controller;
use App\Models\Pasien;
use App\Models\PersetujuanData;
use App\Services\AuditService;
use App\Services\InformedConsentService;
use App\Services\PersetujuanDataService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Persetujuan data pribadi UU PDP (PRD PS-04): pemrosesan data & opt-in marketing terpisah. Status dibaca pemegang pasien.lihat
 * (marketing perlu tahu siapa yang opt-in); tanda tangan & pencabutan oleh front office (pasien.kelola).
 */
class PersetujuanDataController extends Controller
{
    public function __construct(private PersetujuanDataService $service) {}

    /** `{ pemrosesan, marketing, riwayat[], kanal[] }` — tanpa naskah & tanda tangan. */
    public function index(Pasien $pasien): JsonResponse
    {
        $riwayat = PersetujuanData::where('pasien_id', $pasien->id)
            ->with(['pembuat:id,name', 'pencabut:id,name'])
            ->latest('id')
            ->get(['id', 'uuid', 'pasien_id', 'jenis', 'kanal', 'status', 'penandatangan_nama', 'hubungan', 'dibuat_oleh',
                'ditandatangani_at', 'berakhir_at', 'dicabut_oleh', 'alasan_cabut']);
        $berlaku = fn (JenisPersetujuanData $jenis) => $riwayat->first(fn (PersetujuanData $p) => $p->jenis === $jenis
            && $p->status === StatusPersetujuanData::Berlaku);

        return response()->json([
            'pemrosesan' => $berlaku(JenisPersetujuanData::Pemrosesan),
            'marketing' => $berlaku(JenisPersetujuanData::Marketing),
            'riwayat' => $riwayat,
            'kanal' => array_map(fn (KanalMarketing $k) => ['value' => $k->value, 'label' => $k->label()], KanalMarketing::cases()),
        ]);
    }

    /** Naskah yang akan ditandatangani: `{ pemrosesan, marketing }`. */
    public function pratinjau(Request $request, Pasien $pasien): JsonResponse
    {
        $data = $request->validate(['kanal' => ['array'], 'kanal.*' => [Rule::enum(KanalMarketing::class)]]);

        return response()->json([
            'pemrosesan' => $this->service->naskah($pasien, JenisPersetujuanData::Pemrosesan),
            'marketing' => $this->service->naskah($pasien, JenisPersetujuanData::Marketing, $data['kanal'] ?? []),
        ]);
    }

    public function store(Request $request, Pasien $pasien): JsonResponse
    {
        $data = $request->validate([
            'setuju_pemrosesan' => ['accepted'],
            'marketing' => ['required', 'boolean'],
            'kanal' => ['exclude_unless:marketing,true', 'required', 'array', 'min:1'],
            'kanal.*' => ['distinct', Rule::enum(KanalMarketing::class)],
            'penandatangan_nama' => ['required', 'string', 'max:150'],
            'hubungan' => ['required', Rule::enum(HubunganPenandatangan::class)],
            'ttd' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! InformedConsentService::ttdValid($value)) {
                    $fail('Tanda tangan tidak valid atau terlalu besar. Ulangi tanda tangan.');
                }
            }],
        ]);

        return response()->json($this->service->simpan($pasien, $data, $request->user()), 201);
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
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']]);

        return response()->json($this->service->cabut($persetujuanData, $data['alasan'], $request->user()));
    }
}
