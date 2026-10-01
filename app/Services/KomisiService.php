<?php

namespace App\Services;

use App\Enums\PeranKomisi;
use App\Enums\StatusKomisiPeriode;
use App\Enums\StatusTagihan;
use App\Enums\SumberKomisi;
use App\Models\KomisiBaris;
use App\Models\KomisiPeriode;
use App\Models\Tagihan;
use App\Models\TindakanKomisi;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Komisi & jasa medis (PRD KM-01, KM-03).
 *
 * Rekap periode = tagihan kunjungan **lunas** di cabang periode dengan `dibayar_at` dalam rentang. Komisi diatur per treatment per
 * peran di master treatment (`tindakan_komisis`). Per tagihan:
 * - item konsultasi (treatment jasa konsultasi poli) → peran dokter (dokter kunjungan), komisi dokter treatment itu;
 * - tiap tindakan → peran dokter (dokter kunjungan), terapis (pelaksana `petugas_id`), asisten (`asisten_id`), komisi peran itu pada
 *   treatment-nya. Peran tanpa komisi di treatment = tidak ada baris.
 * Dasar: pengaturan `komisi.dasar` — `bruto` (harga × jumlah) atau `neto` (dikali proporsi diskon manual + promo tagihan). Sesi paket
 * (ditagih Rp 0) memakai nilai per sesi paket (sudah bersih) di kedua mode. Persen = dasar × persen; nominal = nilai × jumlah tindakan.
 */
class KomisiService
{
    public function __construct(
        private PengaturanService $pengaturan,
        private AuditService $audit,
    ) {}

    /** @param  array{cabang_id: int, nama: string, mulai: string, selesai: string, catatan?: ?string}  $data */
    public function buatPeriode(array $data, User $user): KomisiPeriode
    {
        $this->pastikanTidakTumpangTindih((int) $data['cabang_id'], $data['mulai'], $data['selesai']);

        return KomisiPeriode::create([...$data, 'status' => StatusKomisiPeriode::Draf, 'created_by' => $user->id]);
    }

    /** Hitung (ulang) baris komisi periode draf dari data sumber; penyesuaian manual dipertahankan. */
    public function hitung(KomisiPeriode $periode, User $user): KomisiPeriode
    {
        $this->pastikanDraf($periode);

        return DB::transaction(function () use ($periode, $user) {
            $dasar = (string) $this->pengaturan->get('komisi.dasar');
            $komisi = TindakanKomisi::get()->keyBy(fn (TindakanKomisi $k) => "{$k->tindakan_id}:{$k->peran->value}");
            $baris = [];

            foreach ($this->tagihanPeriode($periode) as $tagihan) {
                array_push($baris, ...$this->barisTagihan($tagihan, $komisi, $dasar));
            }

            // Baris otomatis diganti seluruhnya (draf); massal agar cepat — periode yang diaudit, bukan tiap baris.
            KomisiBaris::where('komisi_periode_id', $periode->id)->where('sumber', '!=', SumberKomisi::Penyesuaian->value)->delete();
            $waktu = now();
            foreach (array_chunk($baris, 500) as $potong) {
                KomisiBaris::insert(array_map(fn ($b) => [...$b, 'komisi_periode_id' => $periode->id, 'created_at' => $waktu, 'updated_at' => $waktu], $potong));
            }

            $periode->update([
                'dasar' => $dasar,
                'total' => (int) $periode->barises()->sum('komisi'),
                'dihitung_at' => now(),
                'dihitung_oleh' => $user->id,
            ]);
            $this->audit->catat('hitung_komisi', 'komisi_periode', $periode->id, [
                'label' => "{$periode->nama}: ".count($baris).' baris, total Rp '.number_format($periode->total, 0, ',', '.'),
            ]);

            return $periode;
        });
    }

    /** Setujui & kunci (KM-03). Harus sudah dihitung. */
    public function setujui(KomisiPeriode $periode, User $user): KomisiPeriode
    {
        $this->pastikanDraf($periode);

        if (! $periode->dihitung_at) {
            throw ValidationException::withMessages(['status' => 'Hitung komisi periode ini dulu sebelum disetujui.']);
        }

        $periode->update(['status' => StatusKomisiPeriode::Disetujui, 'disetujui_at' => now(), 'disetujui_oleh' => $user->id]);

        return $periode;
    }

