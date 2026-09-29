<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatusKunjungan;
use App\Enums\StatusResep;
use App\Enums\StatusTagihan;
use App\Http\Controllers\Controller;
use App\Models\Kunjungan;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Resep;
use App\Models\Tagihan;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $today = today();

        $kunjunganHariIni = Kunjungan::whereDate('tanggal', $today)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $perPoli = Poli::where('is_active', true)
            ->withCount(['kunjungans' => fn ($q) => $q->whereDate('tanggal', $today)->where('status', '!=', StatusKunjungan::Batal)])
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama']);

        return response()->json([
            'tanggal' => $today->toDateString(),
            'kunjungan' => [
                'total' => $kunjunganHariIni->except(StatusKunjungan::Batal->value)->sum(),
                'per_status' => collect(StatusKunjungan::cases())
                    ->mapWithKeys(fn ($s) => [$s->value => (int) ($kunjunganHariIni[$s->value] ?? 0)]),
                'per_poli' => $perPoli,
            ],
            'pasien_total' => Pasien::count(),
            'pasien_baru_hari_ini' => Pasien::whereDate('created_at', $today)->count(),
            'resep_menunggu' => Resep::where('status', StatusResep::Menunggu)->count(),
            'tagihan_belum_bayar' => Tagihan::where('status', StatusTagihan::BelumBayar)->count(),
            'pendapatan_hari_ini' => (int) Tagihan::where('status', StatusTagihan::Lunas)->whereDate('dibayar_at', $today)->sum('grand_total'),
            'obat_stok_menipis' => Obat::where('is_active', true)->stokMenipis()->orderBy('stok')->limit(10)->get(['id', 'kode', 'nama', 'satuan', 'stok', 'stok_minimum']),
        ]);
    }
}
