<?php

namespace App\Http\Controllers\Api;

use App\Enums\MetodeBayar;
use App\Http\Controllers\Controller;
use App\Models\Tagihan;
use App\Services\KasirService;
use App\Services\TagihanService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TagihanController extends Controller
{
    /** Relasi halaman detail / struk; juga dipakai respons bayar agar UI tidak perlu memuat ulang. */
    private const DETAIL = [
        'items:id,tagihan_id,kategori,deskripsi,jumlah,harga,subtotal', 'kasir:id,name',
        'pembayarans:id,tagihan_id,metode,jumlah,referensi,dibayar_at,dikembalikan_at,alasan_refund',
        'pasien:id,no_rm,nama',
        'cabang:id,kode,nama,alamat,telepon',
        'kunjungan:id,pasien_id,poli_id,dokter_id,tanggal,penjamin,status',
        'kunjungan.pasien:id,no_rm,nama', 'kunjungan.poli:id,nama', 'kunjungan.dokter:id,name',
    ];

    public function index(Request $request): JsonResponse
    {
        $tagihans = Tagihan::query()
            ->select(['id', 'no_tagihan', 'kunjungan_id', 'pasien_id', 'total', 'diskon', 'pajak', 'grand_total',
                'status', 'metode_bayar', 'keterangan', 'created_at'])
            ->with(['kunjungan:id,pasien_id,poli_id,penjamin', 'kunjungan.pasien:id,no_rm,nama',
                'kunjungan.poli:id,nama', 'pasien:id,no_rm,nama'])
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
                    ->orWhereHas('kunjungan.pasien', fn ($p) => $p->where(fn ($x) => $x->whereLike('nama', "%{$q}%")->orWhere('no_rm', 'like', "{$q}%")))
                    ->orWhereHas('pasien', fn ($p) => $p->where(fn ($x) => $x->whereLike('nama', "%{$q}%")->orWhere('no_rm', 'like', "{$q}%"))));
            })
            ->latest('id');

        return response()->json($this->paginate($tagihans, $request));
    }

    public function show(Tagihan $tagihan): JsonResponse
    {
        return response()->json($tagihan->load(self::DETAIL));
    }

    /**
     * Bayar tagihan. `pembayarans[]` = split payment (BL-03); bentuk lama
     * (`metode_bayar` + `dibayar`) tetap diterima agar klien lama tidak rusak.
     */
    public function bayar(Request $request, Tagihan $tagihan, KasirService $kasir): JsonResponse
    {
        $data = $request->validate([
            'pembayarans' => ['sometimes', 'array', 'min:1', 'max:5'],
            'pembayarans.*.metode' => ['required', Rule::enum(MetodeBayar::class)],
            'pembayarans.*.jumlah' => ['required', 'integer', 'min:1'],
            'pembayarans.*.referensi' => ['nullable', 'string', 'max:100'],

            'metode_bayar' => ['required_without:pembayarans', Rule::enum(MetodeBayar::class)],
            'dibayar' => ['required_if:metode_bayar,tunai', 'nullable', 'integer', 'min:0'],
            'diskon' => ['nullable', 'integer', 'min:0'],
        ]);

        $diskon = (int) ($data['diskon'] ?? 0);

        $adaSplit = array_key_exists('pembayarans', $data);

        $pembayarans = $data['pembayarans'] ?? [[
            'metode' => $data['metode_bayar'],
            // Non-tunai dibayar pas; tunai memakai nominal yang diserahkan pasien.
            'jumlah' => $data['metode_bayar'] === MetodeBayar::Tunai->value
                ? (int) ($data['dibayar'] ?? 0)
                : $kasir->hitungGrandTotal($tagihan->total, $diskon, $tagihan->pajak_persen),
        ]];

        return response()->json($kasir
            ->bayar($tagihan, $pembayarans, $diskon, $request->user(), $adaSplit ? 'pembayarans' : 'dibayar')
            ->load(self::DETAIL));
    }

    /** Batalkan tagihan yang belum dibayar (BL-06, temuan 8.3 #7). */
    public function batal(Request $request, Tagihan $tagihan, KasirService $kasir): JsonResponse
    {
        $data = $request->validate(['alasan_batal' => ['required', 'string', 'max:255']]);

        return response()->json($kasir->batal($tagihan, $data['alasan_batal'], $request->user())->load(self::DETAIL));
    }

    /** Refund tagihan lunas (BL-06). */
    public function refund(Request $request, Tagihan $tagihan, KasirService $kasir): JsonResponse
    {
        $data = $request->validate(['alasan_refund' => ['required', 'string', 'max:255']]);

        return response()->json($kasir->refund($tagihan, $data['alasan_refund'], $request->user())->load(self::DETAIL));
    }

    /**
     * Tagihan tanpa kunjungan: penjualan produk OTC, paket, deposit (FR-04, TR-02, 8.3 #3).
     */
    public function store(Request $request, TagihanService $service, CabangAktif $cabang): JsonResponse
    {
        $data = $request->validate([
            'pasien_id' => ['nullable', Rule::exists('pasiens', 'id')->whereNull('deleted_at')],
            'keterangan' => ['nullable', 'string', 'max:255'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.kategori' => ['required', 'string', 'in:produk,paket,deposit,lainnya'],
            'items.*.deskripsi' => ['required', 'string', 'max:255'],
            'items.*.jumlah' => ['required', 'integer', 'min:1'],
            'items.*.harga' => ['required', 'integer', 'min:0'],
        ]);

        $tagihan = $service->buatMandiri(
            $cabang->untukDataBaru(), $data['pasien_id'] ?? null, $data['items'], $data['keterangan'] ?? null,
        );

        return response()->json($tagihan->load(self::DETAIL), 201);
    }
}
