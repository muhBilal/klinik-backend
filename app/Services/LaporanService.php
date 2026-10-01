<?php

namespace App\Services;

use App\Enums\MetodeBayar;
use App\Enums\StatusKunjungan;
use App\Enums\StatusPaketPasien;
use App\Enums\StatusTagihan;
use App\Models\Cabang;
use App\Models\PaketPasien;
use App\Models\Pembayaran;
use App\Support\CabangAktif;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laporan penjualan (PRD LP-02) & paket (LP-03) untuk cabang aktif, atau semua cabang bila user lintas cabang tanpa pilihan cabang.
 *
 * Penjualan = tagihan yang **dibayar** dalam periode (`dibayar_at`), termasuk yang kemudian direfund; refund dicatat sebagai
 * pengurang di periode refund-nya (`dibatalkan_at`) sehingga laporan periode lalu tidak berubah. Penjualan bersih = total − diskon
 * manual − potongan promo (sebelum pajak). Per item/treatment memakai nilai bersih baris (`tagihan_items.neto`, dihitung saat lunas:
 * promo hanya untuk baris yang memenuhi syarat); tagihan lama tanpa neto dialokasikan proporsional terhadap subtotal.
 * Penerimaan tunai dikurangi kembalian. Pendapatan paket diakui per sesi yang dikerjakan (nilai per sesi), hanya untuk paket yang
 * dibayar: belum lunas, dibatalkan, dan yang direfund penuh lewat tagihan tidak diakui.
 */
class LaporanService
{
    /** Kunjungan yang layanannya sudah selesai dikerjakan (sesi paket dianggap terpakai). */
    private const KUNJUNGAN_TUNTAS = [StatusKunjungan::MenungguPembayaran, StatusKunjungan::Selesai];

    public function __construct(private CabangAktif $cabang) {}

