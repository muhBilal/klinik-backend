<?php

namespace App\Services;

use App\Enums\KondisiGigi;
use App\Enums\StatusKunjungan;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\OdontogramKondisi;
use App\Models\Pasien;
use App\Models\User;
use App\Support\Gigi;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Odontogram pasien (PRD DG-01) dan pembaruan otomatis dari tindakan per gigi (DG-07).
 *
 * Aturan penggantian saat kondisi dicatat di kunjungan K (lihat KondisiGigi):
 * - satu permukaan hanya satu kondisi; kondisi seluruh gigi dalam kelompok eksklusif saling menggantikan; `mengakhiri()`
 *   mengakhiri kelompok lain (mis. gigi hilang mengakhiri tambalan & mahkota);
 * - kondisi dari kunjungan sebelumnya **diakhiri** di K (`berakhir_karena_id` = kondisi baru); kondisi manual yang dicatat di K
 *   sendiri pada tempat yang sama dianggap koreksi dan **dihapus** (kondisi yang digantikannya dipulihkan); menghapus kondisi
 *   baru memulihkan semua yang diakhirinya;
 * - hasil tindakan (turunan) tidak bisa ditimpa kondisi manual di kunjungan yang sama — ubah tindakannya.
 */
class OdontogramService
{
    public function __construct(
        private RekamMedisService $rekamMedis,
        private AuditService $audit,
    ) {}

    /**
     * Status odontogram pasien. Dengan `$kunjungan`: status pada kunjungan itu + perubahan yang dicatat di sana.
     * Kondisi dari kunjungan berakses terbatas yang tidak boleh dibaca user tidak disertakan.
     */
    public function data(Pasien $pasien, User $user, ?Kunjungan $kunjungan = null): array
    {
        $tersembunyi = $this->rekamMedis->kunjunganTersembunyi($pasien->id, $user)->all();
        $relasi = ['kunjungan:id,tanggal,no_registrasi', 'pencatat:id,name', 'kunjunganTindakan:id,tindakan_id', 'kunjunganTindakan.tindakan:id,nama'];

        $query = fn () => OdontogramKondisi::where('pasien_id', $pasien->id)->whereNotIn('kunjungan_id', $tersembunyi)->with($relasi);

        $kondisis = $kunjungan
            ? $query()->berlakuPada($kunjungan->id)->orderBy('gigi')->orderBy('id')->get()
            : $query()->aktif()->orderBy('gigi')->orderBy('id')->get();

        $perubahan = $kunjungan ? [
            'dicatat' => $query()->where('kunjungan_id', $kunjungan->id)->orderBy('id')->get(),
            'diakhiri' => $query()->where('berakhir_kunjungan_id', $kunjungan->id)->with('pengakhir:id,name')->orderBy('id')->get(),
        ] : null;

        $kunjunganIds = OdontogramKondisi::where('pasien_id', $pasien->id)->pluck('kunjungan_id')
            ->merge(OdontogramKondisi::where('pasien_id', $pasien->id)->whereNotNull('berakhir_kunjungan_id')->pluck('berakhir_kunjungan_id'))
            ->unique()->diff($tersembunyi);

        $kunjungans = Kunjungan::withoutGlobalScope('cabang')
            ->whereIn('id', $kunjunganIds)
            ->select(['id', 'cabang_id', 'poli_id', 'dokter_id', 'tanggal', 'no_registrasi', 'status'])
            ->with(['poli:id,nama', 'dokter:id,name', 'cabang:id,nama'])
            ->orderByDesc('id')
            ->get();

        return [
            'kondisis' => $kondisis,
            'perubahan' => $perubahan,
            'kunjungan_id' => $kunjungan?->id,
            'bisa_diubah' => $kunjungan ? $kunjungan->status === StatusKunjungan::Diperiksa : false,
            'kunjungans' => $kunjungans,
        ];
    }

    public function catatAkses(Pasien $pasien, ?Kunjungan $kunjungan): void
    {
        $this->audit->catat('lihat', 'odontogram', $pasien->id, [
            'pasien_id' => $pasien->id,
            'label' => 'Odontogram '.$pasien->no_rm.($kunjungan ? " pada {$kunjungan->no_registrasi}" : ''),
        ]);
    }

