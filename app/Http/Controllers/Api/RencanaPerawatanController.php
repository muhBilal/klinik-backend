<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pasien;
use App\Models\RencanaPerawatan;
use App\Services\RencanaPerawatanService;
use App\Support\Gigi;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rencana perawatan gigi per gigi dengan fase & estimasi biaya (PRD DG-02). Dibaca lintas cabang (milik pasien),
 * diubah di cabang penyusunnya.
 */
class RencanaPerawatanController extends Controller
{
    public function __construct(private RencanaPerawatanService $service) {}

    /** Semua rencana pasien, terbaru dulu; `?aktif=1` = hanya draf/disetujui (bisa dikerjakan). */
    public function index(Request $request, Pasien $pasien): JsonResponse
    {
        $rencanas = $pasien->rencanaPerawatans()
            ->when($request->boolean('aktif'), fn ($q) => $q->whereIn('status', ['draf', 'disetujui']))
            ->with(RencanaPerawatanService::RELASI)
            ->latest('id')
            ->get()
            ->each(fn (RencanaPerawatan $r) => $this->service->ringkas($r));

        return response()->json($rencanas);
    }

    public function store(Request $request, Pasien $pasien): JsonResponse
    {
        return response()->json($this->service->buat($pasien, $this->validated($request), $request->user()), 201);
    }

    public function show(RencanaPerawatan $rencana): JsonResponse
    {
        return response()->json($this->service->muat($rencana->load('pasien:id,no_rm,nama,jenis_kelamin,tanggal_lahir')));
    }

    public function update(Request $request, RencanaPerawatan $rencana): JsonResponse
    {
        return response()->json($this->service->ubah($rencana, $this->validated($request, false)));
    }

    public function setujui(Request $request, RencanaPerawatan $rencana): JsonResponse
    {
        $data = $request->validate(['penyetuju_nama' => ['nullable', 'string', 'max:150']]);

        return response()->json($this->service->setujui($rencana, $data['penyetuju_nama'] ?? null, $request->user()));
    }

    public function revisi(RencanaPerawatan $rencana): JsonResponse
    {
        return response()->json($this->service->revisi($rencana));
    }

    public function batal(Request $request, RencanaPerawatan $rencana): JsonResponse
    {
        $data = $request->validate(['alasan' => ['required', 'string', 'max:500']]);

        return response()->json($this->service->batal($rencana, $data['alasan'], $request->user()));
    }

    private function validated(Request $request, bool $baru = true): array
    {
        return $request->validate([
            'judul' => ['required', 'string', 'max:150'],
            'catatan' => ['nullable', 'string', 'max:2000'],
            'kunjungan_id' => $baru ? ['nullable', 'integer'] : ['prohibited'],
            'dokter_id' => ['nullable', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['nullable', 'integer', 'distinct'],
            'items.*.fase' => ['required', 'integer', 'between:1,9'],
            'items.*.gigi' => ['nullable', 'integer', fn ($attr, $nilai, $gagal) => Gigi::valid($nilai) || $gagal('Nomor gigi harus notasi FDI: 11–48 (tetap) atau 51–85 (sulung).')],
            'items.*.permukaan' => ['nullable', 'string', 'max:5', fn ($attr, $nilai, $gagal) => Gigi::permukaanValid($nilai) || $gagal('Permukaan hanya huruf M, O, D, B, L tanpa pengulangan.')],
            // Treatment nonaktif masih boleh untuk item lama; item baru dicek aktif di service.
            'items.*.tindakan_id' => ['required', Rule::exists('tindakans', 'id')],
            'items.*.jumlah' => ['nullable', 'integer', 'min:1', 'max:100'],
            'items.*.keterangan' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
