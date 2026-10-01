<?php

namespace App\Services\SatuSehat;

use App\Jobs\KirimSatuSehat;
use App\Models\Kunjungan;
use App\Models\Pasien;
use App\Models\SatuSehatKirim;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Alur SATUSEHAT (PRD v2 SS-01..05, PS-05): lookup IHS pasien & praktisi via NIK, antrean kirim kunjungan setelah RME ditandatangani,
 * kirim Bundle transaksi, catat hasil/galat untuk dipantau & dikirim ulang.
 */
class SatuSehatService
{
    private const NIK = 'https://fhir.kemkes.go.id/id/nik';

    public function __construct(private SatuSehatClient $client, private FhirMapper $mapper) {}

    public function aktif(): bool
    {
        return (bool) config('services.satusehat.aktif');
    }

    /** Masukkan kunjungan ke antrean (dipanggil setelah RME ditandatangani). Tidak melakukan apa pun bila integrasi nonaktif. */
    public function antrekan(Kunjungan $kunjungan): ?SatuSehatKirim
    {
        if (! $this->aktif()) {
            return null;
        }

        $kirim = SatuSehatKirim::withoutGlobalScope('cabang')->firstOrCreate(
            ['kunjungan_id' => $kunjungan->id],
            ['cabang_id' => $kunjungan->cabang_id, 'status' => SatuSehatKirim::MENUNGGU],
        );

        if ($kirim->status !== SatuSehatKirim::TERKIRIM) {
            $kirim->update(['status' => SatuSehatKirim::MENUNGGU, 'error' => null]);
            DB::afterCommit(fn () => KirimSatuSehat::dispatch($kirim->id));
        }

        return $kirim;
    }

    /**
     * Kirim satu antrean. Galat data (pasien tanpa NIK, Location belum diatur, 4xx) → `gagal` & tidak dicoba ulang otomatis;
     * galat sementara dilempar ulang agar job mencoba lagi dengan jeda.
     */
    public function kirim(SatuSehatKirim $kirim): SatuSehatKirim
    {
        $kirim->update(['percobaan' => $kirim->percobaan + 1, 'terakhir_dicoba_at' => now()]);
        $kunjungan = Kunjungan::withoutGlobalScope('cabang')->with(['pasien', 'dokter', 'cabang'])->findOrFail($kirim->kunjungan_id);

        try {
            if (! $kunjungan->pemeriksaan?->ditandatangani_at) {
                throw new SatuSehatException('Rekam medis kunjungan belum ditandatangani.');
            }

            $ids = [
                'organization' => $this->client->organizationId(),
                'patient' => $this->ihsPasien($kunjungan->pasien),
                'practitioner' => $this->ihsPraktisi($kunjungan->dokter),
                'location' => $kunjungan->cabang?->satusehat_location_id
                    ?: throw new SatuSehatException("Location SATUSEHAT cabang {$kunjungan->cabang?->nama} belum diisi (Master Cabang)."),
            ];

            $respons = $this->client->post('/', $this->mapper->bundle($kunjungan, $ids));

            $hasil = collect($respons['entry'] ?? [])
                ->map(fn ($e) => $e['response']['location'] ?? null)->filter()
                ->map(fn ($lokasi) => explode('/', $lokasi))
                ->groupBy(fn ($p) => $p[0])->map(fn ($g) => $g->map(fn ($p) => $p[1] ?? null)->filter()->values())
                ->all();
            $encounter = $hasil['Encounter'][0] ?? null;

            $kirim->update([
                'status' => SatuSehatKirim::TERKIRIM, 'encounter_id' => $encounter, 'hasil' => $hasil, 'error' => null, 'terkirim_at' => now(),
            ]);
            // Kolom sistem (tidak fillable)
            $kunjungan->forceFill(['satusehat_encounter_id' => $encounter])->save();
        } catch (SatuSehatException $e) {
            // Galat sementara tetap "menunggu" (job mencoba lagi); galat data langsung "gagal"
            $kirim->update([
                'status' => $e->sementara ? SatuSehatKirim::MENUNGGU : SatuSehatKirim::GAGAL,
                'error' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            if ($e->sementara) {
                throw $e;
            }
        }

        return $kirim->refresh();
    }

    /** IHS Number pasien dari NIK (PS-05); disimpan agar tidak dicari ulang. */
    public function ihsPasien(Pasien $pasien, bool $paksa = false): string
    {
        if ($pasien->ihs_id && ! $paksa) {
            return $pasien->ihs_id;
        }
        if (strlen((string) $pasien->nik) !== 16) {
            throw new SatuSehatException("Pasien {$pasien->nama} belum memiliki NIK 16 digit.");
        }

        $bundle = $this->client->get('/Patient', ['identifier' => self::NIK.'|'.$pasien->nik]);
        $id = $bundle['entry'][0]['resource']['id'] ?? null;
        $pasien->forceFill(['ihs_dicek_at' => now()])->save();

        if (! $id) {
            throw new SatuSehatException("NIK pasien {$pasien->nama} tidak ditemukan di SATUSEHAT (Dukcapil).");
        }

        $pasien->forceFill(['ihs_id' => $id])->save();

        return $id;
    }

    public function ihsPraktisi(?User $user): string
    {
        if (! $user) {
            throw new SatuSehatException('Kunjungan belum punya dokter penanggung jawab.');
        }
        if ($user->ihs_id) {
            return $user->ihs_id;
        }
        if (strlen((string) $user->nik) !== 16) {
            throw new SatuSehatException("NIK {$user->name} belum diisi (Master Pengguna) untuk pencarian IHS Practitioner.");
        }

        $bundle = $this->client->get('/Practitioner', ['identifier' => self::NIK.'|'.$user->nik]);
        $id = $bundle['entry'][0]['resource']['id'] ?? null
            ?? throw new SatuSehatException("NIK {$user->name} tidak terdaftar sebagai Practitioner di SATUSEHAT.");

        $user->forceFill(['ihs_id' => $id])->save();

        return $id;
    }

    /** Ringkasan status antrean & kepatuhan (KPI PRD: > 98% kunjungan bertanda tangan terkirim). */
    public function ringkasan(?int $cabangId): array
    {
        $perStatus = SatuSehatKirim::withoutGlobalScope('cabang')
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        $ditandatangani = Kunjungan::withoutGlobalScope('cabang')
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->whereDate('tanggal', '>=', today()->subDays(30))
            ->whereHas('pemeriksaan', fn ($q) => $q->whereNotNull('ditandatangani_at'))
            ->count();
        $terkirim = Kunjungan::withoutGlobalScope('cabang')
            ->when($cabangId, fn ($q) => $q->where('cabang_id', $cabangId))
            ->whereDate('tanggal', '>=', today()->subDays(30))
            ->whereNotNull('satusehat_encounter_id')
            ->count();

        return [
            'aktif' => $this->aktif(),
            'terkonfigurasi' => $this->client->terkonfigurasi(),
            'env' => config('services.satusehat.env'),
            'organization_id' => $this->client->organizationId(),
            'per_status' => [
                'menunggu' => (int) ($perStatus['menunggu'] ?? 0),
                'terkirim' => (int) ($perStatus['terkirim'] ?? 0),
                'gagal' => (int) ($perStatus['gagal'] ?? 0),
            ],
            'kepatuhan_30_hari' => [
                'ditandatangani' => $ditandatangani,
                'terkirim' => $terkirim,
                'persen' => $ditandatangani ? round($terkirim * 100 / $ditandatangani, 1) : null,
            ],
        ];
    }
}
