<?php

namespace App\Services;

use App\Enums\Izin;
use App\Enums\JenisPotongan;
use App\Enums\PeranKomisi;
use App\Enums\SumberKomisi;
use App\Models\AturanKomisi;
use App\Models\Komisi;
use App\Models\KunjunganTindakan;
use App\Models\PeriodeKomisi;
use App\Models\Tagihan;
use App\Models\TagihanItem;
use App\Models\Tindakan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Komisi & jasa medis (PRD KM-01, KM-03, AN-03).
 *
 * Komisi dicatat sebagai kejadian: saat tagihan lunas (`bayar`, +) dan saat direfund (`refund`, −). Dasar = nilai baris tindakan
 * di tagihan (setelah bagian diskon & promo bila `komisi.dasar = setelah_diskon`); sesi paket memakai nilai per sesi paket karena
 * barisnya Rp 0. Aturan dipilih yang paling spesifik (treatment > kategori > semua; khusus cabang lebih diutamakan) per peran.
 * Petugas dengan peran sama dalam satu tindakan berbagi rata nilai aturan peran itu.
 *
 * Periode = bulan kejadian (`YYYY-MM`). Periode yang sudah disetujui terkunci: kejadian baru masuk periode terbuka berikutnya
 * sebagai penyesuaian, sehingga rekap yang sudah dibayar tidak pernah berubah.
 */
class KomisiService
{
    public function __construct(private PengaturanService $pengaturan) {}

    /** Catat komisi dari tagihan yang baru lunas. Idempoten: tagihan yang sudah punya baris `bayar` dilewati. */
    public function catatDariTagihan(Tagihan $tagihan): void
    {
        if (Komisi::withoutGlobalScope('cabang')->where('tagihan_id', $tagihan->id)->where('sumber', SumberKomisi::Bayar)->exists()) {
            return;
        }

        $periode = $this->periodeTerbuka($tagihan->cabang_id, $tagihan->dibayar_at ?? now());
        $this->buatBaris($tagihan, $periode);
    }

    /** Refund tagihan: setiap baris `bayar` dibalik (−) di periode terbuka saat ini. */
    public function batalkanDariTagihan(Tagihan $tagihan): void
    {
        $bayar = Komisi::withoutGlobalScope('cabang')->where('tagihan_id', $tagihan->id)->where('sumber', SumberKomisi::Bayar)->get();

        if ($bayar->isEmpty() || Komisi::withoutGlobalScope('cabang')->where('tagihan_id', $tagihan->id)->where('sumber', SumberKomisi::Refund)->exists()) {
            return;
        }

        $periode = $this->periodeTerbuka($tagihan->cabang_id, now());

        foreach ($bayar as $baris) {
            Komisi::create([
                ...$baris->only(['cabang_id', 'user_id', 'peran', 'tagihan_id', 'tagihan_item_id', 'kunjungan_tindakan_id',
                    'tindakan_id', 'aturan_id', 'jenis', 'nilai_aturan', 'dibagi']),
                'periode' => $periode,
                'sumber' => SumberKomisi::Refund,
                'dasar' => -$baris->dasar,
                'jumlah' => -$baris->jumlah,
                'keterangan' => "Refund {$tagihan->no_tagihan}",
            ]);
        }
    }

