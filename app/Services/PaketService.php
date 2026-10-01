<?php

namespace App\Services;

use App\Enums\MetodeBayar;
use App\Enums\StatusKunjungan;
use App\Enums\StatusPaketPasien;
use App\Models\Kunjungan;
use App\Models\Paket;
use App\Models\PaketPasien;
use App\Models\PaketPasienItem;
use App\Models\Pasien;
use App\Models\Tagihan;
use App\Models\User;
use App\Support\CabangAktif;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Paket multi-sesi milik pasien (PRD TR-02, BL-01).
 *
 * Alur: jual (tagihan mandiri, status menunggu_bayar) → tagihan lunas = aktif (masa berlaku mulai, nilai bersih dialokasikan
 * per sesi) → sesi dipakai lewat `kunjungan_tindakans.paket_pasien_item_id` (ditagih Rp 0) → habis / kedaluwarsa (dihitung).
 * Sisa sesi = jumlah sesi − tindakan kunjungan bukan batal yang memakainya; kunjungan yang masih terbuka ikut mengurangi sisa
 * ("dipesan") agar satu sesi tidak dipakai dua kunjungan sekaligus.
 */
class PaketService
{
    public const RELASI = [
        'items.tindakan:id,kode,nama',
        'cabang:id,kode,nama',
        'tagihan:id,no_tagihan,status,grand_total,cabang_id',
        'pembuat:id,name',
        'dialihkanDari:id,no_paket,pasien_id',
        'dialihkanDari.pasien:id,no_rm,nama',
        'dialihkanKe:id,no_paket,pasien_id,dialihkan_dari_id',
        'dialihkanKe.pasien:id,no_rm,nama',
    ];

    /** Atribut hitungan `ringkas()` — bukan kolom; dilepas sebelum model disimpan. */
    private const RINGKASAN = ['total_sesi', 'sisa_sesi', 'nilai_terpakai', 'status_efektif'];

    public function __construct(
        private NomorUrutService $nomor,
        private TagihanService $tagihan,
        private PengaturanService $pengaturan,
        private CabangAktif $cabang,
        private AuditService $audit,
        private KasirService $kasir,
    ) {}

    /** Jual paket: buat paket (menunggu bayar) + tagihan mandiri berisi satu baris paket. */
    public function jual(Pasien $pasien, Paket $paket, ?string $catatan, User $user): PaketPasien
    {
        $paket->loadMissing('items');

        if (! $paket->is_active || $paket->items->isEmpty()) {
            throw ValidationException::withMessages(['paket_id' => 'Paket tidak aktif atau belum berisi treatment.']);
        }

        return DB::transaction(function () use ($pasien, $paket, $catatan, $user) {
            $noPaket = $this->nomor->noPaket(now());
            $tagihan = $this->tagihan->buatMandiri($this->cabang->untukDataBaru(), $pasien->id, [[
                'kategori' => 'paket',
                'paket_id' => $paket->id,
                'deskripsi' => "Paket {$paket->nama} ({$noPaket})",
                'jumlah' => 1,
                'harga' => $paket->harga,
            ]], "Penjualan paket {$noPaket}");

            $paketPasien = PaketPasien::create([
                'no_paket' => $noPaket,
                'pasien_id' => $pasien->id,
                'paket_id' => $paket->id,
                'cabang_id' => $tagihan->cabang_id,
                'tagihan_id' => $tagihan->id,
                'nama' => $paket->nama,
                'harga' => $paket->harga,
                'status' => StatusPaketPasien::MenungguBayar,
                'lintas_cabang' => $paket->lintas_cabang,
                'masa_berlaku_hari' => $paket->masa_berlaku_hari,
                'catatan' => $catatan,
                'dibuat_oleh' => $user->id,
            ]);

            foreach ($paket->items as $item) {
                $paketPasien->items()->create(['tindakan_id' => $item->tindakan_id, 'jumlah_sesi' => $item->jumlah_sesi]);
            }

            return $this->muat($paketPasien);
        });
    }

