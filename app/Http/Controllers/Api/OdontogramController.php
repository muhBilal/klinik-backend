<?php

namespace App\Http\Controllers\Api;

use App\Enums\KondisiGigi;
use App\Http\Controllers\Controller;
use App\Models\Kunjungan;
use App\Models\OdontogramKondisi;
use App\Models\Pasien;
use App\Services\OdontogramService;
use App\Services\RekamMedisService;
use App\Support\Gigi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Odontogram interaktif notasi FDI (PRD DG-01). Dibaca per pasien (lintas cabang); diubah di kunjungan cabang aktif yang
 * pasiennya sedang diperiksa. Setiap perubahan mengembalikan data odontogram kunjungan itu (bentuk sama dengan `show`).
 */
class OdontogramController extends Controller
{
    public function __construct(
        private OdontogramService $service,
        private RekamMedisService $rekamMedis,
    ) {}

    /** Kode kondisi, label, cakupan, kelompok & warna — satu sumber untuk frontend. */
    public function referensi(): JsonResponse
    {
        return response()->json([
            'kondisi' => KondisiGigi::referensi(),
            'permukaan' => Gigi::PERMUKAAN,
        ]);
    }

    /** `?kunjungan_id=` = status odontogram pada kunjungan itu + perubahannya; tanpa = status terkini. */
    public function show(Request $request, Pasien $pasien): JsonResponse
    {
        $kunjungan = null;
        if ($request->filled('kunjungan_id')) {
            $kunjungan = Kunjungan::withoutGlobalScope('cabang')->where('pasien_id', $pasien->id)->findOrFail($request->integer('kunjungan_id'));
            abort_unless($this->rekamMedis->bolehLihat($request->user(), $kunjungan), 403, 'Rekam medis kunjungan ini berakses terbatas.');
        }

        $this->service->catatAkses($pasien, $kunjungan);

        return response()->json($this->service->data($pasien, $request->user(), $kunjungan));
    }

    public function store(Request $request, Kunjungan $kunjungan): JsonResponse
    {
        $this->pastikanBolehLihat($request, $kunjungan);

        $data = $request->validate([
            'gigi' => ['required', 'integer', fn ($attr, $nilai, $gagal) => Gigi::valid($nilai) || $gagal('Nomor gigi harus notasi FDI: 11–48 (tetap) atau 51–85 (sulung).')],
            'permukaan' => ['nullable', 'array', 'max:5'],
            'permukaan.*' => ['string', Rule::in(Gigi::PERMUKAAN)],
            'kondisi' => ['required', Rule::enum(KondisiGigi::class)],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        $this->service->tetapkan($kunjungan, $data, $request->user());

        return response()->json($this->respons($request, $kunjungan), 201);
    }

    public function destroy(Request $request, Kunjungan $kunjungan, OdontogramKondisi $kondisi): JsonResponse
    {
        $this->pastikanBolehLihat($request, $kunjungan);
        abort_unless($kondisi->pasien_id === (int) $kunjungan->pasien_id, 404);

        $this->service->hapus($kunjungan, $kondisi);

        return response()->json($this->respons($request, $kunjungan));
    }

    public function akhiri(Request $request, Kunjungan $kunjungan, OdontogramKondisi $kondisi): JsonResponse
    {
        $this->pastikanBolehLihat($request, $kunjungan);
        $this->service->akhiri($kunjungan, $kondisi, $request->user());

        return response()->json($this->respons($request, $kunjungan));
    }

    public function pulihkan(Request $request, Kunjungan $kunjungan, OdontogramKondisi $kondisi): JsonResponse
    {
        $this->pastikanBolehLihat($request, $kunjungan);
        $this->service->pulihkan($kunjungan, $kondisi);

        return response()->json($this->respons($request, $kunjungan));
    }

    private function respons(Request $request, Kunjungan $kunjungan): array
    {
        return $this->service->data($kunjungan->pasien, $request->user(), $kunjungan->refresh());
    }

    private function pastikanBolehLihat(Request $request, Kunjungan $kunjungan): void
    {
        abort_unless($this->rekamMedis->bolehLihat($request->user(), $kunjungan), 403, 'Rekam medis kunjungan ini berakses terbatas.');
    }
}
