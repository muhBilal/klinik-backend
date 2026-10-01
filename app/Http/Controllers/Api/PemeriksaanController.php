<?php

namespace App\Http\Controllers\Api;

use App\Enums\BagianAddendum;
use App\Http\Controllers\Controller;
use App\Models\Kunjungan;
use App\Services\PemeriksaanService;
use App\Services\RekamMedisService;
use App\Support\Gigi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PemeriksaanController extends Controller
{
    public function __construct(private PemeriksaanService $service) {}

    public function update(Request $request, Kunjungan $kunjungan): JsonResponse
    {
        $data = $request->validate([
            'tekanan_darah' => ['nullable', 'string', 'regex:/^\d{2,3}\/\d{2,3}$/'],
            'nadi' => ['nullable', 'integer', 'between:20,250'],
            'suhu' => ['nullable', 'numeric', 'between:30,45'],
            'respirasi' => ['nullable', 'integer', 'between:5,80'],
            'berat_badan' => ['nullable', 'numeric', 'between:0.5,400'],
            'tinggi_badan' => ['nullable', 'numeric', 'between:20,250'],
            'subjektif' => ['nullable', 'string', 'max:5000'],
            'objektif' => ['nullable', 'string', 'max:5000'],
            'asesmen' => ['nullable', 'string', 'max:5000'],
            'plan' => ['nullable', 'string', 'max:5000'],
            'akses_terbatas' => ['sometimes', 'boolean'],

            'diagnosas' => ['sometimes', 'array'],
            'diagnosas.*.icd10_id' => ['required', 'distinct', 'exists:icd10s,id'],
            'diagnosas.*.jenis' => ['nullable', Rule::in(['primer', 'sekunder'])],

            'tindakans' => ['sometimes', 'array'],
            'tindakans.*.id' => ['nullable', 'integer', 'distinct'],
            'tindakans.*.tindakan_id' => ['required', Rule::exists('tindakans', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'tindakans.*.jumlah' => ['nullable', 'integer', 'min:1', 'max:100'],
            'tindakans.*.keterangan' => ['nullable', 'string', 'max:255'],
            'tindakans.*.petugas_id' => ['nullable', 'integer'],
            'tindakans.*.icd9cm_id' => ['nullable', Rule::exists('icd9cms', 'id')],
            // Tindakan per gigi (DG-07) & item rencana perawatan yang dikerjakan (DG-02)
            'tindakans.*.gigi' => ['nullable', 'integer', fn ($attr, $nilai, $gagal) => Gigi::valid($nilai) || $gagal('Nomor gigi harus notasi FDI: 11–48 (tetap) atau 51–85 (sulung).')],
            'tindakans.*.permukaan' => ['nullable', 'string', 'max:5', fn ($attr, $nilai, $gagal) => Gigi::permukaanValid($nilai) || $gagal('Permukaan hanya huruf M, O, D, B, L tanpa pengulangan.')],
            'tindakans.*.rencana_item_id' => ['nullable', 'integer', 'distinct'],
            // Sesi paket pasien yang dipakai (TR-02) → ditagih Rp 0
            'tindakans.*.paket_pasien_item_id' => ['nullable', 'integer'],

            'resep' => ['sometimes', 'array'],
            'resep.*.obat_id' => ['required', 'distinct', Rule::exists('obats', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'resep.*.jumlah' => ['required', 'integer', 'min:1', 'max:1000'],
            'resep.*.aturan_pakai' => ['required', 'string', 'max:255'],
            'catatan_resep' => ['nullable', 'string', 'max:1000'],
        ], [
            'tekanan_darah.regex' => 'Format tekanan darah harus sistolik/diastolik, contoh 120/80.',
        ]);

        return response()->json($this->service->simpan($kunjungan, $data, $request->user()));
    }

    public function selesai(Request $request, Kunjungan $kunjungan): JsonResponse
    {
        return response()->json($this->service->selesai($kunjungan, $request->user()));
    }

    /** Koreksi rekam medis yang sudah ditandatangani (RM-07). Kunjungan cabang aktif. */
    public function addendum(Request $request, Kunjungan $kunjungan, RekamMedisService $rekamMedis): JsonResponse
    {
        abort_unless($rekamMedis->bolehLihat($request->user(), $kunjungan), 403, 'Rekam medis kunjungan ini berakses terbatas.');

        $data = $request->validate([
            'bagian' => ['required', Rule::enum(BagianAddendum::class)],
            'isi' => ['required', 'string', 'max:5000'],
            'alasan' => ['required', 'string', 'max:500'],
        ]);

        return response()->json($rekamMedis->tambahAddendum($kunjungan, $data, $request->user()), 201);
    }

    /** Cocokkan hash tanda tangan dengan isi rekam medis saat ini (lintas cabang, tunduk akses terbatas). */
    public function verifikasi(Request $request, int $kunjungan, RekamMedisService $rekamMedis): JsonResponse
    {
        $kunjungan = Kunjungan::withoutGlobalScope('cabang')->findOrFail($kunjungan);
        abort_unless($rekamMedis->bolehLihat($request->user(), $kunjungan), 403, 'Rekam medis kunjungan ini berakses terbatas.');

        return response()->json($rekamMedis->verifikasi($kunjungan));
    }
}