    /**
     * Tagihan penjualan lunas → paket aktif. Nilai bersih = subtotal baris paket setelah bagian proporsional diskon tagihan
     * (manual + promo, sebelum pajak), lalu dialokasikan ke tiap treatment sebanding tarif dasar × jumlah sesi.
     */
    public function aktifkanDariTagihan(Tagihan $tagihan): void
    {
        $pakets = PaketPasien::where('tagihan_id', $tagihan->id)->where('status', StatusPaketPasien::MenungguBayar)->with('items.tindakan')->get();
        if ($pakets->isEmpty()) {
            return;
        }

        $baris = $tagihan->items()->whereNotNull('paket_id')->get();
        $faktor = $tagihan->total > 0 ? ($tagihan->total - $tagihan->diskon - $tagihan->diskon_promo) / $tagihan->total : 0;

        foreach ($pakets as $paket) {
            $subtotal = (int) ($baris->firstWhere('paket_id', $paket->paket_id)?->subtotal ?? $paket->harga);
            $nilai = (int) round($subtotal * $faktor);

            $paket->update([
                'status' => StatusPaketPasien::Aktif,
                'nilai' => $nilai,
                'aktif_at' => now(),
                'berlaku_sampai' => $paket->masa_berlaku_hari ? today()->addDays($paket->masa_berlaku_hari) : null,
            ]);
            $this->alokasikanNilai($paket, $nilai);
        }
    }

    /** Tagihan penjualan dibatalkan sebelum dibayar → paket batal. */
    public function batalDariTagihan(Tagihan $tagihan): void
    {
        PaketPasien::where('tagihan_id', $tagihan->id)->where('status', StatusPaketPasien::MenungguBayar)->get()
            ->each->update(['status' => StatusPaketPasien::Dibatalkan]);
    }

    /**
     * Refund penuh tagihan penjualan (BL-06) hanya untuk paket yang belum pernah dipakai; paket yang sudah dipakai
     * mengikuti kebijakan refund sisa (`refundSisa`).
     */
    public function refundPenuhDariTagihan(Tagihan $tagihan, string $alasan, User $user): void
    {
        foreach (PaketPasien::where('tagihan_id', $tagihan->id)->with('items')->lockForUpdate()->get() as $paket) {
            if ($paket->status !== StatusPaketPasien::Aktif) {
                throw ValidationException::withMessages(['status' => "Paket {$paket->no_paket} sudah {$paket->status->value}; tagihannya tidak bisa direfund."]);
            }

            $dipakai = collect($this->pemakaian($paket->items->pluck('id')))->sum(fn ($p) => $p['terpakai'] + $p['dipesan']);
            if ($dipakai > 0) {
                throw ValidationException::withMessages([
                    'status' => "Paket {$paket->no_paket} sudah dipakai {$dipakai} sesi. Gunakan pengembalian sisa paket sesuai kebijakan klinik.",
                ]);
            }

            $paket->update([
                'status' => StatusPaketPasien::Direfund,
                'refund_nominal' => $paket->nilai,
                'direfund_at' => now(),
                'direfund_oleh' => $user->id,
                'alasan_refund' => $alasan,
            ]);
        }
    }

    /**
     * Item paket yang dipakai tindakan kunjungan: milik pasien kunjungan, aktif, belum kedaluwarsa pada tanggal kunjungan,
     * berlaku di cabang kunjungan, treatment sama, dan sisa sesi cukup. Paket dikunci agar dua kunjungan tidak memakai
     * sesi terakhir bersamaan.
     */
    public function pastikanBisaDipakai(int $itemId, int $tindakanId, int $jumlah, Kunjungan $kunjungan, ?int $kunjunganTindakanId, string $kunci): PaketPasienItem
    {
        $item = PaketPasienItem::with('tindakan:id,nama')->find($itemId);
        $paket = $item ? PaketPasien::whereKey($item->paket_pasien_id)->lockForUpdate()->with('cabang:id,nama')->first() : null;

        $gagal = fn (string $pesan) => throw ValidationException::withMessages([$kunci => $pesan]);

        if (! $paket || $paket->pasien_id !== (int) $kunjungan->pasien_id) {
            $gagal('Paket tidak ditemukan untuk pasien ini.');
        }
        if ($paket->status !== StatusPaketPasien::Aktif) {
            $gagal($paket->status === StatusPaketPasien::MenungguBayar
                ? "Paket {$paket->no_paket} belum aktif — tagihan penjualannya belum lunas."
                : "Paket {$paket->no_paket} sudah {$paket->status->value}.");
        }
        if ($paket->berlaku_sampai && $paket->berlaku_sampai->lt($kunjungan->tanggal)) {
            $gagal("Paket {$paket->no_paket} kedaluwarsa sejak {$paket->berlaku_sampai->translatedFormat('d M Y')}.");
        }
        if (! $paket->lintas_cabang && $paket->cabang_id !== (int) $kunjungan->cabang_id) {
            $gagal("Paket {$paket->no_paket} hanya berlaku di cabang {$paket->cabang?->nama}.");
        }
        if ($item->tindakan_id !== $tindakanId) {
            $gagal("Sesi paket ini untuk {$item->tindakan?->nama}, bukan treatment yang dipilih.");
        }

        $p = $this->pemakaian([$item->id], $kunjunganTindakanId)[$item->id];
        $sisa = $item->jumlah_sesi - $p['terpakai'] - $p['dipesan'];
        if ($jumlah > $sisa) {
            $gagal("Sisa sesi {$item->tindakan?->nama} di paket {$paket->no_paket} tinggal {$sisa}.");
        }

        return $item;
    }

