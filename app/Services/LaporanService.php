<?php

namespace App\Services;

use App\Enums\StatusKunjungan;
use App\Models\Cabang;
use App\Models\KunjunganTindakan;
use App\Models\PaketPasien;
use App\Models\Pembayaran;
use App\Models\Tagihan;
use App\Models\Tindakan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Laporan keuangan (PRD LP-02 penjualan, LP-03 paket / pendapatan diterima di muka, AD-01 konsolidasi cabang).
 *
 * Aturan penjualan: tagihan dihitung pada periode **pembayarannya** (`dibayar_at`) dan refund mengurangi periode **refund terjadi**
 * (`dibatalkan_at` pada tagihan yang pernah dibayar). Nilai per baris = subtotal × porsi setelah diskon & promo (sebelum pajak),
 * sehingga jumlah per treatment/kategori cocok dengan total neto sebelum pajak.
 * `$cabangId` null = semua cabang (pengguna lintas cabang tanpa pilihan cabang).
 */
class LaporanService
{
    /** Tagihan yang dibayar di rentang (+) dan tagihan yang direfund di rentang (−). */
    private function tagihans(?int $cabangId, CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $dasar = fn () => Tagihan::withoutGlobalScope('cabang')
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->select(['id', 'cabang_id', 'kunjungan_id', 'total', 'diskon', 'diskon_promo', 'pajak', 'grand_total', 'dibayar_at', 'dibatalkan_at']);

        return [
            $dasar()->whereBetween('dibayar_at', [$dari, $sampai])->get(),
            $dasar()->whereNotNull('dibayar_at')->whereBetween('dibatalkan_at', [$dari, $sampai])->get(),
        ];
    }

    /** Porsi nilai setelah diskon & promo (sebelum pajak). */
    private function faktor(Tagihan $t): float
    {
        return $t->total > 0 ? max(0, $t->total - $t->diskon - $t->diskon_promo) / $t->total : 0.0;
    }

    /**
     * @return array{ringkasan: array, baris: list<array>}
     */
    public function penjualan(?int $cabangId, CarbonImmutable $dari, CarbonImmutable $sampai, string $kelompok): array
    {
        [$bayar, $refund] = $this->tagihans($cabangId, $dari, $sampai);

        $ringkasan = [
            'transaksi' => $bayar->count(),
            'bruto' => (int) $bayar->sum('total'),
            'diskon' => (int) $bayar->sum('diskon'),
            'promo' => (int) $bayar->sum('diskon_promo'),
            'pajak' => (int) $bayar->sum('pajak'),
            'neto' => (int) $bayar->sum('grand_total'),
            'refund_transaksi' => $refund->count(),
            'refund' => (int) $refund->sum('grand_total'),
        ];
        $ringkasan['bersih'] = $ringkasan['neto'] - $ringkasan['refund'];

        $baris = match ($kelompok) {
            'cabang' => $this->perCabang($bayar, $refund),
            'metode' => $this->perMetode($cabangId, $dari, $sampai),
            'dokter' => $this->perDokter($bayar, $refund),
            'kategori' => $this->perItem($bayar, $refund, 'kategori'),
            default => $this->perItem($bayar, $refund, 'treatment'),
        };

        return ['ringkasan' => $ringkasan, 'baris' => $baris];
    }

    /** Konsolidasi cabang (AD-01). */
    private function perCabang(Collection $bayar, Collection $refund): array
    {
        $nama = Cabang::withTrashed()->pluck('nama', 'id');
        $ids = $bayar->pluck('cabang_id')->merge($refund->pluck('cabang_id'))->unique();

        return $ids->map(function ($id) use ($bayar, $refund, $nama) {
            $b = $bayar->where('cabang_id', $id);
            $r = $refund->where('cabang_id', $id);

            return $this->barisUang((string) $id, $nama[$id] ?? "#{$id}", $b->count(), (int) $b->sum('total'),
                (int) $b->sum('diskon') + (int) $b->sum('diskon_promo'), (int) $b->sum('grand_total'), (int) $r->sum('grand_total'));
        })->sortByDesc('bersih')->values()->all();
    }