    /**
     * Catat kondisi di kunjungan yang sedang diperiksa. Kondisi per permukaan menghasilkan satu baris per permukaan.
     * `$sumber` = tindakan kunjungan asal (hasil otomatis tindakan per gigi).
     *
     * @param  array{gigi: int, permukaan?: string|array|null, kondisi: string, keterangan?: ?string}  $data
     * @return Collection<int, OdontogramKondisi> baris yang dicatat
     */
    public function tetapkan(Kunjungan $kunjungan, array $data, User $user, ?KunjunganTindakan $sumber = null): Collection
    {
        $this->pastikanBisaDiubah($kunjungan);

        $kondisi = $data['kondisi'] instanceof KondisiGigi ? $data['kondisi'] : KondisiGigi::from($data['kondisi']);
        $gigi = (int) $data['gigi'];
        $permukaan = Gigi::normalPermukaan($data['permukaan'] ?? null);

        if (! Gigi::valid($gigi)) {
            throw ValidationException::withMessages(['gigi' => "Nomor gigi {$gigi} tidak valid (notasi FDI 11–48 atau 51–85)."]);
        }
        if ($kondisi->cakupan() === 'permukaan' && ! $permukaan) {
            throw ValidationException::withMessages(['permukaan' => "Pilih permukaan gigi {$gigi} untuk {$kondisi->label()}."]);
        }
        if ($kondisi->cakupan() === 'gigi' && $permukaan) {
            throw ValidationException::withMessages(['permukaan' => "{$kondisi->label()} dicatat untuk seluruh gigi, bukan per permukaan."]);
        }

        $aktif = OdontogramKondisi::where('pasien_id', $kunjungan->pasien_id)->where('gigi', $gigi)->aktif()->get();

        if (! in_array($kondisi->kelompok(), KondisiGigi::bolehSaatHilang(), true)
            && $aktif->contains(fn (OdontogramKondisi $k) => $k->kondisi === KondisiGigi::Hilang)) {
            throw ValidationException::withMessages([
                'gigi' => "Gigi {$gigi} tercatat hilang. Akhiri status gigi hilang dulu bila pencatatannya keliru.",
            ]);
        }

        return DB::transaction(function () use ($kunjungan, $kondisi, $gigi, $permukaan, $data, $user, $sumber) {
            $dicatat = collect();

            foreach ($permukaan ? str_split($permukaan) : [null] as $p) {
                $konflik = $this->konflik($kunjungan->pasien_id, $gigi, $p, $kondisi);

                if (! $sumber) {
                    // Kondisi yang sama persis sudah tercatat → tidak dicatat ulang.
                    if ($konflik->contains(fn ($k) => $k->kondisi === $kondisi && $k->permukaan === $p)) {
                        continue;
                    }
                    $turunan = $konflik->first(fn ($k) => $k->turunanTindakan() && $k->kunjungan_id === $kunjungan->id);
                    if ($turunan) {
                        throw ValidationException::withMessages([
                            'kondisi' => "{$turunan->deskripsi()} adalah hasil tindakan di kunjungan ini; ubah tindakannya bila keliru.",
                        ]);
                    }
                }

                $baru = OdontogramKondisi::create([
                    'pasien_id' => $kunjungan->pasien_id,
                    'cabang_id' => $kunjungan->cabang_id,
                    'kunjungan_id' => $kunjungan->id,
                    'kunjungan_tindakan_id' => $sumber?->id,
                    'gigi' => $gigi,
                    'permukaan' => $p,
                    'kondisi' => $kondisi,
                    'keterangan' => $data['keterangan'] ?? null,
                    'dicatat_oleh' => $user->id,
                ]);

                // Dua putaran: menghapus koreksi di kunjungan ini memulihkan kondisi lama yang juga harus diakhiri.
                for ($putaran = 0; $putaran < 3 && $konflik->isNotEmpty(); $putaran++) {
                    foreach ($konflik as $lama) {
                        if ($lama->kunjungan_id === $kunjungan->id && ! $lama->turunanTindakan() && $this->tempatSama($lama, $p, $kondisi)) {
                            $this->hapusBaris($lama);
                        } else {
                            $this->akhiriBaris($lama, $kunjungan, $user, $baru->id);
                        }
                    }
                    $konflik = $this->konflik($kunjungan->pasien_id, $gigi, $p, $kondisi)->where('id', '!=', $baru->id);
                }

                $dicatat->push($baru);
            }

            return $dicatat;
        });
    }