    /**
     * Pemakaian per item: `terpakai` (kunjungan sudah ditutup) & `dipesan` (kunjungan masih terbuka). Kunjungan batal tidak dihitung.
     *
     * @param  iterable<int>  $itemIds
     * @return array<int, array{terpakai: int, dipesan: int}>
     */
    public function pemakaian(iterable $itemIds, ?int $kecualiKunjunganTindakanId = null): array
    {
        $ids = collect($itemIds)->values();
        $hasil = $ids->mapWithKeys(fn ($id) => [$id => ['terpakai' => 0, 'dipesan' => 0]])->all();

        if ($ids->isEmpty()) {
            return $hasil;
        }

        $baris = DB::table('kunjungan_tindakans as kt')
            ->join('kunjungans as k', 'k.id', '=', 'kt.kunjungan_id')
            ->whereIn('kt.paket_pasien_item_id', $ids)
            ->where('k.status', '!=', StatusKunjungan::Batal->value)
            ->when($kecualiKunjunganTindakanId, fn ($q) => $q->where('kt.id', '!=', $kecualiKunjunganTindakanId))
            ->groupBy('kt.paket_pasien_item_id', 'k.status')
            ->selectRaw('kt.paket_pasien_item_id AS item_id, k.status, SUM(kt.jumlah) AS n')
            ->get();

        foreach ($baris as $b) {
            $terbuka = in_array($b->status, [StatusKunjungan::Menunggu->value, StatusKunjungan::Diperiksa->value], true);
            $hasil[$b->item_id][$terbuka ? 'dipesan' : 'terpakai'] += (int) $b->n;
        }

        return $hasil;
    }

    /** Tambahkan sisa sesi per item & ringkasan paket (status efektif, total/sisa sesi, nilai terpakai). */
    public function ringkas(PaketPasien $paket): PaketPasien
    {
        $pemakaian = $this->pemakaian($paket->items->pluck('id'));
        $nilaiTerpakai = 0;

        foreach ($paket->items as $item) {
            $p = $pemakaian[$item->id];
            $item->setAttribute('terpakai', $p['terpakai']);
            $item->setAttribute('dipesan', $p['dipesan']);
            // Paket yang tidak aktif lagi (dibatalkan/direfund/dialihkan) tidak punya sisa yang bisa dipakai.
            $sisa = $paket->status === StatusPaketPasien::Aktif || $paket->status === StatusPaketPasien::MenungguBayar
                ? max(0, $item->jumlah_sesi - $p['terpakai'] - $p['dipesan']) : 0;
            $item->setAttribute('sisa', $sisa);
            $nilaiTerpakai += $p['terpakai'] * $item->nilai_per_sesi;
        }

        $sisaSesi = (int) $paket->items->sum('sisa');
        $paket->setAttribute('total_sesi', (int) $paket->items->sum('jumlah_sesi'));
        $paket->setAttribute('sisa_sesi', $sisaSesi);
        $paket->setAttribute('nilai_terpakai', $nilaiTerpakai);
        $paket->setAttribute('status_efektif', $paket->statusEfektif($sisaSesi));

        return $paket;
    }

    public function muat(PaketPasien $paket): PaketPasien
    {
        return $this->ringkas($paket->load(self::RELASI));
    }