    /** Uang masuk per metode bayar; refund = baris pembayaran yang dikembalikan di rentang. */
    private function perMetode(?int $cabangId, CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $dasar = fn () => Pembayaran::query()
            ->join('tagihans', 'tagihans.id', '=', 'pembayarans.tagihan_id')
            ->when($cabangId, fn ($q) => $q->where('tagihans.cabang_id', $cabangId));

        $masuk = $dasar()->whereBetween('pembayarans.dibayar_at', [$dari, $sampai])
            ->selectRaw('pembayarans.metode AS metode, COUNT(DISTINCT pembayarans.tagihan_id) AS transaksi, SUM(pembayarans.jumlah) AS total')
            ->groupBy('pembayarans.metode')->get()->keyBy(fn ($r) => $r->metode->value);
        $keluar = $dasar()->whereBetween('pembayarans.dikembalikan_at', [$dari, $sampai])
            ->selectRaw('pembayarans.metode AS metode, SUM(pembayarans.jumlah) AS total')
            ->groupBy('pembayarans.metode')->get()->keyBy(fn ($r) => $r->metode->value);

        return $masuk->keys()->merge($keluar->keys())->unique()->map(fn ($m) => [
            'kunci' => $m,
            'label' => $m,
            'transaksi' => (int) ($masuk[$m]->transaksi ?? 0),
            'masuk' => (int) ($masuk[$m]->total ?? 0),
            'refund' => (int) ($keluar[$m]->total ?? 0),
            'bersih' => (int) ($masuk[$m]->total ?? 0) - (int) ($keluar[$m]->total ?? 0),
        ])->sortByDesc('bersih')->values()->all();
    }

    /** Per dokter penanggung jawab kunjungan; penjualan tanpa kunjungan = "Penjualan langsung". */
    private function perDokter(Collection $bayar, Collection $refund): array
    {
        $dokter = DB::table('kunjungans')->whereIn('id', $bayar->pluck('kunjungan_id')->merge($refund->pluck('kunjungan_id'))->filter()->unique())
            ->pluck('dokter_id', 'id');
        $nama = User::withTrashed()->whereKey($dokter->filter()->unique())->pluck('name', 'id');
        $kunci = fn (Tagihan $t) => $t->kunjungan_id ? (string) ($dokter[$t->kunjungan_id] ?? 'tanpa') : 'langsung';

        $grupB = $bayar->groupBy($kunci);
        $grupR = $refund->groupBy($kunci);

        return $grupB->keys()->merge($grupR->keys())->unique()->map(function ($k) use ($grupB, $grupR, $nama) {
            $b = $grupB[$k] ?? collect();
            $r = $grupR[$k] ?? collect();
            $label = match ($k) {
                'langsung' => 'Penjualan langsung (tanpa kunjungan)',
                'tanpa' => 'Kunjungan tanpa dokter',
                default => $nama[(int) $k] ?? "#{$k}",
            };

            return $this->barisUang($k, $label, $b->count(), (int) $b->sum('total'),
                (int) $b->sum('diskon') + (int) $b->sum('diskon_promo'), (int) $b->sum('grand_total'), (int) $r->sum('grand_total'));
        })->sortByDesc('bersih')->values()->all();
    }