    /**
     * Hitung ulang periode yang belum disetujui dengan aturan terkini (mis. setelah aturan komisi diubah).
     * Baris `bayar` dibuat ulang; baris `refund` dibuat ulang sebagai kebalikan baris bayar terbarunya.
     */
    public function hitungUlang(int $cabangId, string $periode): int
    {
        $this->pastikanTerbuka($cabangId, $periode);

        return DB::transaction(function () use ($cabangId, $periode) {
            $dasar = fn () => Komisi::withoutGlobalScope('cabang')->where('cabang_id', $cabangId)->where('periode', $periode);

            $tagihanBayar = $dasar()->where('sumber', SumberKomisi::Bayar)->distinct()->pluck('tagihan_id');
            $tagihanRefund = $dasar()->where('sumber', SumberKomisi::Refund)->distinct()->pluck('tagihan_id');

            // Tagihan lunas di periode ini yang belum tercatat (mis. sebelum aturan dibuat) ikut dihitung.
            [$awal, $akhir] = $this->rentang($periode);
            $tagihanLunas = Tagihan::withoutGlobalScope('cabang')
                ->where('cabang_id', $cabangId)
                ->whereBetween('dibayar_at', [$awal, $akhir])
                ->whereNotNull('kunjungan_id')
                ->pluck('id');

            $dasar()->delete();

            $semua = $tagihanBayar->merge($tagihanLunas)->unique();
            foreach (Tagihan::withoutGlobalScope('cabang')->whereKey($semua)->get() as $tagihan) {
                // Tagihan yang bayarnya tercatat di periode lain (terkunci) tidak dihitung dua kali.
                if (Komisi::withoutGlobalScope('cabang')->where('tagihan_id', $tagihan->id)->where('sumber', SumberKomisi::Bayar)->exists()) {
                    continue;
                }
                $this->buatBaris($tagihan, $periode);
            }

            foreach (Tagihan::withoutGlobalScope('cabang')->whereKey($tagihanRefund)->get() as $tagihan) {
                $this->batalkanDiPeriode($tagihan, $periode);
            }

            return $dasar()->count();
        });
    }

    /** Setujui & kunci periode komisi satu cabang (KM-03). */
    public function setujui(int $cabangId, string $periode, User $user, ?string $catatan = null): PeriodeKomisi
    {
        if ($periode > now()->format('Y-m')) {
            throw ValidationException::withMessages(['periode' => 'Periode yang belum berjalan tidak bisa disetujui.']);
        }

        $this->pastikanTerbuka($cabangId, $periode);

        $baris = PeriodeKomisi::withoutGlobalScope('cabang')->firstOrNew(['cabang_id' => $cabangId, 'periode' => $periode]);
        $baris->fill([
            'status' => PeriodeKomisi::DISETUJUI,
            'disetujui_oleh' => $user->id,
            'disetujui_at' => now(),
            'catatan' => $catatan,
        ])->save();

        return $baris;
    }

    /**
     * Rekap per petugas: total, jumlah tindakan, rincian per peran.
     *
     * @return array{periode: string, status: string, disetujui_oleh: ?string, disetujui_at: ?string, total: int, petugas: list<array>}
     */
    public function rekap(?int $cabangId, string $periode, ?int $userId = null): array
    {
        $baris = Komisi::withoutGlobalScope('cabang')
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->where('periode', $periode)
            ->selectRaw('user_id, peran, COUNT(*) AS baris, SUM(jumlah) AS total, SUM(CASE WHEN sumber = ? THEN 1 ELSE 0 END) AS tindakan', [SumberKomisi::Bayar->value])
            ->groupBy('user_id', 'peran')
            ->get();

        $users = User::withTrashed()->whereKey($baris->pluck('user_id')->unique())->get(['id', 'name', 'role'])->keyBy('id');

        $petugas = $baris->groupBy('user_id')->map(fn (Collection $g, $id) => [
            'user_id' => (int) $id,
            'nama' => $users[$id]->name ?? "#{$id}",
            'total' => (int) $g->sum('total'),
            'jumlah_tindakan' => (int) $g->sum('tindakan'),
            'per_peran' => $g->map(fn ($r) => [
                'peran' => $r->peran->value, 'label' => $r->peran->label(), 'total' => (int) $r->total,
            ])->values(),
        ])->sortByDesc('total')->values();

        $status = $cabangId
            ? PeriodeKomisi::withoutGlobalScope('cabang')->with('penyetuju:id,name')->where('cabang_id', $cabangId)->where('periode', $periode)->first()
            : null;

        return [
            'periode' => $periode,
            'cabang_id' => $cabangId,
            'status' => $status?->status ?? 'draf',
            'disetujui_oleh' => $status?->penyetuju?->name,
            'disetujui_at' => $status?->disetujui_at?->toIso8601String(),
            'catatan' => $status?->catatan,
            'total' => (int) $petugas->sum('total'),
            'petugas' => $petugas->all(),
        ];
    }