    /** Perpanjang masa berlaku (kebijakan manajer, mis. pasien sakit); alasan tercatat di audit log. */
    public function perpanjang(PaketPasien $paket, CarbonInterface $sampai, string $alasan): PaketPasien
    {
        if ($paket->status !== StatusPaketPasien::Aktif) {
            throw ValidationException::withMessages(['status' => 'Hanya paket aktif yang bisa diperpanjang.']);
        }
        if ($paket->berlaku_sampai && $sampai->lte($paket->berlaku_sampai)) {
            throw ValidationException::withMessages(['berlaku_sampai' => 'Tanggal baru harus setelah masa berlaku saat ini.']);
        }

        $lama = $paket->berlaku_sampai?->toDateString() ?? 'tanpa batas';
        $paket->update(['berlaku_sampai' => $sampai->toDateString()]);
        $this->audit->catat('perpanjang_paket', 'paket_pasien', $paket->id, [
            'pasien_id' => $paket->pasien_id,
            'label' => "{$paket->no_paket}: {$lama} → {$sampai->toDateString()} · {$alasan}",
        ]);

        return $this->muat($paket);
    }

    /**
     * Alihkan sisa sesi ke pasien lain (bila kebijakan klinik mengizinkan): paket baru untuk pasien tujuan berisi sisa sesi,
     * nilai per sesi & masa berlaku yang sama; paket asal `dialihkan`.
     */
    public function alihkan(PaketPasien $paket, Pasien $tujuan, string $alasan, User $user): PaketPasien
    {
        if (! $this->pengaturan->get('paket.boleh_transfer')) {
            throw ValidationException::withMessages(['pasien_id' => 'Kebijakan klinik tidak mengizinkan paket dialihkan ke pasien lain.']);
        }
        if ($tujuan->id === $paket->pasien_id) {
            throw ValidationException::withMessages(['pasien_id' => 'Pilih pasien lain sebagai penerima paket.']);
        }

        return DB::transaction(function () use ($paket, $tujuan, $alasan, $user) {
            $paket = $this->ringkas(PaketPasien::whereKey($paket->id)->lockForUpdate()->with('items')->firstOrFail());
            $this->pastikanSisaBisaDiproses($paket);
            $nilaiSisa = (int) $paket->items->sum(fn ($i) => $i->sisa * $i->nilai_per_sesi);
            $this->lepasRingkasan($paket);

            $baru = PaketPasien::create([
                'no_paket' => $this->nomor->noPaket(now()),
                'pasien_id' => $tujuan->id,
                'paket_id' => $paket->paket_id,
                'cabang_id' => $paket->cabang_id,
                'nama' => $paket->nama,
                'harga' => 0,
                'nilai' => $nilaiSisa,
                'status' => StatusPaketPasien::Aktif,
                'lintas_cabang' => $paket->lintas_cabang,
                'masa_berlaku_hari' => $paket->masa_berlaku_hari,
                'aktif_at' => now(),
                'berlaku_sampai' => $paket->berlaku_sampai,
                'catatan' => "Dialihkan dari {$paket->no_paket}",
                'dibuat_oleh' => $user->id,
                'dialihkan_dari_id' => $paket->id,
            ]);

            foreach ($paket->items->where('sisa', '>', 0) as $item) {
                $baru->items()->create(['tindakan_id' => $item->tindakan_id, 'jumlah_sesi' => $item->sisa, 'nilai_per_sesi' => $item->nilai_per_sesi]);
            }

            $paket->update([
                'status' => StatusPaketPasien::Dialihkan,
                'dialihkan_at' => now(),
                'dialihkan_oleh' => $user->id,
                'alasan_alih' => $alasan,
            ]);

            return $this->muat($baru);
        });
    }