    public function penjualan(Carbon $dari, Carbon $sampai): array
    {
        [$awal, $akhir] = [$dari->copy()->startOfDay(), $sampai->copy()->endOfDay()];

        $r = $this->tagihanTerjual($awal, $akhir)
            ->selectRaw('COUNT(*) AS transaksi, COALESCE(SUM(total), 0) AS bruto, COALESCE(SUM(diskon), 0) AS diskon,
                COALESCE(SUM(diskon_promo), 0) AS promo, COALESCE(SUM(pajak), 0) AS pajak, COALESCE(SUM(grand_total), 0) AS total')
            ->first();
        $refund = $this->tagihan()->whereNotNull('dibayar_at')->where('status', StatusTagihan::Batal->value)
            ->whereBetween('dibatalkan_at', [$awal, $akhir])
            ->selectRaw('COUNT(*) AS transaksi, COALESCE(SUM(grand_total), 0) AS total')->first();
        $refundPaket = (int) $this->refundSisaPaket($awal, $akhir)->sum('refund_nominal');
        $bersih = (int) $r->bruto - (int) $r->diskon - (int) $r->promo;

        return [
            'periode' => ['mulai' => $dari->toDateString(), 'selesai' => $sampai->toDateString()],
            'cabang' => $this->infoCabang(),
            'ringkasan' => [
                'transaksi' => (int) $r->transaksi,
                'bruto' => (int) $r->bruto,
                'diskon' => (int) $r->diskon,
                'promo' => (int) $r->promo,
                'penjualan_bersih' => $bersih,
                'pajak' => (int) $r->pajak,
                'total' => (int) $r->total,
                'refund' => ['transaksi' => (int) $refund->transaksi, 'total' => (int) $refund->total, 'paket_sisa' => $refundPaket],
                'total_setelah_refund' => (int) $r->total - (int) $refund->total - $refundPaket,
            ],
            'per_hari' => $this->tagihanTerjual($awal, $akhir)
                ->selectRaw('DATE(dibayar_at) AS tanggal, COUNT(*) AS transaksi, COALESCE(SUM(total - diskon - diskon_promo), 0) AS penjualan_bersih')
                ->groupByRaw('DATE(dibayar_at)')->orderBy('tanggal')->get()
                ->map(fn ($b) => ['tanggal' => (string) $b->tanggal, 'transaksi' => (int) $b->transaksi, 'penjualan_bersih' => (int) $b->penjualan_bersih]),
            'per_kategori' => $this->perKategori($awal, $akhir),
            'per_treatment' => $this->perTreatment($dari, $sampai, $awal, $akhir),
            'per_dokter' => $this->perDokter($awal, $akhir),
            'per_cabang' => $this->perCabang($awal, $akhir),
            'per_metode' => $this->perMetode($awal, $akhir),
        ];
    }

    public function paket(Carbon $dari, Carbon $sampai): array
    {
        [$awal, $akhir] = [$dari->copy()->startOfDay(), $sampai->copy()->endOfDay()];
        $cabangId = $this->cabang->id();

        // Terjual: paket yang aktif (lunas) dalam periode; paket hasil pengalihan bukan penjualan baru.
        $terjual = PaketPasien::query()->whereNull('dialihkan_dari_id')->whereBetween('aktif_at', [$awal, $akhir])
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->selectRaw('paket_id, COUNT(*) AS n, COALESCE(SUM(nilai), 0) AS nilai')->groupBy('paket_id')->get()->keyBy('paket_id');

        // Pemakaian (pendapatan diakui): sesi paket berbayar pada kunjungan yang tuntas dalam periode
        $dipakai = $this->paketDibayar(DB::table('kunjungan_tindakans as kt')
            ->join('kunjungans as k', 'k.id', '=', 'kt.kunjungan_id')
            ->join('paket_pasien_items as i', 'i.id', '=', 'kt.paket_pasien_item_id')
            ->join('paket_pasiens as p', 'p.id', '=', 'i.paket_pasien_id'))
            ->whereIn('k.status', array_map(fn ($s) => $s->value, self::KUNJUNGAN_TUNTAS))
            ->whereBetween('k.tanggal', [$awal, $akhir])
            ->when($cabangId, fn ($q) => $q->where('p.cabang_id', $cabangId))
            ->selectRaw('p.paket_id, SUM(kt.jumlah) AS sesi, SUM(kt.jumlah * i.nilai_per_sesi) AS nilai')
            ->groupBy('p.paket_id')->get()->keyBy('paket_id');

        $refund = PaketPasien::query()->where('status', StatusPaketPasien::Direfund)->whereBetween('direfund_at', [$awal, $akhir])
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->selectRaw('paket_id, COUNT(*) AS n, COALESCE(SUM(refund_nominal), 0) AS nilai')->groupBy('paket_id')->get()->keyBy('paket_id');

        // Paket aktif + sisa sesi (dari kunjungan tuntas) → kewajiban (masih berlaku) atau hangus (kedaluwarsa dalam periode)
        $aktif = $this->sisaPaketAktif($cabangId);
        $hariIni = today();
        $berlaku = $aktif->filter(fn ($p) => ! $p->berlaku_sampai || ! Carbon::parse($p->berlaku_sampai)->lt($hariIni));
        $hangus = $aktif->filter(fn ($p) => $p->berlaku_sampai && Carbon::parse($p->berlaku_sampai)->lt($hariIni)
            && Carbon::parse($p->berlaku_sampai)->between($dari->copy()->startOfDay(), $sampai->copy()->endOfDay()) && $p->sisa_sesi > 0);

        $nama = DB::table('pakets')->whereIn('id', collect([$terjual, $dipakai, $refund])->flatMap->keys()->merge($aktif->pluck('paket_id'))->unique())
            ->pluck('nama', 'id');
        $perPaket = $nama->map(fn ($n, $id) => [
            'paket_id' => (int) $id,
            'nama' => $n,
            'terjual' => (int) ($terjual[$id]->n ?? 0),
            'nilai_terjual' => (int) ($terjual[$id]->nilai ?? 0),
            'sesi_dipakai' => (int) ($dipakai[$id]->sesi ?? 0),
            'nilai_dipakai' => (int) ($dipakai[$id]->nilai ?? 0),
            'refund' => (int) ($refund[$id]->nilai ?? 0),
            'aktif' => $berlaku->where('paket_id', $id)->where('sisa_sesi', '>', 0)->count(),
            'sisa_sesi' => (int) $berlaku->where('paket_id', $id)->sum('sisa_sesi'),
            'sisa_kewajiban' => (int) $berlaku->where('paket_id', $id)->sum('sisa_nilai'),
        ])->sortByDesc('nilai_terjual')->values();

        return [
            'periode' => ['mulai' => $dari->toDateString(), 'selesai' => $sampai->toDateString()],
            'cabang' => $this->infoCabang(),
            'ringkasan' => [
                'terjual' => (int) $terjual->sum('n'),
                'nilai_terjual' => (int) $terjual->sum('nilai'),
                'sesi_dipakai' => (int) $dipakai->sum('sesi'),
                'nilai_dipakai' => (int) $dipakai->sum('nilai'),
                'refund' => (int) $refund->sum('nilai'),
                'hangus' => (int) $hangus->sum('sisa_nilai'),
                // Per hari ini (bukan per akhir periode): sisa sesi paket yang masih berlaku × nilai per sesi
                'paket_aktif' => $berlaku->where('sisa_sesi', '>', 0)->count(),
                'sisa_sesi' => (int) $berlaku->sum('sisa_sesi'),
                'sisa_kewajiban' => (int) $berlaku->sum('sisa_nilai'),
            ],
            'per_paket' => $perPaket,
            // Tindak lanjut CS: paket yang masih bersisa dan kedaluwarsa ≤ 30 hari lagi
            'segera_kedaluwarsa' => $berlaku
                ->filter(fn ($p) => $p->berlaku_sampai && $p->sisa_sesi > 0 && Carbon::parse($p->berlaku_sampai)->lte($hariIni->copy()->addDays(30)))
                ->sortBy('berlaku_sampai')->take(50)
                ->map(fn ($p) => (array) $p)
                ->values(),
        ];
    }

    /** Tagihan (query builder dasar) dalam cabang laporan, tanpa yang terhapus. */
    private function tagihan(string $alias = 'tagihans'): Builder
    {
        $cabangId = $this->cabang->id();

        return DB::table("tagihans as {$alias}")->whereNull("{$alias}.deleted_at")
            ->when($cabangId, fn ($q) => $q->where("{$alias}.cabang_id", $cabangId));
    }

    private function tagihanTerjual(Carbon $awal, Carbon $akhir, string $alias = 'tagihans'): Builder
    {
        return $this->tagihan($alias)->whereNotNull("{$alias}.dibayar_at")->whereBetween("{$alias}.dibayar_at", [$awal, $akhir]);
    }

    /** Nilai bersih satu baris tagihan: neto tersimpan, atau (tagihan lama) potongan dialokasikan proporsional (SQL, desimal). */
    private const NETO = 'COALESCE(ti.neto, ti.subtotal - CASE WHEN t.total > 0 THEN ti.subtotal * (t.diskon + t.diskon_promo) * 1.0 / t.total ELSE 0 END)';

    /** Sesi paket diakui sebagai pendapatan hanya dari paket berbayar (bukan menunggu bayar / dibatalkan / direfund penuh lewat tagihan). */
    private function paketDibayar(Builder $q, string $alias = 'p'): Builder
    {
        return $q->whereNotIn("{$alias}.status", [StatusPaketPasien::MenungguBayar->value, StatusPaketPasien::Dibatalkan->value])
            ->where(fn ($w) => $w->where("{$alias}.status", '!=', StatusPaketPasien::Direfund->value)->orWhereNotNull("{$alias}.refund_metode"));
    }

    private function perKategori(Carbon $awal, Carbon $akhir): Collection
    {
        return $this->tagihanTerjual($awal, $akhir, 't')
            ->join('tagihan_items as ti', 'ti.tagihan_id', '=', 't.id')
            ->selectRaw('ti.kategori, SUM(ti.jumlah) AS jumlah, SUM(ti.subtotal) AS bruto, SUM('.self::NETO.') AS neto')
            ->groupBy('ti.kategori')->get()
            ->map(fn ($b) => ['kategori' => $b->kategori, 'jumlah' => (int) $b->jumlah, 'bruto' => (int) $b->bruto, 'neto' => (int) round($b->neto)])
            ->sortByDesc('neto')->values();
    }

    /** Treatment & jasa konsultasi yang ditagihkan + sesi paket yang dikerjakan (nilai per sesi; ditagih Rp 0). */
    private function perTreatment(Carbon $dari, Carbon $sampai, Carbon $awal, Carbon $akhir): Collection
    {
        $tagih = $this->tagihanTerjual($awal, $akhir, 't')
            ->join('tagihan_items as ti', 'ti.tagihan_id', '=', 't.id')
            ->whereNotNull('ti.tindakan_id')->whereNull('ti.paket_id')
            // Baris Rp 0 = sesi paket (dihitung terpisah dari kunjungan_tindakans) → tidak masuk jumlah berbayar
            ->selectRaw('ti.tindakan_id, SUM(CASE WHEN ti.harga > 0 THEN ti.jumlah ELSE 0 END) AS jumlah, SUM(ti.subtotal) AS bruto, SUM('.self::NETO.') AS neto')
            ->groupBy('ti.tindakan_id')->get()->keyBy('tindakan_id');

        $cabangId = $this->cabang->id();
        $paket = $this->paketDibayar(DB::table('kunjungan_tindakans as kt')
            ->join('kunjungans as k', 'k.id', '=', 'kt.kunjungan_id')
            ->join('paket_pasien_items as i', 'i.id', '=', 'kt.paket_pasien_item_id')
            ->join('paket_pasiens as p', 'p.id', '=', 'i.paket_pasien_id'))
            ->whereIn('k.status', array_map(fn ($s) => $s->value, self::KUNJUNGAN_TUNTAS))
            ->whereBetween('k.tanggal', [$awal, $akhir])
            ->when($cabangId, fn ($q) => $q->where('k.cabang_id', $cabangId))
            ->selectRaw('kt.tindakan_id, SUM(kt.jumlah) AS sesi, SUM(kt.jumlah * i.nilai_per_sesi) AS nilai')
            ->groupBy('kt.tindakan_id')->get()->keyBy('tindakan_id');

        $tindakan = DB::table('tindakans as td')->leftJoin('kategori_tindakans as kat', 'kat.id', '=', 'td.kategori_id')
            ->whereIn('td.id', $tagih->keys()->merge($paket->keys())->unique())
            ->get(['td.id', 'td.kode', 'td.nama', 'kat.nama as kategori'])->keyBy('id');

        return $tindakan->map(fn ($t, $id) => [
            'tindakan_id' => (int) $id, 'kode' => $t->kode, 'nama' => $t->nama, 'kategori' => $t->kategori,
            'jumlah' => (int) ($tagih[$id]->jumlah ?? 0),
            'bruto' => (int) ($tagih[$id]->bruto ?? 0),
            'neto' => (int) round($tagih[$id]->neto ?? 0),
            'sesi_paket' => (int) ($paket[$id]->sesi ?? 0),
            'nilai_sesi_paket' => (int) ($paket[$id]->nilai ?? 0),
        ])->sortByDesc(fn ($t) => $t['neto'] + $t['nilai_sesi_paket'])->values();
    }

    private function perDokter(Carbon $awal, Carbon $akhir): Collection
    {
        return $this->tagihanTerjual($awal, $akhir, 't')
            ->leftJoin('kunjungans as k', 'k.id', '=', 't.kunjungan_id')
            ->leftJoin('users as u', 'u.id', '=', 'k.dokter_id')
            ->selectRaw('k.dokter_id, u.name, (t.kunjungan_id IS NULL) AS langsung, COUNT(*) AS transaksi,
                SUM(t.total - t.diskon - t.diskon_promo) AS penjualan_bersih')
            ->groupBy('k.dokter_id', 'u.name')->groupByRaw('(t.kunjungan_id IS NULL)')->get()
            ->map(fn ($b) => [
                'dokter_id' => $b->dokter_id ? (int) $b->dokter_id : null,
                // Tagihan tanpa kunjungan = penjualan langsung di kasir (produk, paket)
                'nama' => $b->name ?? ($b->langsung ? 'Penjualan langsung (tanpa kunjungan)' : 'Dokter belum tercatat'),
                'transaksi' => (int) $b->transaksi,
                'penjualan_bersih' => (int) $b->penjualan_bersih,
            ])->sortByDesc('penjualan_bersih')->values();
    }

    private function perCabang(Carbon $awal, Carbon $akhir): Collection
    {
        $nama = Cabang::withTrashed()->pluck('nama', 'id');

        return $this->tagihanTerjual($awal, $akhir, 't')
            ->selectRaw('t.cabang_id, COUNT(*) AS transaksi, SUM(t.total - t.diskon - t.diskon_promo) AS penjualan_bersih, SUM(t.grand_total) AS total')
            ->groupBy('t.cabang_id')->get()
            ->map(fn ($b) => ['cabang_id' => (int) $b->cabang_id, 'nama' => $nama[$b->cabang_id] ?? '-', 'transaksi' => (int) $b->transaksi,
                'penjualan_bersih' => (int) $b->penjualan_bersih, 'total' => (int) $b->total])
            ->sortByDesc('total')->values();
    }

    /**
     * Penerimaan per metode: pembayaran tagihan yang dibayar dalam periode (tunai dikurangi kembalian), pengembalian = pembayaran
     * yang direfund dalam periode + pengembalian sisa paket.
     */
    private function perMetode(Carbon $awal, Carbon $akhir): Collection
    {
        $cabangId = $this->cabang->id();
        $pembayaran = fn () => Pembayaran::query()->join('tagihans as t', 't.id', '=', 'pembayarans.tagihan_id')
            ->whereNull('t.deleted_at')->when($cabangId, fn ($q) => $q->where('t.cabang_id', $cabangId));
        $tunai = MetodeBayar::Tunai->value;

        $diterima = $pembayaran()->whereNotNull('t.dibayar_at')->whereBetween('t.dibayar_at', [$awal, $akhir])
            ->selectRaw('pembayarans.metode, SUM(pembayarans.jumlah) AS total')->groupBy('pembayarans.metode')->toBase()->get()->pluck('total', 'metode');
        $kembali = $pembayaran()->whereBetween('pembayarans.dikembalikan_at', [$awal, $akhir])
            ->selectRaw('pembayarans.metode, SUM(pembayarans.jumlah) AS total')->groupBy('pembayarans.metode')->toBase()->get()->pluck('total', 'metode');

        // Kembalian hanya terjadi pada tunai: tagihan dibayar dalam periode / tagihan yang tunainya dikembalikan dalam periode
        $kembalianDiterima = (int) $this->tagihanTerjual($awal, $akhir)->sum('kembalian');
        $kembalianRefund = (int) $this->tagihan()->whereIn('id', $pembayaran()->where('pembayarans.metode', $tunai)
            ->whereBetween('pembayarans.dikembalikan_at', [$awal, $akhir])->toBase()->select('pembayarans.tagihan_id'))->sum('kembalian');
        $paket = $this->refundSisaPaket($awal, $akhir)->selectRaw('refund_metode, SUM(refund_nominal) AS total')->groupBy('refund_metode')
            ->toBase()->get()->pluck('total', 'refund_metode');

        return collect(MetodeBayar::cases())->map(function (MetodeBayar $m) use ($diterima, $kembali, $paket, $kembalianDiterima, $kembalianRefund, $tunai) {
            $masuk = (int) ($diterima[$m->value] ?? 0) - ($m->value === $tunai ? $kembalianDiterima : 0);
            $keluar = (int) ($kembali[$m->value] ?? 0) - ($m->value === $tunai ? $kembalianRefund : 0) + (int) ($paket[$m->value] ?? 0);

            return ['metode' => $m->value, 'diterima' => $masuk, 'dikembalikan' => $keluar, 'bersih' => $masuk - $keluar];
        })->filter(fn ($b) => $b['diterima'] || $b['dikembalikan'])->values();
    }

    /** Pengembalian sisa paket (uang keluar di kasir, bukan refund tagihan). */
    private function refundSisaPaket(Carbon $awal, Carbon $akhir)
    {
        $cabangId = $this->cabang->id();

        return PaketPasien::query()->whereNotNull('refund_metode')->whereBetween('direfund_at', [$awal, $akhir])
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId));
    }

