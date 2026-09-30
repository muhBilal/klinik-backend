<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\PengaturanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pengaturan klinik (PRD AD-04). Payload bertingkat sesuai kunci config('eklinik.pengaturan'):
 * `{ "klinik": { "nama": "..." }, "keamanan": { "idle_timeout_menit": 15 } }` — kunci yang tidak dikirim tidak diubah.
 */
class PengaturanController extends Controller
{
    public function __construct(private PengaturanService $pengaturan) {}

    public function index(): JsonResponse
    {
        return response()->json($this->pengaturan->semua());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate($this->pengaturan->aturanValidasi(), [
            'penomoran.*.regex' => 'Prefix nomor harus 2–5 huruf kapital, mis. REG.',
        ]);

        return response()->json($this->pengaturan->simpan($data, $request->user()));
    }

    /** Informasi publik (tanpa login): nama & kontak klinik, catatan kaki & lebar struk. */
    public function info(): JsonResponse
    {
        return response()->json($this->pengaturan->semua(hanyaPublik: true));
    }
}
