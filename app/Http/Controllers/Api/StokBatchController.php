<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Obat;
use App\Models\StokBatch;
use App\Services\InventoriService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Stok per batch & kedaluwarsa (PRD IN-01, IN-03, IN-05 sebagian).
 */
class StokBatchController extends Controller
{
    public function __construct(private InventoriService $service) {}

    public function index(Request $request, CabangAktif $cabangAktif): JsonResponse
    {
        $batches = StokBatch::query()
            ->select(['id', 'obat_id', 'cabang_id', 'no_batch', 'kedaluwarsa', 'jumlah', 'jumlah_awal',
                'dibuka_at', 'kedaluwarsa_dibuka_at'])
            ->with(['obat:id,kode,nama,satuan,fraksional', 'cabang:id,kode,nama'])
            ->when($request->filled('obat_id'), fn ($q) => $q->where('obat_id', $request->integer('obat_id')))
            ->when($request->boolean('tersedia'), fn ($q) => $q->tersedia())
            ->when($request->boolean('habis'), fn ($q) => $q->where('jumlah', '<=', 0))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w
                    ->whereLike('no_batch', "%{$q}%")
                    ->orWhereHas('obat', fn ($o) => $o->where(fn ($x) => $x
                        ->whereLike('nama', "%{$q}%")->orWhereLike('kode', "{$q}%"))));
            })
            ->urutFefo();

        return response()->json($this->paginate($batches, $request, 50, 200));
    }

    /** Batch yang sudah / akan kedaluwarsa dalam `?hari=` hari (default 30). */
    public function kedaluwarsa(Request $request, CabangAktif $cabangAktif): JsonResponse
    {
        $request->validate(['hari' => ['nullable', 'integer', 'between:0,365']]);

        return response()->json(
            $this->service->akanKedaluwarsa($cabangAktif->id(), $request->integer('hari') ?: 30),
        );
    }

    /** Penerimaan barang ke satu batch. */
    public function store(Request $request, CabangAktif $cabang): JsonResponse
    {
        $data = $request->validate([
            'obat_id' => ['required', Rule::exists('obats', 'id')->whereNull('deleted_at')],
            'jumlah' => ['required', 'numeric', 'gt:0', 'max:999999', 'decimal:0,3'],
            'no_batch' => ['nullable', 'string', 'max:50'],
            'kedaluwarsa' => ['nullable', 'date', 'after:today'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        $batch = $this->service->terima(
            Obat::findOrFail($data['obat_id']),
            $cabang->untukDataBaru(),
            (float) $data['jumlah'],
            $data['no_batch'] ?? null,
            isset($data['kedaluwarsa']) ? $request->date('kedaluwarsa') : null,
            $request->user(),
            $data['keterangan'] ?? null,
        );

        return response()->json($batch->load('obat:id,kode,nama,satuan'), 201);
    }

    /** Stok opname satu batch: set jumlah ke hasil hitung fisik. */
    public function sesuaikan(Request $request, StokBatch $stokBatch): JsonResponse
    {
        $data = $request->validate([
            'jumlah' => ['required', 'numeric', 'min:0', 'max:999999', 'decimal:0,3'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        $batch = $this->service->sesuaikan(
            $stokBatch, (float) $data['jumlah'], $request->user(), $data['keterangan'] ?? null,
        );

        return response()->json($batch->load('obat:id,kode,nama,satuan'));
    }

    /** Buang sisa batch kedaluwarsa. */
    public function buang(Request $request, StokBatch $stokBatch): JsonResponse
    {
        $data = $request->validate(['keterangan' => ['nullable', 'string', 'max:255']]);

        $batch = $this->service->buangKedaluwarsa($stokBatch, $request->user(), $data['keterangan'] ?? null);

        return response()->json($batch->load('obat:id,kode,nama,satuan'));
    }
}