    /**
     * Pengembalian dana sisa paket yang sudah dipakai sebagian (bila kebijakan mengizinkan): nominal = nilai sesi tersisa
     * dikurangi potongan persen pengaturan. Dicatat ke shift kasir yang sedang terbuka (mengurangi kas seharusnya bila tunai).
     */
    public function refundSisa(PaketPasien $paket, MetodeBayar $metode, ?string $referensi, string $alasan, User $user): PaketPasien
    {
        if (! $this->pengaturan->get('paket.refund_sisa')) {
            throw ValidationException::withMessages(['status' => 'Kebijakan klinik: sisa paket yang sudah dipakai tidak dapat diuangkan.']);
        }

        return DB::transaction(function () use ($paket, $metode, $referensi, $alasan, $user) {
            $paket = $this->ringkas(PaketPasien::whereKey($paket->id)->lockForUpdate()->with('items')->firstOrFail());
            $this->pastikanSisaBisaDiproses($paket);

            $sisaNilai = (int) $paket->items->sum(fn ($i) => $i->sisa * $i->nilai_per_sesi);
            $potongan = (int) round($sisaNilai * (int) $this->pengaturan->get('paket.potongan_refund_persen') / 100);
            // Shift kasir yang memproses di cabang aktif (uang keluar dari laci kas cabang itu).
            $cabangId = $this->cabang->id() ?? $paket->cabang_id;
            $shift = $cabangId ? $this->kasir->shiftTerbuka($cabangId, $user) : null;
            $this->lepasRingkasan($paket);

            $paket->update([
                'status' => StatusPaketPasien::Direfund,
                'refund_nominal' => $sisaNilai - $potongan,
                'refund_metode' => $metode,
                'refund_referensi' => $referensi,
                'refund_shift_id' => $shift?->id,
                'direfund_at' => now(),
                'direfund_oleh' => $user->id,
                'alasan_refund' => $alasan,
            ]);

            return $this->muat($paket);
        });
    }

    /** Ringkasan nominal refund sisa (untuk ditampilkan sebelum diproses). */
    public function simulasiRefundSisa(PaketPasien $paket): array
    {
        $paket = $this->ringkas($paket->loadMissing('items'));
        $sisaNilai = (int) $paket->items->sum(fn ($i) => $i->sisa * $i->nilai_per_sesi);
        $persen = (int) $this->pengaturan->get('paket.potongan_refund_persen');
        $potongan = (int) round($sisaNilai * $persen / 100);

        return [
            'diizinkan' => (bool) $this->pengaturan->get('paket.refund_sisa'),
            'sisa_nilai' => $sisaNilai,
            'potongan_persen' => $persen,
            'potongan' => $potongan,
            'nominal' => $sisaNilai - $potongan,
        ];
    }

    private function lepasRingkasan(PaketPasien $paket): void
    {
        foreach (self::RINGKASAN as $atribut) {
            unset($paket->{$atribut});
        }
    }

    /** Paket aktif, belum kedaluwarsa, masih bersisa, dan tidak sedang dipakai kunjungan terbuka. */
    private function pastikanSisaBisaDiproses(PaketPasien $paket): void
    {
        $pesan = match (true) {
            $paket->status !== StatusPaketPasien::Aktif => "Paket {$paket->no_paket} sudah {$paket->status->value}.",
            $paket->status_efektif === StatusPaketPasien::KEDALUWARSA => "Paket {$paket->no_paket} sudah kedaluwarsa; perpanjang dulu bila kebijakan mengizinkan.",
            $paket->sisa_sesi === 0 && $paket->items->sum('dipesan') === 0 => "Sesi paket {$paket->no_paket} sudah habis.",
            $paket->items->sum('dipesan') > 0 => "Paket {$paket->no_paket} sedang dipakai di kunjungan yang belum ditutup.",
            default => null,
        };

        if ($pesan) {
            throw ValidationException::withMessages(['status' => $pesan]);
        }
    }

    /** Bagi nilai bersih paket ke tiap treatment sebanding tarif dasar × sesi (sama rata bila semua tarif 0). */
    private function alokasikanNilai(PaketPasien $paket, int $nilai): void
    {
        $bobot = $paket->items->mapWithKeys(fn ($i) => [$i->id => max(0, (int) $i->tindakan?->tarif) * $i->jumlah_sesi]);
        $totalBobot = $bobot->sum();

        foreach ($paket->items as $item) {
            $porsi = $totalBobot > 0 ? $nilai * $bobot[$item->id] / $totalBobot : $nilai * $item->jumlah_sesi / max(1, $paket->items->sum('jumlah_sesi'));
            $item->update(['nilai_per_sesi' => (int) floor($porsi / max(1, $item->jumlah_sesi))]);
        }
    }

    /**
     * Paket aktif pasien beserta sisa, untuk pilihan "pakai paket" di pemeriksaan.
     *
     * @return Collection<int, PaketPasien>
     */
    public function aktif(Pasien $pasien): Collection
    {
        return $pasien->paketPasiens()->where('status', StatusPaketPasien::Aktif)->with(self::RELASI)->latest('id')->get()
            ->map(fn (PaketPasien $p) => $this->ringkas($p))
            ->filter(fn (PaketPasien $p) => $p->status_efektif === StatusPaketPasien::Aktif->value)
            ->values();
    }
}
