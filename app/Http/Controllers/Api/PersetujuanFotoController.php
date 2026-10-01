<?php

namespace App\Http\Controllers\Api;

use App\Enums\HubunganPenandatangan;
use App\Enums\TingkatPersetujuanFoto;
use App\Http\Controllers\Controller;
use App\Models\Pasien;
use App\Models\PersetujuanFoto;
use App\Services\AuditService;
use App\Services\InformedConsentService;
use App\Services\PersetujuanFotoService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Consent foto klinis bertingkat (PRD FT-04). Status dibaca pemegang pasien.lihat (petugas perlu tahu boleh memotret);
 * tanda tangan & pencabutan oleh front office (pasien.kelola) atau tenaga tindakan (rme.tindakan).
 */
class PersetujuanFotoController extends Controller
{
    public function __construct(private PersetujuanFotoService $service) {}

    /** `{ aktif, riwayat[], tingkat[] }` — riwayat tanpa naskah & tanda tangan. */
    public function index(Pasien $pasien): JsonResponse
    {
        $riwayat = PersetujuanFoto::where('pasien_id', $pasien->id)
            ->with(['pembuat:id,name', 'pencabut:id,name'])
            ->latest('id')
            ->get(['id', 'uuid', 'pasien_id', 'tingkat', 'status', 'penandatangan_nama', 'hubungan', 'dibuat_oleh',
                'ditandatangani_at', 'berakhir_at', 'dicabut_oleh', 'alasan_cabut']);

        return response()->json([
            'aktif' => $riwayat->first(fn (PersetujuanFoto $p) => $p->status->value === 'berlaku'),
            'riwayat' => $riwayat,
            'tingkat' => array_map(fn (TingkatPersetujuanFoto $t) => [
                'value' => $t->value, 'label' => $t->label(), 'keterangan' => $t->keterangan(),
            ], TingkatPersetujuanFoto::cases()),
        ]);
    }

    public function pratinjau(Request $request, Pasien $pasien): JsonResponse
    {
        $data = $request->validate(['tingkat' => ['required', Rule::enum(TingkatPersetujuanFoto::class)]]);

        return response()->json(['isi' => $this->service->naskah($pasien, TingkatPersetujuanFoto::from($data['tingkat']))]);
    }

    public function store(Request $request, Pasien $pasien): JsonResponse
    {
        $data = $request->validate([
            'tingkat' => ['required', Rule::enum(TingkatPersetujuanFoto::class)],
            'penandatangan_nama' => ['required', 'string', 'max:150'],
            'hubungan' => ['required', Rule::enum(HubunganPenandatangan::class)],
            'ttd' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                if (! InformedConsentService::ttdValid($value)) {
                    $fail('Tanda tangan tidak valid atau terlalu besar. Ulangi tanda tangan.');
                }
            }],
            'kunjungan_id' => ['nullable', 'integer'],
        ]);

        return response()->json($this->service->simpan($pasien, $data, $request->user())->load('pembuat:id,name'), 201);
    }

    /** Naskah + tanda tangan (lihat/cetak). Tercatat audit. */
    public function show(PersetujuanFoto $persetujuanFoto, AuditService $audit): JsonResponse
    {
        $audit->catat('lihat', 'persetujuan_foto', $persetujuanFoto->id, [
            'pasien_id' => $persetujuanFoto->pasien_id, 'label' => $persetujuanFoto->auditLabel(),
        ]);

        $persetujuanFoto->load(['pasien:id,no_rm,nama,tanggal_lahir', 'cabang:id,kode,nama,alamat', 'pembuat:id,name', 'pencabut:id,name']);

        return response()->json([
            ...$persetujuanFoto->makeVisible('ttd')->toArray(),
            'checksum_valid' => $persetujuanFoto->checksumValid(),
        ]);
    }

    public function cabut(Request $request, PersetujuanFoto $persetujuanFoto): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']]);

        return response()->json($this->service->cabut($persetujuanFoto, $data['alasan'], $request->user()));
    }
}
