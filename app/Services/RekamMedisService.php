<?php

namespace App\Services;

use App\Enums\Izin;
use App\Models\Kunjungan;
use App\Models\Pemeriksaan;
use App\Models\PemeriksaanAddendum;
use App\Models\User;
use App\Support\CabangAktif;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aturan rekam medis lintas modul: akses terbatas (PRD DR-03), tanda tangan elektronik & keutuhan isi,
 * serta addendum setelah RME dikunci (RM-07).
 */
class RekamMedisService
{
    public function __construct(private CabangAktif $cabang) {}

    /**
     * Boleh membaca isi rekam medis kunjungan ini (di luar izin rme.lihat yang dicek terpisah).
     * Kunjungan berakses terbatas hanya untuk: pemegang rme.terbatas, tim yang tercatat menangani (dokter, perawat,
     * petugas tindakan), dan tenaga pelayanan di cabang itu selama pemeriksaan masih berjalan.
     */
    public function bolehLihat(User $user, Kunjungan $kunjungan): bool
    {
        if (! $kunjungan->akses_terbatas || $user->punyaIzin(Izin::RmeTerbatas)) {
            return true;
        }

        // Relasi tidak di-load ke model agar bentuk respons pemanggil tidak berubah.
        $pemeriksaan = $kunjungan->relationLoaded('pemeriksaan')
            ? $kunjungan->pemeriksaan
            : $kunjungan->pemeriksaan()->first(['id', 'kunjungan_id', 'dokter_id', 'perawat_id']);
        $petugas = $kunjungan->relationLoaded('tindakans')
            ? $kunjungan->tindakans->pluck('petugas_id')
            : $kunjungan->tindakans()->pluck('petugas_id');

        $tim = array_map('intval', array_filter([
            $kunjungan->dokter_id, $pemeriksaan?->dokter_id, $pemeriksaan?->perawat_id, ...$petugas->all(),
        ]));

        if (in_array($user->id, $tim, true)) {
            return true;
        }

        return $kunjungan->terbuka()
            && (int) $kunjungan->cabang_id === (int) $this->cabang->id()
            && $user->punyaIzin(Izin::PemeriksaanVital, Izin::PemeriksaanDokter);
    }

    /**
     * Lepas isi rekam medis kunjungan berakses terbatas yang tidak boleh dibaca user ini; tandai `rme_disembunyikan`.
     *
     * @param  iterable<Kunjungan>  $kunjungans
     */
    public function sembunyikanTerbatas(iterable $kunjungans, User $user): void
    {
        foreach ($kunjungans as $kunjungan) {
            $boleh = $this->bolehLihat($user, $kunjungan);

            if (! $boleh) {
                foreach (Kunjungan::RELASI_RME as $relasi) {
                    $kunjungan->unsetRelation($relasi);
                }
            }

            $kunjungan->setAttribute('rme_disembunyikan', ! $boleh);
        }
    }

    /** Id kunjungan berakses terbatas milik pasien yang tidak boleh dibaca user (untuk menyaring berkas). */
    public function kunjunganTersembunyi(int $pasienId, User $user): Collection
    {
        if ($user->punyaIzin(Izin::RmeTerbatas)) {
            return collect();
        }

        return Kunjungan::withoutGlobalScope('cabang')
            ->where('pasien_id', $pasienId)
            ->where('akses_terbatas', true)
            ->get()
            ->reject(fn (Kunjungan $k) => $this->bolehLihat($user, $k))
            ->pluck('id');
    }

    /**
     * Tanda tangan elektronik dokter saat pemeriksaan ditutup (RM-07, UU 17/2023: hanya dokter ber-SIP aktif).
     * Menyimpan hash isi klinis sehingga perubahan di luar aplikasi bisa dideteksi (`verifikasi`).
     */
    public function tandaTangani(Kunjungan $kunjungan, User $user): Pemeriksaan
    {
        $this->pastikanBolehMenandatangani($user);

        $pemeriksaan = $kunjungan->pemeriksaan()->firstOrFail();

        $pemeriksaan->update([
            'ditandatangani_at' => now(),
            'ditandatangani_oleh' => $user->id,
            'hash_ttd' => $this->hash($kunjungan),
        ]);

        return $pemeriksaan;
    }

