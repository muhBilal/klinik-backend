<?php

namespace App\Services;

use App\Enums\Izin;
use App\Enums\MetodeBayar;
use App\Enums\StatusKunjungan;
use App\Enums\StatusTagihan;
use App\Models\PaketPasien;
use App\Models\Pembayaran;
use App\Models\ShiftKas;
use App\Models\Tagihan;
use App\Models\TagihanItem;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Kasir: shift kas, split payment, batas diskon per peran, void & refund
 * (PRD BL-02, BL-03, BL-05, BL-06; temuan teknis 8.3 #7).
 */
class KasirService
{
    public function __construct(private PengaturanService $pengaturan) {}

    /** Shift kasir yang masih terbuka di cabang ini, bila ada. */
    public function shiftTerbuka(int $cabangId, User $kasir): ?ShiftKas
    {
        return ShiftKas::withoutGlobalScope('cabang')
            ->where('cabang_id', $cabangId)->where('kasir_id', $kasir->id)
            ->whereNull('ditutup_at')
            ->first();
    }

    public function bukaShift(int $cabangId, User $kasir, int $modalAwal): ShiftKas
    {
        if ($this->shiftTerbuka($cabangId, $kasir)) {
            throw ValidationException::withMessages(['shift' => 'Masih ada shift yang belum ditutup.']);
        }

        return ShiftKas::create([
            'cabang_id' => $cabangId,
            'kasir_id' => $kasir->id,
            'dibuka_at' => now(),
            'modal_awal' => $modalAwal,
        ]);
    }

    /**
     * Tutup shift; `selisih` = kas fisik dikurangi (modal awal + tunai masuk).
     * Selisih negatif berarti uang kurang.
     */
    public function tutupShift(ShiftKas $shift, int $kasFisik, ?string $catatan): ShiftKas
    {
        return DB::transaction(function () use ($shift, $kasFisik, $catatan) {
            if (! $shift->terbuka()) {
                throw ValidationException::withMessages(['shift' => 'Shift ini sudah ditutup.']);
            }

            $tunai = (int) $shift->pembayarans()->berlaku()->where('metode', MetodeBayar::Tunai)->sum('jumlah');
            $refundTunai = $this->refundPaket($shift, MetodeBayar::Tunai);

            $shift->update([
                'ditutup_at' => now(),
                'kas_fisik' => $kasFisik,
                'selisih' => $kasFisik - ($shift->modal_awal + $tunai - $refundTunai),
                'catatan' => $catatan,
            ]);

            return $shift;
        });
    }

    /**
     * Rekap shift per metode bayar, untuk layar tutup shift & cetak. Baris pembayaran tunai menyimpan uang yang diserahkan
     * pasien; kembalian (`tagihans.kembalian`) dikurangkan agar total & kas seharusnya = uang yang benar-benar masuk laci.
     */
    public function rekapShift(ShiftKas $shift): array
    {
        $kembalian = fn (bool $direfund) => (int) Tagihan::withoutGlobalScope('cabang')
            ->whereIn('id', $shift->pembayarans()->where('metode', MetodeBayar::Tunai)
                ->when($direfund, fn ($q) => $q->whereNotNull('dikembalikan_at'), fn ($q) => $q->whereNull('dikembalikan_at'))
                ->select('tagihan_id'))
            ->sum('kembalian');
        $kembalianBerlaku = $kembalian(false);

        $perMetode = $shift->pembayarans()->berlaku()
            ->selectRaw('metode, COUNT(*) AS jumlah_transaksi, SUM(jumlah) AS total')
            ->groupBy('metode')->get()
            ->map(fn ($b) => [
                'metode' => $b->metode->value,
                'jumlah_transaksi' => (int) $b->jumlah_transaksi,
                'total' => (int) $b->total - ($b->metode === MetodeBayar::Tunai ? $kembalianBerlaku : 0),
            ])->values();

        $refund = (int) $shift->pembayarans()->whereNotNull('dikembalikan_at')->sum('jumlah') - $kembalian(true);
        $tunai = (int) ($perMetode->firstWhere('metode', MetodeBayar::Tunai->value)['total'] ?? 0);
        // Pengembalian sisa paket (TR-02) = uang keluar di shift ini; yang tunai mengurangi kas seharusnya.
        $refundPaket = $this->refundPaket($shift);
        $refundPaketTunai = $this->refundPaket($shift, MetodeBayar::Tunai);

        return [
            'per_metode' => $perMetode,
            'total' => (int) $perMetode->sum('total'),
            'total_refund' => $refund + $refundPaket,
            'refund_paket' => $refundPaket,
            'kas_seharusnya' => $shift->modal_awal + $tunai - $refundPaketTunai,
        ];
    }

    private function refundPaket(ShiftKas $shift, ?MetodeBayar $metode = null): int
    {
        return (int) PaketPasien::where('refund_shift_id', $shift->id)
            ->when($metode, fn ($q) => $q->where('refund_metode', $metode))
            ->sum('refund_nominal');
    }

    /**
     * Bayar tagihan, boleh beberapa metode sekaligus (BL-03). Kode promo yang terpasang diperiksa ulang & dihitung ulang
     * (kuota bisa habis sejak dipasang) lalu dicatat pemakaiannya (TR-06); paket yang dijual lewat tagihan ini aktif (TR-02).
     *
     * @param  list<array{metode: string, jumlah: int, referensi?: string}>  $pembayarans
     * @param  int  $diskon  diskon manual nominal; dibatasi oleh batas peran (BL-02), di luar potongan promo
     * @param  string  $kunciNominal  field tujuan pesan error nominal; klien lama memakai `dibayar`
     * @param  array{email: string, password: string}|null  $persetujuan  kredensial atasan bila diskon di atas batas peran
     */
    public function bayar(Tagihan $tagihan, array $pembayarans, int $diskon, User $kasir, string $kunciNominal = 'pembayarans', ?array $persetujuan = null): Tagihan
    {
        return DB::transaction(function () use ($tagihan, $pembayarans, $diskon, $kasir, $kunciNominal, $persetujuan) {
            $tagihan = Tagihan::withoutGlobalScope('cabang')->whereKey($tagihan->id)->lockForUpdate()->firstOrFail();

            if ($tagihan->status !== StatusTagihan::BelumBayar) {
                throw ValidationException::withMessages(['status' => 'Tagihan ini sudah dibayar atau dibatalkan.']);
            }

            $diskonPromo = $tagihan->promo_id
                ? app(PromoService::class)->hitung($tagihan->promo()->firstOrFail(), $tagihan, kunci: true)
                : 0;

            if ($diskon + $diskonPromo > $tagihan->total) {
                throw ValidationException::withMessages(['diskon' => 'Diskon (termasuk potongan promo) tidak boleh melebihi total tagihan.']);
            }

            $penyetuju = $this->pastikanDiskonDiizinkan($tagihan, $diskon, $kasir, $persetujuan);

            $grandTotal = $this->hitungGrandTotal($tagihan->total, $diskon + $diskonPromo, $tagihan->pajak_persen);
            // Tagihan Rp 0 (mis. seluruhnya sesi paket di poli tanpa biaya konsultasi) boleh dilunasi tanpa pembayaran.
            $baris = $this->normalkanPembayaran($pembayarans, $kunciNominal, bolehKosong: $grandTotal === 0);
            $dibayar = (int) $baris->sum('jumlah');

            if ($dibayar < $grandTotal) {
                throw ValidationException::withMessages([$kunciNominal => 'Nominal pembayaran kurang dari total tagihan.']);
            }

            // Hanya tunai yang boleh berlebih (kembalian); non-tunai harus pas.
            $nonTunai = (int) $baris->where('metode', '!=', MetodeBayar::Tunai->value)->sum('jumlah');

            if ($nonTunai > $grandTotal) {
                throw ValidationException::withMessages([$kunciNominal => 'Pembayaran non-tunai tidak boleh melebihi tagihan.']);
            }

            $shift = $this->shiftTerbuka($tagihan->cabang_id, $kasir);

            if (! $shift && $this->pengaturan->get('keuangan.wajib_shift')) {
                throw ValidationException::withMessages(['shift' => 'Buka shift kas terlebih dahulu sebelum menerima pembayaran.']);
            }

            $tagihan->update([
                'diskon' => $diskon,
                'diskon_disetujui_oleh' => $penyetuju?->id,
                'diskon_promo' => $diskonPromo,
                'pajak' => $grandTotal - ($tagihan->total - $diskon - $diskonPromo),
                'grand_total' => $grandTotal,
                'metode_bayar' => $baris->count() === 1 ? $baris->first()['metode'] : null,
                'dibayar' => $dibayar,
                'kembalian' => $dibayar - $grandTotal,
                'status' => StatusTagihan::Lunas,
                'kasir_id' => $kasir->id,
                'shift_id' => $shift?->id,
                'dibayar_at' => now(),
            ]);

            foreach ($baris as $b) {
                $tagihan->pembayarans()->create([
                    'shift_id' => $shift?->id,
                    'metode' => $b['metode'],
                    'jumlah' => $b['jumlah'],
                    'referensi' => $b['referensi'] ?? null,
                    'kasir_id' => $kasir->id,
                    'dibayar_at' => now(),
                ]);
            }

            if ($penyetuju) {
                app(AuditService::class)->catat('setujui_diskon', 'tagihan', $tagihan->id, [
                    'label' => $tagihan->no_tagihan,
                    'pasien_id' => $tagihan->pasien_id ?? $tagihan->kunjungan?->pasien_id,
                    'perubahan' => [
                        'diskon' => ['lama' => null, 'baru' => $diskon],
                        'penyetuju' => ['lama' => null, 'baru' => $penyetuju->name],
                        'kasir' => ['lama' => null, 'baru' => $kasir->name],
                    ],
                ]);
            }

            $this->alokasikanNeto($tagihan);
            app(PromoService::class)->catatPemakaian($tagihan);
            app(PaketService::class)->aktifkanDariTagihan($tagihan);

            // Kunjungan selesai hanya bila semua tagihannya sudah lunas/batal.
            if ($kunjungan = $tagihan->kunjungan) {
                $masihAda = $kunjungan->tagihans()
                    ->where('status', StatusTagihan::BelumBayar)
                    ->exists();

                if (! $masihAda) {
                    $kunjungan->update(['status' => StatusKunjungan::Selesai]);
                }
            }

            return $tagihan;
        });
    }

    /**
     * Nilai bersih per baris saat lunas (`tagihan_items.neto`, sebelum pajak): potongan promo dibagi ke baris yang memenuhi syarat promo
     * (sebanding subtotal), lalu diskon manual dibagi ke semua baris sebanding sisanya. Pembulatan ke rupiah dengan metode sisa terbesar
     * sehingga Σ neto = total − diskon − potongan promo. Satu sumber untuk nilai paket, komisi (dasar neto) & laporan penjualan.
     */
    private function alokasikanNeto(Tagihan $tagihan): void
    {
        $items = $tagihan->items()->get()->keyBy('id');
        $neto = $items->map(fn (TagihanItem $i) => $i->subtotal)->all();

        $layak = app(PromoService::class)->barisMemenuhiSyarat($tagihan, $items);
        $this->kurangiSebanding($neto, $layak->map(fn (TagihanItem $i) => $i->subtotal)->all(), (int) $tagihan->diskon_promo);
        $this->kurangiSebanding($neto, $neto, (int) $tagihan->diskon);

        foreach ($items as $id => $item) {
            $item->update(['neto' => max(0, $neto[$id])]);
        }
    }

    /**
     * Kurangi `$jumlah` dari `$nilai` sebanding `$bobot` (id => bobot, bilangan bulat): tiap bagian dibulatkan ke bawah, sisanya diberikan
     * satu-satu ke baris dengan sisa bagi terbesar, sehingga total yang dikurangi tepat `$jumlah`.
     *
     * @param  array<int, int>  $nilai
     * @param  array<int, int>  $bobot
     */
    private function kurangiSebanding(array &$nilai, array $bobot, int $jumlah): void
    {
        $totalBobot = array_sum($bobot);
        if ($jumlah <= 0 || $totalBobot <= 0) {
            return;
        }

        $sisaBagi = [];
        $terbagi = 0;
        foreach ($bobot as $id => $b) {
            $bagian = intdiv($b * $jumlah, $totalBobot);
            $sisaBagi[$id] = ($b * $jumlah) % $totalBobot;
            $nilai[$id] -= $bagian;
            $terbagi += $bagian;
        }
        arsort($sisaBagi);
        foreach (array_slice(array_keys($sisaBagi), 0, $jumlah - $terbagi) as $id) {
            $nilai[$id]--;
        }
    }

    /** Batalkan (void) tagihan yang belum dibayar — BL-06, temuan 8.3 #7. */
    public function batal(Tagihan $tagihan, string $alasan, User $user): Tagihan
    {
        return DB::transaction(function () use ($tagihan, $alasan, $user) {
            $tagihan = Tagihan::withoutGlobalScope('cabang')->whereKey($tagihan->id)->lockForUpdate()->firstOrFail();

            if ($tagihan->status !== StatusTagihan::BelumBayar) {
                throw ValidationException::withMessages([
                    'status' => 'Hanya tagihan yang belum dibayar yang bisa dibatalkan. Tagihan lunas gunakan refund.',
                ]);
            }

            $tagihan->update([
                'status' => StatusTagihan::Batal,
                'dibatalkan_at' => now(),
                'dibatalkan_oleh' => $user->id,
                'alasan_batal' => $alasan,
            ]);

            app(PaketService::class)->batalDariTagihan($tagihan);

            return $tagihan;
        });
    }

    /**
     * Refund seluruh pembayaran tagihan yang sudah lunas (BL-06).
     * Tagihan kembali ke status batal dengan jejak siapa & alasannya.
     */
    public function refund(Tagihan $tagihan, string $alasan, User $user): Tagihan
    {
        return DB::transaction(function () use ($tagihan, $alasan, $user) {
            $tagihan = Tagihan::withoutGlobalScope('cabang')->whereKey($tagihan->id)->lockForUpdate()->firstOrFail();

            if ($tagihan->status !== StatusTagihan::Lunas) {
                throw ValidationException::withMessages(['status' => 'Hanya tagihan lunas yang bisa direfund.']);
            }

            // Paket yang dijual lewat tagihan ini hanya bisa direfund penuh bila belum dipakai (TR-02); kuota promo kembali.
            app(PaketService::class)->refundPenuhDariTagihan($tagihan, $alasan, $user);
            app(PromoService::class)->batalkanPemakaian($tagihan);

            // Per model agar setiap refund tercatat di audit log.
            foreach ($tagihan->pembayarans()->berlaku()->get() as $pembayaran) {
                $pembayaran->update([
                    'dikembalikan_at' => now(),
                    'dikembalikan_oleh' => $user->id,
                    'alasan_refund' => $alasan,
                ]);
            }

            $tagihan->update([
                'status' => StatusTagihan::Batal,
                'dibatalkan_at' => now(),
                'dibatalkan_oleh' => $user->id,
                'alasan_batal' => $alasan,
            ]);

            if ($kunjungan = $tagihan->kunjungan) {
                $kunjungan->update(['status' => StatusKunjungan::MenungguPembayaran]);
            }

            return $tagihan;
        });
    }

    /** Grand total setelah diskon dan pajak (AD-04). Pajak dihitung dari nilai setelah diskon. */
    public function hitungGrandTotal(int $total, int $diskon, int $pajakPersen): int
    {
        $setelahDiskon = $total - $diskon;

        return $setelahDiskon + (int) round($setelahDiskon * $pajakPersen / 100);
    }

    /** Tarif pajak berlaku (persen) dari pengaturan klinik. */
    public function pajakPersen(): int
    {
        return (int) $this->pengaturan->get('keuangan.pajak_persen');
    }

    /**
     * Batas diskon per peran (BL-02): persen maksimum dari total, dari pengaturan
     * `keuangan.batas_diskon_persen` = {kode_peran: persen}.
     *
     * Peran berakses penuh dan peran yang tidak tercantum tidak dibatasi — batas bersifat opt-in
     * agar klinik yang belum mengaturnya tetap berjalan seperti sebelumnya. Persen 0 = dilarang.
     *
     * Diskon di atas batas boleh bila atasan menyetujui di tempat (PRD v2 9.6): email + password atasan yang aktif,
     * bertugas di cabang tagihan (atau lintas cabang), memegang `kasir.diskon`, dan batas perannya sendiri mencukupi.
     * Mengembalikan penyetuju (null bila tidak perlu persetujuan).
     *
     * @param  array{email: string, password: string}|null  $persetujuan
     */
    private function pastikanDiskonDiizinkan(Tagihan $tagihan, int $diskon, User $kasir, ?array $persetujuan = null): ?User
    {
        if ($this->dalamBatas($tagihan, $diskon, $kasir)) {
            return null;
        }

        if (! $persetujuan) {
            $persen = $this->batasPersen($kasir);

            throw ValidationException::withMessages([
                'diskon' => $persen === 0
                    ? 'Peran Anda tidak boleh memberi diskon. Minta persetujuan atasan.'
                    : "Diskon melebihi batas peran Anda ({$persen}% = Rp ".number_format($this->batasNominal($tagihan, $persen), 0, ',', '.').'). Minta persetujuan atasan.',
                'perlu_persetujuan' => 'Diskon ini butuh persetujuan atasan.',
            ]);
        }

        $kunci = 'setujui-diskon:'.strtolower($persetujuan['email']).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($kunci, 5)) {
            throw ValidationException::withMessages([
                'persetujuan' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($kunci).' detik.',
            ]);
        }

        $penyetuju = User::where('email', $persetujuan['email'])->where('is_active', true)->first();

        if (! $penyetuju || ! Hash::check($persetujuan['password'], $penyetuju->password)) {
            RateLimiter::hit($kunci, 60);

            throw ValidationException::withMessages(['persetujuan' => 'Email atau password atasan salah.']);
        }

        RateLimiter::clear($kunci);

        if ($penyetuju->is($kasir)) {
            throw ValidationException::withMessages(['persetujuan' => 'Persetujuan harus dari pengguna lain (atasan).']);
        }

        if (! $penyetuju->punyaIzin(Izin::KasirDiskon)) {
            throw ValidationException::withMessages(['persetujuan' => "{$penyetuju->name} tidak berwenang menyetujui diskon."]);
        }

        if ($penyetuju->cabang_id !== null && $penyetuju->cabang_id !== $tagihan->cabang_id) {
            throw ValidationException::withMessages(['persetujuan' => "{$penyetuju->name} tidak bertugas di cabang tagihan ini."]);
        }

        if (! $this->dalamBatas($tagihan, $diskon, $penyetuju)) {
            throw ValidationException::withMessages(['persetujuan' => "Diskon juga melebihi batas peran {$penyetuju->name}."]);
        }

        return $penyetuju;
    }

    private function dalamBatas(Tagihan $tagihan, int $diskon, User $user): bool
    {
        if ($diskon === 0 || $user->peran?->akses_penuh) {
            return true;
        }

        $persen = $this->batasPersen($user);

        return $persen === null || $diskon <= $this->batasNominal($tagihan, $persen);
    }

    /** Persen batas diskon peran user, atau null bila tidak dibatasi. */
    private function batasPersen(User $user): ?int
    {
        $batas = $this->pengaturan->get('keuangan.batas_diskon_persen') ?? [];

        return array_key_exists($user->role, $batas) ? (int) $batas[$user->role] : null;
    }

    private function batasNominal(Tagihan $tagihan, int $persen): int
    {
        return (int) floor($tagihan->total * $persen / 100);
    }

    /**
     * @param  list<array{metode: string, jumlah: int, referensi?: string}>  $pembayarans
     * @return Collection<int, array{metode: string, jumlah: int, referensi?: string}>
     */
    private function normalkanPembayaran(array $pembayarans, string $kunciNominal, bool $bolehKosong = false): Collection
    {
        $baris = collect($pembayarans)->filter(fn ($b) => (int) $b['jumlah'] > 0)->values();

        if ($baris->isEmpty() && ! $bolehKosong) {
            throw ValidationException::withMessages([$kunciNominal => 'Masukkan minimal satu pembayaran.']);
        }

        return $baris;
    }
}
