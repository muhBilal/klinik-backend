<?php

namespace App\Http\Controllers\Api;

use App\Enums\HubunganPenandatangan;
use App\Http\Controllers\Controller;
use App\Models\InformedConsent;
use App\Models\Kunjungan;
use App\Models\TemplateConsent;
use App\Services\AuditService;
use App\Services\InformedConsentService;
use App\Services\RekamMedisService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Informed consent digital per tindakan, ditandatangani pasien di tablet (PRD RM-03).
 * Ambil & cabut: izin rme.tindakan, kunjungan cabang aktif yang masih terbuka. Buka naskah + tanda tangan: rme.lihat.
 */
class InformedConsentController extends Controller
{
    public function __construct(private InformedConsentService $service) {}

    /** Naskah yang akan ditandatangani (placeholder sudah terisi). Tidak menyimpan apa pun. */
    public function pratinjau(Request $request, Kunjungan $kunjungan): JsonResponse
    {
        $data = $request->validate([
            'template_consent_id' => ['required', Rule::exists('template_consents', 'id')->whereNull('deleted_at')],
            'kunjungan_tindakan_id' => ['nullable', 'integer'],
            'dokter_id' => ['nullable', 'integer'],
        ]);

        $kunjunganTindakan = isset($data['kunjungan_tindakan_id'])
            ? $kunjungan->tindakans()->with('tindakan:id,nama')->find($data['kunjungan_tindakan_id'])
                ?? throw ValidationException::withMessages(['kunjungan_tindakan_id' => 'Tindakan tidak ada di kunjungan ini.'])
            : null;

        return response()->json($this->service->pratinjau(
            $kunjungan,
            TemplateConsent::findOrFail($data['template_consent_id']),
            $kunjunganTindakan,
            $this->service->dokterPemberiInformasi($kunjungan, $data['dokter_id'] ?? null, $request->user()),
        ));
    }

    public function store(Request $request, Kunjungan $kunjungan): JsonResponse
    {
        $ttd = function (string $attribute, mixed $value, Closure $fail) {
            if (! InformedConsentService::ttdValid($value)) {
                $fail('Tanda tangan tidak valid atau terlalu besar. Ulangi tanda tangan.');
            }
        };

        $data = $request->validate([
            'template_consent_id' => ['required', Rule::exists('template_consents', 'id')->whereNull('deleted_at')],
            'kunjungan_tindakan_id' => ['nullable', 'integer'],
            'keputusan' => ['required', Rule::in(['setuju', 'tolak'])],
            'penandatangan_nama' => ['required', 'string', 'max:150'],
            'hubungan' => ['required', Rule::enum(HubunganPenandatangan::class)],
            'ttd_penandatangan' => ['required', 'string', $ttd],
            'saksi_nama' => ['nullable', 'required_with:ttd_saksi', 'string', 'max:150'],
            'ttd_saksi' => ['nullable', 'string', $ttd],
            'dokter_id' => ['nullable', 'integer'],
        ]);

        $consent = $this->service->simpan($kunjungan, $data, $request->user());

        return response()->json($consent->load(['dokter:id,name', 'pembuat:id,name']), 201);
    }

    /**
     * Naskah & tanda tangan lengkap (untuk dilihat/dicetak). Lintas cabang (riwayat pasien), tunduk pada akses terbatas.
     * Setiap pembukaan tercatat di audit log.
     */
    public function show(Request $request, InformedConsent $informedConsent, RekamMedisService $rekamMedis, AuditService $audit): JsonResponse
    {
        abort_unless($rekamMedis->bolehLihat($request->user(), $informedConsent->kunjungan), 403,
            'Rekam medis kunjungan ini berakses terbatas.');

        $audit->catat('lihat', 'informed_consent', $informedConsent->id, [
            'pasien_id' => $informedConsent->pasien_id, 'label' => $informedConsent->auditLabel(),
        ]);

        $informedConsent->load([
            'pasien:id,no_rm,nama,tanggal_lahir,jenis_kelamin', 'kunjungan:id,cabang_id,no_registrasi,tanggal',
            'kunjungan.cabang:id,kode,nama,alamat,telepon', 'dokter:id,name,sip', 'pembuat:id,name', 'pencabut:id,name',
        ]);

        return response()->json([
            ...$informedConsent->makeVisible(['ttd_penandatangan', 'ttd_saksi'])->toArray(),
            'checksum_valid' => $informedConsent->checksumValid(),
        ]);
    }

    public function cabut(Request $request, InformedConsent $informedConsent): JsonResponse
    {
        // Hanya kunjungan cabang aktif (scope cabang) yang boleh diubah.
        Kunjungan::findOrFail($informedConsent->kunjungan_id);

        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']]);

        return response()->json($this->service->cabut($informedConsent, $data['alasan'], $request->user()));
    }
}
