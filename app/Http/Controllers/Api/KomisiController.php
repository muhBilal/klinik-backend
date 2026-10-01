<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
use App\Http\Controllers\Controller;
use App\Services\KomisiService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Rekap, rincian (slip), hitung ulang & persetujuan komisi per periode (PRD KM-01, KM-03).
 */
class KomisiController extends Controller
{
    public function __construct(private KomisiService $service) {}

    /** Rekap semua petugas di cabang aktif (atau semua cabang untuk pengguna lintas cabang tanpa pilihan cabang). */
    public function rekap(Request $request, CabangAktif $cabang): JsonResponse
    {
        $periode = $this->periode($request);

        return response()->json($this->service->rekap($cabang->id(), $periode));
    }

    /** Rincian satu petugas. Petugas boleh melihat slipnya sendiri; petugas lain butuh `komisi.kelola`. */
    public function rincian(Request $request, CabangAktif $cabang): JsonResponse
    {
        $periode = $this->periode($request);
        $request->validate(['user_id' => ['nullable', 'integer']]);
        $userId = $request->integer('user_id') ?: $request->user()->id;

        abort_if($userId !== $request->user()->id && ! $request->user()->punyaIzin(Izin::KomisiKelola), 403,
            'Anda hanya boleh melihat komisi sendiri.');

        // Slip sendiri mencakup semua cabang tempat petugas bertugas.
        $cabangId = $userId === $request->user()->id && ! $request->user()->punyaIzin(Izin::KomisiKelola) ? null : $cabang->id();

        return response()->json([
            ...$this->service->rekap($cabangId, $periode, $userId),
            'rincian' => $this->service->rincian($cabangId, $periode, $userId),
        ]);
    }

    public function hitungUlang(Request $request, CabangAktif $cabang): JsonResponse
    {
        $periode = $this->periode($request);
        $jumlah = $this->service->hitungUlang($cabang->untukDataBaru(), $periode);

        return response()->json([...$this->service->rekap($cabang->untukDataBaru(), $periode), 'baris' => $jumlah]);
    }

    public function setujui(Request $request, CabangAktif $cabang): JsonResponse
    {
        $periode = $this->periode($request);
        $request->validate(['catatan' => ['nullable', 'string', 'max:255']]);

        $this->service->setujui($cabang->untukDataBaru(), $periode, $request->user(), $request->input('catatan'));

        return response()->json($this->service->rekap($cabang->untukDataBaru(), $periode));
    }

    private function periode(Request $request): string
    {
        $data = $request->validate(['periode' => ['required', 'date_format:Y-m']]);

        return $data['periode'];
    }
}