    public function pastikanBolehMenandatangani(User $user): void
    {
        if (! $user->sipAktif()) {
            throw ValidationException::withMessages([
                'sip' => $user->sip
                    ? 'SIP Anda sudah kedaluwarsa. Perbarui masa berlaku SIP sebelum menandatangani rekam medis.'
                    : 'Hanya dokter dengan SIP aktif yang dapat menandatangani rekam medis. Lengkapi No. SIP di data pengguna.',
            ]);
        }
    }

    /** Hash SHA-256 isi klinis kunjungan. Dihitung dari data tersimpan agar sama saat tanda tangan & verifikasi. */
    public function hash(Kunjungan $kunjungan): string
    {
        $kunjungan = Kunjungan::withoutGlobalScope('cabang')->with([
            'pemeriksaan.diagnosas.icd10:id,kode',
            'tindakans.catatan.titiks',
            'informedConsents:id,kunjungan_id,uuid,status,checksum',
        ])->findOrFail($kunjungan->id);

        $p = $kunjungan->pemeriksaan;

        $isi = [
            'kunjungan' => [$kunjungan->id, $kunjungan->pasien_id, $kunjungan->tanggal?->toDateString()],
            'pemeriksaan' => $p?->only([...Pemeriksaan::VITAL_FIELDS, ...Pemeriksaan::SOAP_FIELDS]),
            'diagnosa' => $p?->diagnosas->sortBy('id')->map(fn ($d) => [$d->icd10?->kode, $d->jenis])->values()->all(),
            'tindakan' => $kunjungan->tindakans->sortBy('id')->map(fn ($t) => [
                $t->tindakan_id, $t->jumlah, $t->icd9cm_id, $t->petugas_id, $t->keterangan,
                $t->catatan ? [
                    $t->catatan->jenis?->value, $t->catatan->area, $t->catatan->catatan,
                    $this->urutkan($t->catatan->parameter ?? []), $t->catatan->sumber_daya_id,
                    $t->catatan->titiks->map(fn ($x) => [
                        $x->tampilan, round($x->x, 4), round($x->y, 4), $x->area, $x->obat_id, $x->batch_id,
                        $x->jumlah === null ? null : round($x->jumlah, 3), $x->satuan, $x->kedalaman, $x->alat, $x->catatan,
                    ])->all(),
                ] : null,
            ])->values()->all(),
            'consent' => $kunjungan->informedConsents->map(fn ($c) => [$c->uuid, $c->status->value, $c->checksum])->all(),
        ];

        return hash('sha256', json_encode($isi, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    }

    /** @return array{ditandatangani: bool, valid: bool|null, ditandatangani_at: ?string, penandatangan: ?array} */
    public function verifikasi(Kunjungan $kunjungan): array
    {
        $pemeriksaan = $kunjungan->pemeriksaan()->with('penandatangan:id,name,sip')->first();

        if (! $pemeriksaan?->ditandatangani()) {
            return ['ditandatangani' => false, 'valid' => null, 'ditandatangani_at' => null, 'penandatangan' => null];
        }

        return [
            'ditandatangani' => true,
            'valid' => hash_equals((string) $pemeriksaan->hash_ttd, $this->hash($kunjungan)),
            'ditandatangani_at' => $pemeriksaan->ditandatangani_at->toIso8601String(),
            'penandatangan' => $pemeriksaan->penandatangan?->only(['id', 'name', 'sip']),
        ];
    }

    /**
     * Koreksi RME yang sudah ditandatangani (RM-07). Penulis harus dokter ber-SIP aktif.
     *
     * @param  array{bagian: string, isi: string, alasan: string}  $data
     */
    public function tambahAddendum(Kunjungan $kunjungan, array $data, User $user): PemeriksaanAddendum
    {
        $pemeriksaan = $kunjungan->pemeriksaan;

        if (! $pemeriksaan?->ditandatangani()) {
            throw ValidationException::withMessages([
                'addendum' => 'Rekam medis belum ditandatangani; ubah langsung di form pemeriksaan.',
            ]);
        }

        $this->pastikanBolehMenandatangani($user);

        return DB::transaction(fn () => $pemeriksaan->addendums()->create([
            ...$data,
            'user_id' => $user->id,
            'created_at' => now(),
        ])->load('user:id,name'));
    }

    /** Urutkan kunci array bertingkat agar hash tidak bergantung urutan penyimpanan JSON. */
    private function urutkan(array $data): array
    {
        ksort($data);

        return array_map(fn ($v) => is_array($v) ? $this->urutkan($v) : $v, $data);
    }
}
