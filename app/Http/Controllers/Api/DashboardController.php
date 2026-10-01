<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
use App\Enums\StatusAppointment;
use App\Enums\StatusKunjungan;
use App\Enums\StatusResep;
use App\Enums\StatusTagihan;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Cabang;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Resep;
use App\Models\Tagihan;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    /**
     * Ringkasan hari ini (PRD LP-01) untuk cabang aktif (semua cabang bila user lintas cabang tanpa pilihan cabang): kunjungan,
     * booking & no-show, top treatment, omzet (laporan.keuangan), dan rincian per cabang bila melihat semua cabang.
     * Pasien & obat bersifat pusat (belum per cabang).
     */
    public function __invoke(Request $request, CabangAktif $cabang): JsonResponse
    {
        $today = today();
        $bolehOmzet = $request->user()->punyaIzin(Izin::LaporanKeuangan);

        $booking = Appointment::whereDate('mulai_at', $today)
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $topTreatment = KunjunganTindakan::query()
            ->whereHas('kunjungan', fn ($q) => $q->whereDate('tanggal', $today)->where('status', '!=', StatusKunjungan::Batal))
            ->select('tindakan_id')->selectRaw('SUM(jumlah) AS jumlah')
            ->groupBy('tindakan_id')->orderByDesc('jumlah')->limit(5)
            ->with('tindakan:id,nama')->get()
            ->map(fn ($t) => ['tindakan_id' => $t->tindakan_id, 'nama' => $t->tindakan?->nama, 'jumlah' => (int) $t->jumlah]);

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
            'booking' => [
                'total' => (int) $booking->except(StatusAppointment::Batal->value)->sum(),
                'tidak_hadir' => (int) ($booking[StatusAppointment::TidakHadir->value] ?? 0),
            ],
            'top_treatment' => $topTreatment,
            'per_cabang' => $cabang->id() ? null : $this->perCabang($today, $bolehOmzet),
            'pasien_total' => Pasien::count(),
            'pasien_baru_hari_ini' => Pasien::whereDate('created_at', $today)->count(),
            'resep_menunggu' => Resep::where('status', StatusResep::Menunggu)->count(),
            'tagihan_belum_bayar' => Tagihan::where('status', StatusTagihan::BelumBayar)->count(),
            // Hanya untuk pemegang izin laporan.keuangan; peran lain tidak perlu menjalankan query-nya.
            'pendapatan_hari_ini' => $bolehOmzet
                ? (int) Tagihan::where('status', StatusTagihan::Lunas)->whereDate('dibayar_at', $today)->sum('grand_total')
                : null,
            'obat_stok_menipis' => Obat::where('is_active', true)->stokMenipis()->orderBy('stok')->limit(10)->get(['id', 'nama', 'satuan', 'stok']),
        ]);
    }

    /** Kunjungan, no-show & omzet hari ini per cabang (hanya saat melihat semua cabang). */
    private function perCabang(Carbon $today, bool $bolehOmzet): array
    {
        $kunjungan = Kunjungan::whereDate('tanggal', $today)->where('status', '!=', StatusKunjungan::Batal)
            ->selectRaw('cabang_id, count(*) as n')->groupBy('cabang_id')->pluck('n', 'cabang_id');
        $noShow = Appointment::whereDate('mulai_at', $today)->where('status', StatusAppointment::TidakHadir)
            ->selectRaw('cabang_id, count(*) as n')->groupBy('cabang_id')->pluck('n', 'cabang_id');
        $omzet = $bolehOmzet
            ? Tagihan::where('status', StatusTagihan::Lunas)->whereDate('dibayar_at', $today)
                ->selectRaw('cabang_id, sum(grand_total) as n')->groupBy('cabang_id')->pluck('n', 'cabang_id')
            : collect();

        return Cabang::orderBy('nama')->get(['id', 'kode', 'nama'])->map(fn (Cabang $c) => [
            'cabang_id' => $c->id, 'kode' => $c->kode, 'nama' => $c->nama,
            'kunjungan' => (int) ($kunjungan[$c->id] ?? 0),
            'tidak_hadir' => (int) ($noShow[$c->id] ?? 0),
            'pendapatan' => $bolehOmzet ? (int) ($omzet[$c->id] ?? 0) : null,
        ])->all();
    }
}
