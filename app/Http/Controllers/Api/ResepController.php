<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Resep;
use App\Services\FarmasiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResepController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $reseps = Resep::query()
            ->with(['kunjungan:id,no_registrasi,pasien_id,poli_id,tanggal,status', 'kunjungan.pasien:id,no_rm,nama',
                'kunjungan.poli:id,nama', 'kunjungan.tagihan:id,kunjungan_id,status', 'dokter:id,name'])
            ->withCount('items')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('tanggal'), fn ($q) => $q->whereDate('created_at', $request->input('tanggal')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w
                    ->where('no_resep', 'like', "%{$q}%")
                    ->orWhereHas('kunjungan.pasien', fn ($p) => $p->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhere('no_rm', 'like', "{$q}%"))));
            })
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($reseps);
    }

    public function show(Resep $resep): JsonResponse
    {
        return response()->json($resep->load([
            'items.obat', 'kunjungan.pasien', 'kunjungan.poli', 'kunjungan.tagihan:id,kunjungan_id,no_tagihan,status',
            'dokter:id,name,sip', 'apoteker:id,name',
        ]));
    }

    public function serahkan(Request $request, Resep $resep, FarmasiService $farmasi): JsonResponse
    {
        return response()->json($farmasi->serahkan($resep, $request->user()));
    }
}