    /** Baris koreksi manual (bonus / potongan) pada periode draf; tercatat di audit log. */
    public function penyesuaian(KomisiPeriode $periode, int $userId, int $komisi, string $keterangan, User $oleh): KomisiBaris
    {
        $this->pastikanDraf($periode);

        return DB::transaction(function () use ($periode, $userId, $komisi, $keterangan, $oleh) {
            $baris = $periode->barises()->create([
                'user_id' => $userId,
                'peran' => PeranKomisi::Penyesuaian,
                'sumber' => SumberKomisi::Penyesuaian,
                'tanggal' => $periode->selesai,
                'deskripsi' => $keterangan,
                'dasar' => 0,
                'komisi' => $komisi,
                'dibuat_oleh' => $oleh->id,
            ]);
            $periode->update(['total' => (int) $periode->barises()->sum('komisi')]);
            $this->audit->catat('penyesuaian_komisi', 'komisi_periode', $periode->id, [
                'label' => "{$periode->nama}: ".User::withTrashed()->find($userId)?->name.' Rp '.number_format($komisi, 0, ',', '.')." — {$keterangan}",
            ]);

            return $baris;
        });
    }

    public function hapusPenyesuaian(KomisiPeriode $periode, KomisiBaris $baris): void
    {
        $this->pastikanDraf($periode);
        abort_unless($baris->komisi_periode_id === $periode->id && $baris->sumber === SumberKomisi::Penyesuaian, 404);

        DB::transaction(function () use ($periode, $baris) {
            $this->audit->catat('hapus_penyesuaian_komisi', 'komisi_periode', $periode->id, [
                'label' => "{$periode->nama}: {$baris->deskripsi} (Rp ".number_format($baris->komisi, 0, ',', '.').')',
            ]);
            $baris->delete();
            $periode->update(['total' => (int) $periode->barises()->sum('komisi')]);
        });
    }

    /**
     * Ringkasan per petugas: total, jumlah baris, dan subtotal per peran.
     *
     * @return Collection<int, array>
     */
    public function ringkasan(KomisiPeriode $periode): Collection
    {
        return $periode->barises()->with('user:id,name,role')->get()
            ->groupBy('user_id')
            ->map(fn (Collection $b) => [
                'user' => $b->first()->user?->only(['id', 'name', 'role']),
                'total' => (int) $b->sum('komisi'),
                'jumlah_baris' => $b->count(),
                'per_peran' => $b->groupBy(fn ($x) => $x->peran->value)->map(fn ($x) => (int) $x->sum('komisi')),
            ])
            ->sortByDesc('total')
            ->values();
    }

    /** @return Collection<int, Tagihan> */
    private function tagihanPeriode(KomisiPeriode $periode): Collection
    {
        return Tagihan::withoutGlobalScope('cabang')
            ->where('cabang_id', $periode->cabang_id)
            ->where('status', StatusTagihan::Lunas)
            ->whereNotNull('kunjungan_id')
            ->whereBetween('dibayar_at', [Carbon::parse($periode->mulai)->startOfDay(), Carbon::parse($periode->selesai)->endOfDay()])
            ->with([
                'items:id,tagihan_id,kategori,tindakan_id,deskripsi,jumlah,subtotal',
                'kunjungan:id,cabang_id,no_registrasi,pasien_id,poli_id,dokter_id',
                'kunjungan.pasien:id,nama',
                'kunjungan.tindakans:id,kunjungan_id,tindakan_id,jumlah,tarif,petugas_id,asisten_id,gigi,permukaan,paket_pasien_item_id',
                'kunjungan.tindakans.tindakan:id,nama,kategori_id',
                'kunjungan.tindakans.paketItem:id,nilai_per_sesi',
            ])
            ->orderBy('dibayar_at')
            ->get()
            // Satu kunjungan dihitung sekali (tagihan yang memuat konsultasi/tindakannya).
            ->filter(fn (Tagihan $t) => $t->items->contains(fn ($i) => in_array($i->kategori, ['konsultasi', 'tindakan'], true)))
            ->unique('kunjungan_id');
    }