    /** Hapus kondisi yang dicatat (koreksi) di kunjungan ini; kondisi yang digantikannya berlaku lagi. */
    public function hapus(Kunjungan $kunjungan, OdontogramKondisi $kondisi): void
    {
        $this->pastikanBisaDiubah($kunjungan);

        if ($kondisi->kunjungan_id !== $kunjungan->id) {
            throw ValidationException::withMessages(['kondisi' => 'Kondisi dari kunjungan sebelumnya tidak dihapus — akhiri saja bila sudah tidak berlaku.']);
        }
        if ($kondisi->turunanTindakan()) {
            $nama = $kondisi->kunjunganTindakan?->tindakan?->nama ?? 'tindakan';
            throw ValidationException::withMessages(['kondisi' => "Kondisi ini hasil tindakan {$nama}; ubah atau hapus tindakannya."]);
        }

        DB::transaction(fn () => $this->hapusBaris($kondisi));
    }

    /** Kondisi dari kunjungan sebelumnya tidak berlaku lagi (mis. tambalan lepas, pencatatan lama keliru). */
    public function akhiri(Kunjungan $kunjungan, OdontogramKondisi $kondisi, User $user): void
    {
        $this->pastikanBisaDiubah($kunjungan);
        $this->pastikanMilikPasien($kunjungan, $kondisi);

        if ($kondisi->berakhir_kunjungan_id !== null) {
            throw ValidationException::withMessages(['kondisi' => 'Kondisi ini sudah tidak berlaku.']);
        }
        if ($kondisi->kunjungan_id === $kunjungan->id) {
            throw ValidationException::withMessages(['kondisi' => 'Kondisi yang dicatat di kunjungan ini dihapus saja, bukan diakhiri.']);
        }

        $this->akhiriBaris($kondisi, $kunjungan, $user, null);
    }

    /** Batalkan pengakhiran manual yang dilakukan di kunjungan ini. */
    public function pulihkan(Kunjungan $kunjungan, OdontogramKondisi $kondisi): void
    {
        $this->pastikanBisaDiubah($kunjungan);
        $this->pastikanMilikPasien($kunjungan, $kondisi);

        if ($kondisi->berakhir_kunjungan_id !== $kunjungan->id) {
            throw ValidationException::withMessages(['kondisi' => 'Hanya kondisi yang diakhiri di kunjungan ini yang bisa dipulihkan.']);
        }
        if ($kondisi->berakhir_karena_id !== null) {
            throw ValidationException::withMessages(['kondisi' => 'Kondisi ini digantikan kondisi baru; hapus kondisi pengganti untuk memulihkannya.']);
        }

        $bentrok = $this->konflik($kondisi->pasien_id, $kondisi->gigi, $kondisi->permukaan, $kondisi->kondisi)->first();
        if ($bentrok) {
            throw ValidationException::withMessages(['kondisi' => "Bertentangan dengan kondisi yang berlaku: {$bentrok->deskripsi()}."]);
        }

        $kondisi->update(['berakhir_kunjungan_id' => null, 'berakhir_at' => null, 'berakhir_oleh' => null, 'berakhir_karena_id' => null]);
    }

    /**
     * Samakan kondisi turunan dengan tindakan per gigi kunjungan (DG-07): tindakan dengan nomor gigi dan `kondisi_gigi_hasil`
     * mencatat kondisi itu; tindakan yang berubah gigi/permukaan dicatat ulang. Hanya selama pasien diperiksa.
     *
     * @param  array<int, int>  $indeks  id tindakan kunjungan => index payload (untuk kunci pesan validasi)
     */
    public function sinkronDariTindakan(Kunjungan $kunjungan, User $user, array $indeks = []): void
    {
        if ($kunjungan->status !== StatusKunjungan::Diperiksa) {
            return;
        }

        $tindakans = $kunjungan->tindakans()->with(['tindakan:id,nama,kondisi_gigi_hasil', 'kondisiGigi'])->orderBy('id')->get();

        foreach ($tindakans as $kt) {
            $kondisi = $kt->gigi ? $kt->tindakan?->kondisi_gigi_hasil : null;
            $tempat = $kondisi?->cakupan() === 'permukaan' ? str_split((string) $kt->permukaan) : [null];
            $target = $kondisi ? collect($tempat)->map(fn ($p) => "{$kt->gigi}|{$p}|{$kondisi->value}")->sort()->values()->all() : [];
            $ada = $kt->kondisiGigi->map(fn ($r) => "{$r->gigi}|{$r->permukaan}|{$r->kondisi->value}")->sort()->values()->all();

            if ($ada === $target) {
                continue;
            }

            $kt->kondisiGigi->sortByDesc('id')->each(fn (OdontogramKondisi $r) => $this->hapusBaris($r));

            if (! $kondisi) {
                continue;
            }

            try {
                $this->tetapkan($kunjungan, ['gigi' => $kt->gigi, 'permukaan' => $kt->permukaan, 'kondisi' => $kondisi], $user, $kt);
            } catch (ValidationException $e) {
                $i = $indeks[$kt->id] ?? null;
                $pesan = collect($e->errors())->map(fn ($m) => "{$kt->tindakan->nama}: {$m[0]}");
                throw ValidationException::withMessages($pesan->mapWithKeys(fn ($m, $kunci) => [
                    $i === null ? 'tindakans' : "tindakans.{$i}.".($kunci === 'permukaan' ? 'permukaan' : 'gigi') => $m,
                ])->all());
            }
        }
    }