    /**
     * Per treatment (baris tindakan) atau per kategori baris (konsultasi/tindakan/obat/produk/paket).
     * Nilai = sebelum pajak, setelah porsi diskon & promo. Sesi paket (Rp 0) dihitung terpisah sebagai `sesi_paket`.
     */
    private function perItem(Collection $bayar, Collection $refund, string $mode): array
    {
        $faktor = $bayar->merge($refund)->mapWithKeys(fn (Tagihan $t) => [$t->id => $this->faktor($t)]);
        $items = DB::table('tagihan_items')
            ->whereIn('tagihan_id', $bayar->pluck('id')->merge($refund->pluck('id'))->unique())
            ->when($mode === 'treatment', fn ($q) => $q->where('kategori', 'tindakan'))
            ->get(['tagihan_id', 'kategori', 'tindakan_id', 'jumlah', 'subtotal']);

        $idBayar = $bayar->pluck('id')->flip();
        $idRefund = $refund->pluck('id')->flip();
        $hasil = [];

        foreach ($items as $i) {
            $k = $mode === 'treatment' ? (string) $i->tindakan_id : $i->kategori;
            $hasil[$k] ??= ['kunci' => $k, 'jumlah' => 0, 'sesi_paket' => 0, 'bruto' => 0, 'neto' => 0, 'refund' => 0];
            $nilai = (int) round($i->subtotal * ($faktor[$i->tagihan_id] ?? 0));

            if (isset($idBayar[$i->tagihan_id])) {
                $i->subtotal > 0 ? $hasil[$k]['jumlah'] += $i->jumlah : $hasil[$k]['sesi_paket'] += $i->jumlah;
                $hasil[$k]['bruto'] += (int) $i->subtotal;
                $hasil[$k]['neto'] += $nilai;
            }
            if (isset($idRefund[$i->tagihan_id])) {
                $hasil[$k]['refund'] += $nilai;
            }
        }

        $label = $mode === 'treatment'
            ? Tindakan::withTrashed()->with('kategori:id,nama')->whereKey(array_filter(array_keys($hasil)))->get(['id', 'kode', 'nama', 'kategori_id'])->keyBy('id')
            : collect();
        $KATEGORI = ['konsultasi' => 'Konsultasi', 'tindakan' => 'Tindakan / treatment', 'obat' => 'Obat (resep)', 'produk' => 'Produk',
            'paket' => 'Paket treatment', 'deposit' => 'Deposit', 'lainnya' => 'Lainnya'];

        return collect($hasil)->map(function ($r) use ($mode, $label, $KATEGORI) {
            if ($mode === 'treatment') {
                $t = $label[(int) $r['kunci']] ?? null;
                $r['label'] = $t ? $t->nama : 'Treatment terhapus';
                $r['kode'] = $t?->kode;
                $r['grup'] = $t?->kategori?->nama;
            } else {
                $r['label'] = $KATEGORI[$r['kunci']] ?? $r['kunci'];
            }
            $r['bersih'] = $r['neto'] - $r['refund'];

            return $r;
        })->sortByDesc('bersih')->values()->all();
    }

    private function barisUang(string $kunci, string $label, int $transaksi, int $bruto, int $potongan, int $neto, int $refund): array
    {
        return compact('kunci', 'label', 'transaksi', 'bruto', 'potongan', 'neto', 'refund') + ['bersih' => $neto - $refund];
    }