    /** Rincian baris komisi satu petugas di satu periode (slip). */
    public function rincian(?int $cabangId, string $periode, int $userId): Collection
    {
        return Komisi::withoutGlobalScope('cabang')
            ->with(['tindakan:id,kode,nama', 'tagihan:id,no_tagihan,kunjungan_id,pasien_id,dibayar_at', 'tagihan.pasien:id,no_rm,nama'])
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->where('periode', $periode)
            ->where('user_id', $userId)
            ->orderBy('id')
            ->get();
    }

    /** Periode terbuka pertama mulai bulan `$waktu` (periode disetujui dilewati). */
    public function periodeTerbuka(int $cabangId, CarbonInterface $waktu): string
    {
        $bulan = CarbonImmutable::parse($waktu)->startOfMonth();
        $terkunci = PeriodeKomisi::withoutGlobalScope('cabang')
            ->where('cabang_id', $cabangId)->where('status', PeriodeKomisi::DISETUJUI)
            ->where('periode', '>=', $bulan->format('Y-m'))
            ->pluck('periode')->flip();

        while ($terkunci->has($bulan->format('Y-m'))) {
            $bulan = $bulan->addMonth();
        }

        return $bulan->format('Y-m');
    }

    public function terkunci(int $cabangId, string $periode): bool
    {
        return PeriodeKomisi::withoutGlobalScope('cabang')
            ->where('cabang_id', $cabangId)->where('periode', $periode)->where('status', PeriodeKomisi::DISETUJUI)
            ->exists();
    }

    /**
     * Peserta tindakan beserta perannya: pelaksana utama (`petugas_id`) + petugas tambahan.
     * Peran pelaksana utama: dokter bila tercatat sebagai dokter, selain itu terapis — kecuali ia juga tercantum sebagai petugas tambahan.
     *
     * @return Collection<int, array{user_id: int, peran: PeranKomisi}>
     */
    public function peserta(KunjunganTindakan $tindakan): Collection
    {
        $tambahan = $tindakan->petugasTambahan()->get()
            ->map(fn ($p) => ['user_id' => $p->user_id, 'peran' => $p->peran]);

        if ($tindakan->petugas_id && ! $tambahan->contains('user_id', $tindakan->petugas_id)) {
            $utama = User::withTrashed()->find($tindakan->petugas_id);
            $tambahan->prepend([
                'user_id' => $tindakan->petugas_id,
                'peran' => $utama?->punyaIzin(Izin::PemeriksaanDokter) && ! $utama->peran?->akses_penuh
                    ? PeranKomisi::Dokter
                    : PeranKomisi::Terapis,
            ]);
        }

        return $tambahan->values();
    }

    /** Aturan paling spesifik untuk treatment, cabang & peran; null bila tidak ada. */
    public function aturan(Tindakan $tindakan, int $cabangId, PeranKomisi $peran): ?AturanKomisi
    {
        return AturanKomisi::query()
            ->where('is_active', true)
            ->where('peran', $peran)
            ->where(fn ($q) => $q->where('tindakan_id', $tindakan->id)->orWhereNull('tindakan_id'))
            ->where(fn ($q) => $q->where('kategori_id', $tindakan->kategori_id)->orWhereNull('kategori_id'))
            ->where(fn ($q) => $q->where('cabang_id', $cabangId)->orWhereNull('cabang_id'))
            // Aturan treatment tertentu tidak boleh sekaligus mensyaratkan kategori lain
            ->where(fn ($q) => $q->whereNull('tindakan_id')->orWhereNull('kategori_id')->orWhere('kategori_id', $tindakan->kategori_id))
            ->get()
            ->sortByDesc(fn (AturanKomisi $a) => $a->skor() * 1_000_000 + $a->id)
            ->first();
    }