    /** Tindakan dihapus dari pemeriksaan: kondisi turunannya ikut dihapus (kondisi lama berlaku lagi). */
    public function hapusTurunan(KunjunganTindakan $kt): void
    {
        $kt->kondisiGigi()->orderByDesc('id')->get()->each(fn (OdontogramKondisi $r) => $this->hapusBaris($r));
    }

    /**
     * Kondisi aktif pada gigi yang harus diganti bila `$kondisi` dicatat di permukaan `$permukaan` (null = seluruh gigi).
     *
     * @return Collection<int, OdontogramKondisi>
     */
    private function konflik(int $pasienId, int $gigi, ?string $permukaan, KondisiGigi $kondisi): Collection
    {
        $kelompokDiakhiri = $kondisi->mengakhiri();

        return OdontogramKondisi::where('pasien_id', $pasienId)->where('gigi', $gigi)->aktif()->orderBy('id')->get()
            ->filter(function (OdontogramKondisi $k) use ($permukaan, $kondisi, $kelompokDiakhiri) {
                if ($k->permukaan !== null) {
                    return $k->permukaan === $permukaan || in_array(KondisiGigi::PERMUKAAN, $kelompokDiakhiri, true);
                }
                if ($permukaan !== null) {
                    return false;
                }

                return $k->kondisi === $kondisi
                    || ($kondisi->eksklusif() && $k->kondisi->kelompok() === $kondisi->kelompok())
                    || in_array($k->kondisi->kelompok(), $kelompokDiakhiri, true);
            })
            ->values();
    }

    /**
     * Kondisi lama menempati "tempat" yang sama dengan kondisi baru (permukaan yang sama, atau kelompok eksklusif yang sama)
     * → penggantian di kunjungan yang sama dianggap koreksi (dihapus). Yang tergeser lewat `mengakhiri()` (mis. tambalan
     * saat gigi dicatat hilang) diakhiri saja agar kembali bila kondisi baru dihapus.
     */
    private function tempatSama(OdontogramKondisi $lama, ?string $permukaan, KondisiGigi $kondisi): bool
    {
        if ($lama->permukaan !== null || $permukaan !== null) {
            return $lama->permukaan === $permukaan;
        }

        return $lama->kondisi === $kondisi || ($kondisi->eksklusif() && $lama->kondisi->kelompok() === $kondisi->kelompok());
    }

    private function akhiriBaris(OdontogramKondisi $kondisi, Kunjungan $kunjungan, User $user, ?int $karena): void
    {
        $kondisi->update([
            'berakhir_kunjungan_id' => $kunjungan->id,
            'berakhir_at' => now(),
            'berakhir_oleh' => $user->id,
            'berakhir_karena_id' => $karena,
        ]);
    }

    /** Hapus per model (tercatat audit) dan pulihkan kondisi yang digantikannya. */
    private function hapusBaris(OdontogramKondisi $kondisi): void
    {
        OdontogramKondisi::where('berakhir_karena_id', $kondisi->id)->get()->each->update([
            'berakhir_kunjungan_id' => null, 'berakhir_at' => null, 'berakhir_oleh' => null, 'berakhir_karena_id' => null,
        ]);
        $kondisi->delete();
    }

    private function pastikanBisaDiubah(Kunjungan $kunjungan): void
    {
        if ($kunjungan->status !== StatusKunjungan::Diperiksa) {
            throw ValidationException::withMessages([
                'status' => 'Odontogram hanya bisa diubah saat pasien sedang diperiksa (sudah dipanggil, pemeriksaan belum ditutup).',
            ]);
        }
    }

    private function pastikanMilikPasien(Kunjungan $kunjungan, OdontogramKondisi $kondisi): void
    {
        abort_unless($kondisi->pasien_id === (int) $kunjungan->pasien_id, 404);
    }
}
