<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatusTagihan;
use App\Http\Controllers\Controller;
use App\Models\Resep;
use App\Services\FarmasiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResepController extends Controller
{
    /** Relasi halaman detail resep; juga dipakai respons serahkan agar UI tidak perlu memuat ulang. */
    private const DETAIL = [
        'items:id,resep_id,obat_id,jumlah,aturan_pakai,harga', 'items.obat:id,nama,satuan,stok',
        'kunjungan:id,pasien_id,poli_id', 'kunjungan.pasien:id,no_rm,nama,jenis_kelamin,tanggal_lahir,alergi',
        'kunjungan.poli:id,nama', 'kunjungan.tagihan:id,kunjungan_id,no_tagihan,status',
        'dokter:id,name,sip', 'apoteker:id,name', 'cabang:id,kode,nama,alamat,telepon',
    ];

    public function index(Request $request): JsonResponse
    {
        $reseps = Resep::query()
            ->select(['id', 'no_resep', 'kunjungan_id', 'dokter_id', 'status', 'created_at'])
            ->with(['kunjungan:id,pasien_id,poli_id', 'kunjungan.pasien:id,no_rm,nama',
                'kunjungan.poli:id,nama', 'kunjungan.tagihan:id,kunjungan_id,status', 'dokter:id,name'])
            ->withCount('items')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('tanggal'), fn ($q) => $q->whereDate('created_at', $request->input('tanggal')))
            ->when($request->filled('poli_id'), fn ($q) => $q->whereHas('kunjungan', fn ($k) => $k->where('poli_id', $request->integer('poli_id'))))
            ->when($request->input('pembayaran') === 'lunas', fn ($q) => $q->whereHas('kunjungan.tagihan', fn ($t) => $t->where('status', StatusTagihan::Lunas)))
            ->when($request->input('pembayaran') === 'belum', fn ($q) => $q->whereDoesntHave('kunjungan.tagihan', fn ($t) => $t->where('status', StatusTagihan::Lunas)))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w
                    ->where('no_resep', 'like', "%{$q}%")
                    ->orWhereHas('kunjungan.pasien', fn ($p) => $p->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhere('no_rm', 'like', "{$q}%"))));
            })
            ->latest('id');

        return response()->json($this->paginate($reseps, $request));
    }

    public function show(Resep $resep): JsonResponse
    {
        return response()->json($resep->load(self::DETAIL));
    }

    public function serahkan(Request $request, Resep $resep, FarmasiService $farmasi): JsonResponse
    {
        return response()->json($farmasi->serahkan($resep, $request->user())->load(self::DETAIL));
    }

    /** Batalkan resep yang belum diserahkan (temuan teknis 8.3 #7). */
    public function batal(Request $request, Resep $resep, FarmasiService $farmasi): JsonResponse
    {
        $data = $request->validate(['alasan_batal' => ['required', 'string', 'max:255']]);

        return response()->json($farmasi->batal($resep, $data['alasan_batal'], $request->user())->load(self::DETAIL));
    }
}
