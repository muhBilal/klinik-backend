<?php

namespace App\Http\Controllers\Api;

use App\Enums\MetodeBayar;
use App\Http\Controllers\Controller;
use App\Models\Tagihan;
use App\Services\TagihanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TagihanController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $tagihans = Tagihan::query()
            ->with(['kunjungan:id,no_registrasi,pasien_id,poli_id,penjamin,tanggal', 'kunjungan.pasien:id,no_rm,nama',
                'kunjungan.poli:id,nama', 'kasir:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('tanggal'), fn ($q) => $q->whereDate('created_at', $request->input('tanggal')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w
                    ->where('no_tagihan', 'like', "%{$q}%")
                    ->orWhereHas('kunjungan.pasien', fn ($p) => $p->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhere('no_rm', 'like', "{$q}%"))));
            })
            ->latest('id')
            ->paginate(min($request->integer('per_page', 20), 100));

        return response()->json($tagihans);
    }

    public function show(Tagihan $tagihan): JsonResponse
    {
        return response()->json($tagihan->load(['items', 'kasir:id,name', 'kunjungan.pasien', 'kunjungan.poli', 'kunjungan.dokter:id,name']));
    }

    public function bayar(Request $request, Tagihan $tagihan, TagihanService $service): JsonResponse
    {
        $data = $request->validate([
            'metode_bayar' => ['required', Rule::enum(MetodeBayar::class)],
            'dibayar' => ['required_if:metode_bayar,tunai', 'nullable', 'integer', 'min:0'],
            'diskon' => ['nullable', 'integer', 'min:0'],
        ]);

        return response()->json($service->bayar(
            $tagihan,
            MetodeBayar::from($data['metode_bayar']),
            (int) ($data['dibayar'] ?? 0),
            (int) ($data['diskon'] ?? 0),
            $request->user(),
        ));
    }
}
