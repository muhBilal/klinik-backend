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
use App\Models\User;
use App\Services\PengaturanService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            // AD-05: SIP/STR yang akan/sudah kedaluwarsa — pengelola pengguna melihat semua, petugas melihat miliknya
            'izin_praktik' => $this->izinPraktik($request->user()),
            // LP-01: booking & no-show, top treatment, perbandingan cabang
            'booking' => $request->user()->punyaIzin(Izin::BookingLihat) ? $this->booking() : null,
            'top_treatment' => $this->topTreatment($cabang->id(), $today),
            'per_cabang' => $cabang->id() === null && $request->user()->aksesSemuaCabang()
                ? $this->perCabang($today, $request->user()->punyaIzin(Izin::LaporanKeuangan))
                : null,
        ]);
    }

    /**
     * SIP/STR berakhir dalam `regulasi.peringatan_izin_hari` hari (atau sudah lewat), dan dokter tanpa SIP.
     *
     * @return list<array{user_id: int, nama: string, dokumen: string, nomor: ?string, berlaku_sampai: ?string, sisa_hari: ?int}>
     */
    private function izinPraktik(User $user): array
    {
        $hari = (int) app(PengaturanService::class)->get('regulasi.peringatan_izin_hari');
        $batas = today()->addDays($hari);

        $users = $user->punyaIzin(Izin::PenggunaKelola)
            ? User::petugasMedis()->get(['id', 'name', 'role', 'sip', 'sip_berlaku_sampai', 'str', 'str_berlaku_sampai'])
            : collect([$user]);

        $hasil = [];
        foreach ($users as $u) {
            foreach (['sip' => 'SIP', 'str' => 'STR'] as $kolom => $label) {
                $sampai = $u->{"{$kolom}_berlaku_sampai"};
                $tanpaSip = $kolom === 'sip' && blank($u->sip) && $u->tercatatSebagaiDokter();

                if ($tanpaSip || ($sampai && $sampai->lte($batas))) {
                    $hasil[] = [
                        'user_id' => $u->id, 'nama' => $u->name, 'dokumen' => $label, 'nomor' => $u->{$kolom},
                        'berlaku_sampai' => $sampai?->toDateString(),
                        'sisa_hari' => $sampai ? (int) today()->diffInDays($sampai, false) : null,
                    ];
                }
            }
        }

        return collect($hasil)->sortBy(fn ($r) => $r['sisa_hari'] ?? -99999)->values()->all();
    }

    /** Booking hari ini per status + rasio no-show 30 hari terakhir (KPI PRD bagian 2). */
    private function booking(): array
    {
        $hariIni = Appointment::whereBetween('mulai_at', [today()->startOfDay(), today()->endOfDay()])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        // Pembagi = booking yang jadwalnya sudah lewat dan sudah ditandai hadir / tidak hadir
        $sebulan = Appointment::whereBetween('mulai_at', [today()->subDays(30)->startOfDay(), now()])
            ->whereIn('status', [StatusAppointment::Hadir->value, StatusAppointment::TidakHadir->value])
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $hadir = (int) ($sebulan[StatusAppointment::Hadir->value] ?? 0);
        $tidak = (int) ($sebulan[StatusAppointment::TidakHadir->value] ?? 0);

        return [
            'hari_ini' => collect(StatusAppointment::cases())->mapWithKeys(fn ($s) => [$s->value => (int) ($hariIni[$s->value] ?? 0)]),
            'no_show_30_hari' => ['hadir' => $hadir, 'tidak_hadir' => $tidak, 'persen' => $hadir + $tidak ? round($tidak * 100 / ($hadir + $tidak), 1) : null],
        ];
    }

    /** Lima treatment terbanyak hari ini (kunjungan tidak batal). */
    private function topTreatment(?int $cabangId, $today): array
    {
        return KunjunganTindakan::query()
            ->join('kunjungans', 'kunjungans.id', '=', 'kunjungan_tindakans.kunjungan_id')
            ->join('tindakans', 'tindakans.id', '=', 'kunjungan_tindakans.tindakan_id')
            ->whereDate('kunjungans.tanggal', $today)
            ->where('kunjungans.status', '!=', StatusKunjungan::Batal->value)
            ->when($cabangId, fn ($q) => $q->where('kunjungans.cabang_id', $cabangId))
            ->groupBy('kunjungan_tindakans.tindakan_id', 'tindakans.nama')
            ->orderByDesc('jumlah')
            ->limit(5)
            ->get([DB::raw('kunjungan_tindakans.tindakan_id AS tindakan_id'), DB::raw('tindakans.nama AS nama'),
                DB::raw('SUM(kunjungan_tindakans.jumlah) AS jumlah')])
            ->map(fn ($r) => ['tindakan_id' => (int) $r->tindakan_id, 'nama' => $r->nama, 'jumlah' => (int) $r->jumlah])
            ->all();
    }

    /** Perbandingan antar cabang hari ini untuk pengguna lintas cabang (AD-01 konsolidasi). */
    private function perCabang($today, bool $keuangan): array
    {
        $kunjungan = Kunjungan::withoutGlobalScope('cabang')->whereDate('tanggal', $today)->where('status', '!=', StatusKunjungan::Batal)
            ->selectRaw('cabang_id, count(*) as total')->groupBy('cabang_id')->pluck('total', 'cabang_id');
        $pendapatan = $keuangan
            ? Tagihan::withoutGlobalScope('cabang')->where('status', StatusTagihan::Lunas)->whereDate('dibayar_at', $today)
                ->selectRaw('cabang_id, sum(grand_total) as total')->groupBy('cabang_id')->pluck('total', 'cabang_id')
            : collect();

        return Cabang::where('is_active', true)->orderBy('nama')->get(['id', 'kode', 'nama'])->map(fn (Cabang $c) => [
            'id' => $c->id, 'kode' => $c->kode, 'nama' => $c->nama,
            'kunjungan' => (int) ($kunjungan[$c->id] ?? 0),
            'pendapatan' => $keuangan ? (int) ($pendapatan[$c->id] ?? 0) : null,
        ])->all();
    }
}