    /**
     * @param  Collection<string, TindakanKomisi>  $komisi  kunci "tindakan_id:peran"
     * @return list<array> baris komisi (belum ber-periode) untuk satu tagihan
     */
    private function barisTagihan(Tagihan $tagihan, Collection $komisi, string $dasar): array
    {
        $kunjungan = $tagihan->kunjungan;
        $faktor = $dasar === 'neto' && $tagihan->total > 0
            ? ($tagihan->total - $tagihan->diskon - $tagihan->diskon_promo) / $tagihan->total
            : 1.0;
        $tanggal = $tagihan->dibayar_at->toDateString();
        $ket = "{$kunjungan->no_registrasi} · {$kunjungan->pasien?->nama}";
        $hasil = [];

        $tambah = function (?int $userId, PeranKomisi $peran, SumberKomisi $sumber, ?int $tindakanId, int $nilaiDasar, int $jumlah, string $deskripsi, ?int $ktId) use (&$hasil, $komisi, $tagihan, $kunjungan, $tanggal) {
            $k = $tindakanId ? $komisi->get("{$tindakanId}:{$peran->value}") : null;
            if (! $userId || ! $k) {
                return;
            }
            $nilai = $k->hitung($nilaiDasar, $jumlah);
            if ($nilai === 0) {
                return;
            }
            $hasil[] = [
                'user_id' => $userId, 'peran' => $peran->value, 'sumber' => $sumber->value, 'kunjungan_id' => $kunjungan->id,
                'kunjungan_tindakan_id' => $ktId, 'tagihan_id' => $tagihan->id, 'tindakan_id' => $tindakanId, 'tanggal' => $tanggal,
                'deskripsi' => mb_substr($deskripsi, 0, 255), 'dasar' => $nilaiDasar, 'jenis' => $k->jenis->value, 'nilai' => $k->nilai,
                'komisi' => $nilai, 'dibuat_oleh' => null,
            ];
        };

        // Jasa konsultasi dokter: item konsultasi menunjuk treatment jasa konsultasi poli.
        foreach ($tagihan->items->where('kategori', 'konsultasi') as $item) {
            $tambah($kunjungan->dokter_id, PeranKomisi::Dokter, SumberKomisi::Konsultasi, $item->tindakan_id, (int) round($item->subtotal * $faktor),
                $item->jumlah, "{$item->deskripsi} — {$ket}", null);
        }

        foreach ($kunjungan->tindakans as $kt) {
            $nilai = $kt->paketItem
                ? $kt->paketItem->nilai_per_sesi * $kt->jumlah
                : (int) round($kt->tarif * $kt->jumlah * $faktor);
            $nama = $kt->tindakan?->nama.($kt->gigi ? " gigi {$kt->gigi}".($kt->permukaan ? " ({$kt->permukaan})" : '') : '')
                .($kt->paketItem ? ' · sesi paket' : '').($kt->jumlah > 1 ? " ×{$kt->jumlah}" : '');

            foreach ([
                [PeranKomisi::Dokter, $kunjungan->dokter_id],
                [PeranKomisi::Terapis, $kt->petugas_id],
                [PeranKomisi::Asisten, $kt->asisten_id],
            ] as [$peran, $userId]) {
                $tambah($userId, $peran, SumberKomisi::Tindakan, $kt->tindakan_id, $nilai, $kt->jumlah, "{$nama} — {$ket}", $kt->id);
            }
        }

        return $hasil;
    }

    private function pastikanDraf(KomisiPeriode $periode): void
    {
        if ($periode->terkunci()) {
            throw ValidationException::withMessages(['status' => 'Rekap komisi ini sudah disetujui dan terkunci.']);
        }
    }

    private function pastikanTidakTumpangTindih(int $cabangId, string $mulai, string $selesai, ?int $kecuali = null): void
    {
        $bentrok = KomisiPeriode::withoutGlobalScope('cabang')->where('cabang_id', $cabangId)
            ->whereDate('mulai', '<=', $selesai)->whereDate('selesai', '>=', $mulai)
            ->when($kecuali, fn ($q) => $q->whereKeyNot($kecuali))
            ->first();

        if ($bentrok) {
            throw ValidationException::withMessages(['mulai' => "Rentang tanggal tumpang tindih dengan periode \"{$bentrok->nama}\" di cabang ini."]);
        }
    }
}