    /**
     * Laporan paket (LP-03): terjual, terpakai, refund di rentang; kewajiban sisa sesi (pendapatan diterima di muka) per tanggal `$sampai`;
     * paket kedaluwarsa yang masih bersisa (potensi diakui sebagai pendapatan).
     */
    public function paket(?int $cabangId, CarbonImmutable $dari, CarbonImmutable $sampai): array
    {
        $tanggal = $sampai->toDateString();

        $paket = PaketPasien::query()
            ->with(['items:id,paket_pasien_id,tindakan_id,jumlah_sesi,nilai_per_sesi', 'pasien:id,no_rm,nama'])
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->whereNotNull('aktif_at')
            ->where('aktif_at', '<=', $sampai)
            ->get();

        // Sesi terpakai per item sampai tanggal laporan (kunjungan tidak batal), dan di dalam rentang
        $pakai = KunjunganTindakan::query()
            ->join('kunjungans', 'kunjungans.id', '=', 'kunjungan_tindakans.kunjungan_id')
            ->whereIn('kunjungan_tindakans.paket_pasien_item_id', $paket->flatMap->items->pluck('id'))
            ->where('kunjungans.status', '!=', StatusKunjungan::Batal->value)
            ->whereDate('kunjungans.tanggal', '<=', $tanggal)
            ->get(['kunjungan_tindakans.paket_pasien_item_id', 'kunjungan_tindakans.jumlah', 'kunjungans.tanggal']);
        $pakaiTotal = $pakai->groupBy('paket_pasien_item_id')->map->sum('jumlah');
        $pakaiRentang = $pakai->filter(fn ($p) => CarbonImmutable::parse($p->tanggal)->betweenIncluded($dari->startOfDay(), $sampai))
            ->groupBy('paket_pasien_item_id')->map->sum('jumlah');

        $ringkasan = [
            'terjual' => ['paket' => 0, 'nilai' => 0],
            'terpakai' => ['sesi' => 0, 'nilai' => 0],
            'direfund' => ['paket' => 0, 'nilai' => 0],
            'kewajiban' => ['paket' => 0, 'sesi' => 0, 'nilai' => 0],
            'kedaluwarsa_bersisa' => ['paket' => 0, 'sesi' => 0, 'nilai' => 0],
        ];
        $perPaket = [];
        $daftar = [];

        foreach ($paket as $p) {
            $k = $p->paket_id;
            $perPaket[$k] ??= ['kunci' => $k, 'label' => $p->nama, 'terjual' => 0, 'nilai_terjual' => 0, 'sesi_terpakai' => 0,
                'nilai_terpakai' => 0, 'sesi_sisa' => 0, 'kewajiban' => 0];

            if ($p->aktif_at->betweenIncluded($dari->startOfDay(), $sampai) && ! $p->dialihkan_dari_id) {
                $ringkasan['terjual']['paket']++;
                $ringkasan['terjual']['nilai'] += (int) $p->nilai;
                $perPaket[$k]['terjual']++;
                $perPaket[$k]['nilai_terjual'] += (int) $p->nilai;
            }
            if ($p->direfund_at && $p->direfund_at->betweenIncluded($dari->startOfDay(), $sampai)) {
                $ringkasan['direfund']['paket']++;
                $ringkasan['direfund']['nilai'] += (int) $p->refund_nominal;
            }

            $sisaSesi = 0;
            $sisaNilai = 0;
            foreach ($p->items as $item) {
                $nilaiTerpakai = (int) ($pakaiRentang[$item->id] ?? 0) * $item->nilai_per_sesi;
                $ringkasan['terpakai']['sesi'] += (int) ($pakaiRentang[$item->id] ?? 0);
                $ringkasan['terpakai']['nilai'] += $nilaiTerpakai;
                $perPaket[$k]['sesi_terpakai'] += (int) ($pakaiRentang[$item->id] ?? 0);
                $perPaket[$k]['nilai_terpakai'] += $nilaiTerpakai;

                $sisa = max(0, $item->jumlah_sesi - (int) ($pakaiTotal[$item->id] ?? 0));
                $sisaSesi += $sisa;
                $sisaNilai += $sisa * $item->nilai_per_sesi;
            }

            // Kewajiban berakhir saat paket direfund atau dialihkan (paket pengganti menanggung sisanya)
            $berakhir = collect([$p->direfund_at, $p->dialihkan_at])->filter()->min();
            if (($berakhir && $berakhir->lte($sampai)) || $sisaSesi === 0) {
                continue;
            }

            if ($p->berlaku_sampai && $p->berlaku_sampai->lt($sampai->startOfDay())) {
                $ringkasan['kedaluwarsa_bersisa']['paket']++;
                $ringkasan['kedaluwarsa_bersisa']['sesi'] += $sisaSesi;
                $ringkasan['kedaluwarsa_bersisa']['nilai'] += $sisaNilai;

                continue;
            }

            $ringkasan['kewajiban']['paket']++;
            $ringkasan['kewajiban']['sesi'] += $sisaSesi;
            $ringkasan['kewajiban']['nilai'] += $sisaNilai;
            $perPaket[$k]['sesi_sisa'] += $sisaSesi;
            $perPaket[$k]['kewajiban'] += $sisaNilai;
            $daftar[] = [
                'no_paket' => $p->no_paket, 'nama' => $p->nama, 'pasien' => $p->pasien?->nama, 'no_rm' => $p->pasien?->no_rm,
                'berlaku_sampai' => $p->berlaku_sampai?->toDateString(), 'sesi_sisa' => $sisaSesi, 'nilai_sisa' => $sisaNilai,
            ];
        }

        return [
            'per_tanggal' => $tanggal,
            'ringkasan' => $ringkasan,
            'per_paket' => collect($perPaket)->sortByDesc('kewajiban')->values()->all(),
            'kewajiban' => collect($daftar)->sortByDesc('nilai_sisa')->values()->all(),
        ];
    }
}