    /**
     * Paket berstatus aktif + sisa sesi (jumlah sesi − sesi pada kunjungan tuntas) & nilai sisa.
     *
     * @return Collection<int, object>
     */
    private function sisaPaketAktif(?int $cabangId): Collection
    {
        $terpakai = DB::table('kunjungan_tindakans as kt')->join('kunjungans as k', 'k.id', '=', 'kt.kunjungan_id')
            ->whereNotNull('kt.paket_pasien_item_id')
            ->whereIn('k.status', array_map(fn ($s) => $s->value, self::KUNJUNGAN_TUNTAS))
            ->groupBy('kt.paket_pasien_item_id')->selectRaw('kt.paket_pasien_item_id AS item_id, SUM(kt.jumlah) AS n');

        return DB::table('paket_pasien_items as i')
            ->join('paket_pasiens as p', 'p.id', '=', 'i.paket_pasien_id')
            ->join('pasiens as ps', 'ps.id', '=', 'p.pasien_id')
            ->leftJoinSub($terpakai, 'u', 'u.item_id', '=', 'i.id')
            ->where('p.status', StatusPaketPasien::Aktif->value)
            ->when($cabangId, fn ($q) => $q->where('p.cabang_id', $cabangId))
            ->get(['p.id', 'p.no_paket', 'p.nama', 'p.paket_id', 'p.pasien_id', 'ps.nama as pasien_nama', 'ps.no_rm', 'p.berlaku_sampai',
                'i.jumlah_sesi', 'i.nilai_per_sesi', DB::raw('COALESCE(u.n, 0) AS terpakai')])
            ->groupBy('id')
            ->map(function (Collection $items) {
                $p = $items->first();
                $sisa = fn ($i) => max(0, (int) $i->jumlah_sesi - (int) $i->terpakai);

                return (object) [
                    'id' => (int) $p->id, 'no_paket' => $p->no_paket, 'nama' => $p->nama, 'paket_id' => (int) $p->paket_id,
                    'pasien_id' => (int) $p->pasien_id, 'pasien_nama' => $p->pasien_nama, 'no_rm' => $p->no_rm,
                    'berlaku_sampai' => $p->berlaku_sampai ? substr((string) $p->berlaku_sampai, 0, 10) : null,
                    'sisa_sesi' => (int) $items->sum($sisa),
                    'sisa_nilai' => (int) $items->sum(fn ($i) => $sisa($i) * (int) $i->nilai_per_sesi),
                ];
            })->values();
    }

    private function infoCabang(): ?array
    {
        $id = $this->cabang->id();

        return $id ? Cabang::withTrashed()->whereKey($id)->first(['id', 'kode', 'nama'])?->toArray() : null;
    }
}
