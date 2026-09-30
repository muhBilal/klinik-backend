<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
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
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * Ringkasan hari ini untuk cabang aktif (semua cabang bila user lintas cabang tanpa pilihan cabang).
     * Pasien & obat bersifat pusat (belum per cabang).
     */
    public function __invoke(Request $request, CabangAktif $cabang): JsonResponse
    {
        $today = today();

        $kunjunganHariIni = Kunjungan::whereDate('tanggal', $today)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $perPoli = Poli::where('is_active', true)
            ->withCount(['kunjungans' => fn ($q) => $q->whereDate('tanggal', $today)->where('status', '!=', StatusKunjungan::Batal)])
            ->orderBy('nama')
            ->get(['id', 'nama']);

        return response()->json([
            'tanggal' => $today->toDateString(),
            'cabang_id' => $cabang->id(),
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
            // Hanya untuk pemegang izin laporan.keuangan; peran lain tidak perlu menjalankan query-nya.
            'pendapatan_hari_ini' => $request->user()->punyaIzin(Izin::LaporanKeuangan)
                ? (int) Tagihan::where('status', StatusTagihan::Lunas)->whereDate('dibayar_at', $today)->sum('grand_total')
                : null,
            'obat_stok_menipis' => Obat::where('is_active', true)->stokMenipis()->orderBy('stok')->limit(10)->get(['id', 'nama', 'satuan', 'stok']),
        ]);
    }
}