    private function buatBaris(Tagihan $tagihan, string $periode): void
    {
        if (! $tagihan->kunjungan_id) {
            return; // Komisi tindakan hanya dari tagihan kunjungan; penjualan produk/paket = KM-02 (Fase 2)
        }

        $items = $tagihan->items()->where('kategori', 'tindakan')->get();
        $tindakans = KunjunganTindakan::with(['tindakan', 'paketItem'])->where('kunjungan_id', $tagihan->kunjungan_id)->get();
        $faktor = $this->faktorDiskon($tagihan);
        $sisa = $tindakans->keyBy('id');

        foreach ($items as $item) {
            $kt = $this->cocokkan($item, $sisa);
            if (! $kt) {
                continue;
            }
            $sisa->forget($kt->id);

            // Sesi paket ditagih Rp 0 → dasar = nilai per sesi paket (sudah bersih dari diskon saat paket dibeli)
            $dasar = $kt->paket_pasien_item_id && $item->subtotal === 0
                ? (int) ($kt->paketItem?->nilai_per_sesi ?? 0) * $kt->jumlah
                : (int) floor($item->subtotal * $faktor);

            $peserta = $this->peserta($kt);
            $perPeran = $peserta->countBy(fn ($p) => $p['peran']->value);

            foreach ($peserta as $p) {
                $aturan = $this->aturan($kt->tindakan, $tagihan->cabang_id, $p['peran']);
                if (! $aturan) {
                    continue;
                }

                $dibagi = max(1, $perPeran[$p['peran']->value]);
                $jumlah = $aturan->jenis === JenisPotongan::Persen
                    ? (int) round($dasar * $aturan->nilai / 100 / $dibagi)
                    : (int) round($aturan->nilai * $kt->jumlah / $dibagi);

                Komisi::create([
                    'cabang_id' => $tagihan->cabang_id,
                    'periode' => $periode,
                    'user_id' => $p['user_id'],
                    'peran' => $p['peran'],
                    'sumber' => SumberKomisi::Bayar,
                    'tagihan_id' => $tagihan->id,
                    'tagihan_item_id' => $item->id,
                    'kunjungan_tindakan_id' => $kt->id,
                    'tindakan_id' => $kt->tindakan_id,
                    'aturan_id' => $aturan->id,
                    'dasar' => $dasar,
                    'jenis' => $aturan->jenis,
                    'nilai_aturan' => $aturan->nilai,
                    'dibagi' => $dibagi,
                    'jumlah' => $jumlah,
                    'keterangan' => $tagihan->no_tagihan,
                ]);
            }
        }
    }

    /** Pembalik baris bayar untuk refund, ditempatkan di periode tertentu (dipakai hitung ulang). */
    private function batalkanDiPeriode(Tagihan $tagihan, string $periode): void
    {
        foreach (Komisi::withoutGlobalScope('cabang')->where('tagihan_id', $tagihan->id)->where('sumber', SumberKomisi::Bayar)->get() as $baris) {
            Komisi::create([
                ...$baris->only(['cabang_id', 'user_id', 'peran', 'tagihan_id', 'tagihan_item_id', 'kunjungan_tindakan_id',
                    'tindakan_id', 'aturan_id', 'jenis', 'nilai_aturan', 'dibagi']),
                'periode' => $periode,
                'sumber' => SumberKomisi::Refund,
                'dasar' => -$baris->dasar,
                'jumlah' => -$baris->jumlah,
                'keterangan' => "Refund {$tagihan->no_tagihan}",
            ]);
        }
    }

    /** Baris tagihan → tindakan kunjungan; tagihan lama tanpa `kunjungan_tindakan_id` dicocokkan lewat treatment. */
    private function cocokkan(TagihanItem $item, Collection $sisa): ?KunjunganTindakan
    {
        if ($item->kunjungan_tindakan_id) {
            return $sisa->get($item->kunjungan_tindakan_id);
        }

        return $sisa->first(fn (KunjunganTindakan $kt) => $kt->tindakan_id === $item->tindakan_id);
    }

    /** Bagian nilai setelah diskon manual & promo (pajak tidak pernah masuk dasar). */
    private function faktorDiskon(Tagihan $tagihan): float
    {
        if ($this->pengaturan->get('komisi.dasar') === 'sebelum_diskon' || $tagihan->total <= 0) {
            return 1.0;
        }

        return max(0, $tagihan->total - $tagihan->diskon - $tagihan->diskon_promo) / $tagihan->total;
    }

    private function pastikanTerbuka(int $cabangId, string $periode): void
    {
        if ($this->terkunci($cabangId, $periode)) {
            throw ValidationException::withMessages(['periode' => "Periode komisi {$periode} sudah disetujui dan terkunci."]);
        }
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function rentang(string $periode): array
    {
        $awal = CarbonImmutable::createFromFormat('Y-m-d', "{$periode}-01")->startOfDay();

        return [$awal, $awal->endOfMonth()];
    }
}
