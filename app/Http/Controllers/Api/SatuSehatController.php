<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\KirimSatuSehat;
use App\Models\Pasien;
use App\Models\SatuSehatKirim;
use App\Services\SatuSehat\SatuSehatClient;
use App\Services\SatuSehat\SatuSehatException;
use App\Services\SatuSehat\SatuSehatService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Pemantauan & kirim ulang SATUSEHAT (PRD v2 SS-05), tes koneksi, lookup IHS pasien (PS-05). */
class SatuSehatController extends Controller
{
    public function __construct(private SatuSehatService $service) {}

    public function status(CabangAktif $cabang): JsonResponse
    {
        return response()->json($this->service->ringkasan($cabang->id()));
    }

    public function index(Request $request): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'in:menunggu,terkirim,gagal']]);

        $kirims = SatuSehatKirim::query()
            ->with(['kunjungan:id,no_registrasi,tanggal,pasien_id,dokter_id,poli_id', 'kunjungan.pasien:id,no_rm,nama', 'kunjungan.dokter:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id');

        return response()->json($this->paginate($kirims, $request, 25, 100));
    }

    public function ulang(SatuSehatKirim $kirim): JsonResponse
    {
        abort_if($kirim->status === SatuSehatKirim::TERKIRIM, 422, 'Kunjungan ini sudah terkirim.');
        $kirim->update(['status' => SatuSehatKirim::MENUNGGU, 'error' => null]);
        KirimSatuSehat::dispatch($kirim->id);

        return response()->json($kirim->refresh());
    }

    public function ulangSemua(): JsonResponse
    {
        $ids = SatuSehatKirim::query()->where('status', SatuSehatKirim::GAGAL)->pluck('id');
        SatuSehatKirim::query()->whereKey($ids)->update(['status' => SatuSehatKirim::MENUNGGU, 'error' => null]);
        $ids->each(fn ($id) => KirimSatuSehat::dispatch($id));

        return response()->json(['dijadwalkan' => $ids->count()]);
    }

    public function tesKoneksi(SatuSehatClient $client): JsonResponse
    {
        try {
            $client->token(baru: true);
        } catch (SatuSehatException $e) {
            throw ValidationException::withMessages(['koneksi' => $e->getMessage()]);
        }

        return response()->json(['message' => 'Terhubung ke SATUSEHAT ('.config('services.satusehat.env').').']);
    }

    /** Lookup IHS Number pasien via NIK (PS-05). */
    public function lookupPasien(Pasien $pasien): JsonResponse
    {
        try {
            $ihs = $this->service->ihsPasien($pasien, paksa: true);
        } catch (SatuSehatException $e) {
            throw ValidationException::withMessages(['ihs' => $e->getMessage()]);
        }

        return response()->json(['ihs_id' => $ihs, 'ihs_dicek_at' => $pasien->refresh()->ihs_dicek_at]);
    }
}
