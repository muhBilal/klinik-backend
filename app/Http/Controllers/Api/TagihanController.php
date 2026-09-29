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
    /** Relasi halaman detail / struk; juga dipakai respons bayar agar UI tidak perlu memuat ulang. */
    private const DETAIL = [
        'items:id,tagihan_id,kategori,deskripsi,jumlah,harga,subtotal', 'kasir:id,name',
        'kunjungan:id,pasien_id,poli_id,dokter_id,tanggal,penjamin,status',
        'kunjungan.pasien:id,no_rm,nama', 'kunjungan.poli:id,nama', 'kunjungan.dokter:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $tagihans = Tagihan::query()
            ->select(['id', 'no_tagihan', 'kunjungan_id', 'grand_total', 'status', 'metode_bayar', 'created_at'])
            ->with(['kunjungan:id,pasien_id,poli_id,penjamin', 'kunjungan.pasien:id,no_rm,nama', 'kunjungan.poli:id,nama'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('tanggal'), fn ($q) => $q->whereDate('created_at', $request->input('tanggal')))
            ->when($request->filled('metode_bayar'), fn ($q) => $q->where('metode_bayar', $request->input('metode_bayar')))
            ->when($request->filled('penjamin') || $request->filled('poli_id'), fn ($q) => $q->whereHas('kunjungan', fn ($k) => $k
                ->when($request->filled('penjamin'), fn ($w) => $w->where('penjamin', $request->input('penjamin')))
                ->when($request->filled('poli_id'), fn ($w) => $w->where('poli_id', $request->integer('poli_id')))))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w
                    ->where('no_tagihan', 'like', "%{$q}%")
                    ->orWhereHas('kunjungan.pasien', fn ($p) => $p->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhere('no_rm', 'like', "{$q}%"))));
            })
            ->latest('id');

        return response()->json($this->paginate($tagihans, $request));
    }

    public function show(Tagihan $tagihan): JsonResponse
    {
        return response()->json($tagihan->load(self::DETAIL));
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
        )->load(self::DETAIL));
    }
}
