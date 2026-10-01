<?php

namespace App\Services;

use App\Enums\StatusConsent;
use App\Models\InformedConsent;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\TemplateConsent;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Informed consent digital per tindakan (PRD RM-03).
 *
 * Naskah di-render dari template di server (bukan dari klien) lalu di-snapshot bersama tanda tangan, sehingga
 * yang tersimpan persis yang ditampilkan saat pasien menandatangani. Treatment yang punya template consent
 * wajib memiliki consent `disetujui` sebelum pemeriksaan ditutup (pengaturan `rme.wajib_informed_consent`).
 */
class InformedConsentService
{
    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September',
        'Oktober', 'November', 'Desember'];

    /** Ukuran maksimum gambar tanda tangan (byte, setelah decode base64). */
    public const TTD_MAKS_BYTE = 300 * 1024;

    public function __construct(private PengaturanService $pengaturan) {}

    /** @return array{judul: string, tindakan_nama: ?string, isi: string, dokter: ?array} */
    public function pratinjau(Kunjungan $kunjungan, TemplateConsent $template, ?KunjunganTindakan $kunjunganTindakan, ?User $dokter): array
    {
        $kunjungan->loadMissing(['pasien', 'cabang']);
        $tindakanNama = $kunjunganTindakan?->tindakan?->nama;
        $pasien = $kunjungan->pasien;

        return [
            'judul' => $template->nama,
            'tindakan_nama' => $tindakanNama,
            'isi' => $template->render([
                '{nama_pasien}' => $pasien->nama,
                '{no_rm}' => $pasien->no_rm,
                '{tanggal_lahir}' => $pasien->tanggal_lahir ? $this->tanggal($pasien->tanggal_lahir) : null,
                '{tindakan}' => $tindakanNama,
                '{dokter}' => $dokter?->name,
                '{tanggal}' => $this->tanggal(now()),
                '{klinik}' => $this->pengaturan->get('klinik.nama'),
                '{cabang}' => $kunjungan->cabang?->nama,
            ]),
            'dokter' => $dokter?->only(['id', 'name']),
        ];
    }

    /** Dokter pemberi penjelasan: pilihan eksplisit, dokter kunjungan, atau user yang login bila ia dokter. */
    public function dokterPemberiInformasi(Kunjungan $kunjungan, ?int $dokterId, User $user): ?User
    {
        if ($dokterId) {
            return User::dokter()->find($dokterId)
                ?? throw ValidationException::withMessages(['dokter_id' => 'Dokter tidak ditemukan.']);
        }

        return $kunjungan->dokter ?? ($user->tercatatSebagaiDokter() ? $user : null);
    }

    /**
     * @param  array{template_consent_id: int, kunjungan_tindakan_id?: int|null, keputusan: string, penandatangan_nama: string,
     *               hubungan: string, ttd_penandatangan: string, saksi_nama?: ?string, ttd_saksi?: ?string, dokter_id?: ?int}  $data
     */
    public function simpan(Kunjungan $kunjungan, array $data, User $user): InformedConsent
    {
        $this->pastikanTerbuka($kunjungan);

        return DB::transaction(function () use ($kunjungan, $data, $user) {
            $kunjunganTindakan = null;

            if ($data['kunjungan_tindakan_id'] ?? null) {
                $kunjunganTindakan = $kunjungan->tindakans()->with('tindakan')->whereKey($data['kunjungan_tindakan_id'])->lockForUpdate()->first()
                    ?? throw ValidationException::withMessages(['kunjungan_tindakan_id' => 'Tindakan tidak ada di kunjungan ini.']);

                // Satu persetujuan berlaku per tindakan; yang lama harus dicabut dulu bila pasien berubah pikiran.
                if ($kunjunganTindakan->informedConsents()->where('status', StatusConsent::Disetujui)->exists()) {
                    throw ValidationException::withMessages([
                        'kunjungan_tindakan_id' => 'Tindakan ini sudah memiliki informed consent yang berlaku.',
                    ]);
                }
            }

            $template = TemplateConsent::findOrFail($data['template_consent_id']);
            $dokter = $this->dokterPemberiInformasi($kunjungan, $data['dokter_id'] ?? null, $user);
            $naskah = $this->pratinjau($kunjungan, $template, $kunjunganTindakan, $dokter);

            $consent = new InformedConsent([
                'uuid' => (string) Str::uuid(),
                'cabang_id' => $kunjungan->cabang_id,
                'kunjungan_id' => $kunjungan->id,
                'pasien_id' => $kunjungan->pasien_id,
                'kunjungan_tindakan_id' => $kunjunganTindakan?->id,
                'template_consent_id' => $template->id,
                'judul' => $naskah['judul'],
                'tindakan_nama' => $naskah['tindakan_nama'],
                'isi' => $naskah['isi'],
                'status' => $data['keputusan'] === 'setuju' ? StatusConsent::Disetujui : StatusConsent::Ditolak,
                'penandatangan_nama' => $data['penandatangan_nama'],
                'hubungan' => $data['hubungan'],
                'ttd_penandatangan' => $data['ttd_penandatangan'],
                'saksi_nama' => $data['saksi_nama'] ?? null,
                'ttd_saksi' => $data['ttd_saksi'] ?? null,
                'dokter_id' => $dokter?->id,
                'dibuat_oleh' => $user->id,
                'ditandatangani_at' => now()->startOfSecond(),
                'ip_address' => request()->ip(),
            ]);
            $consent->checksum = $consent->hitungChecksum();
            $consent->save();

            return $consent;
        });
    }

    /** Pasien menarik persetujuan sebelum tindakan dilakukan (selama pemeriksaan masih terbuka). */
    public function cabut(InformedConsent $consent, string $alasan, User $user): InformedConsent
    {
        $this->pastikanTerbuka($consent->kunjungan);

        if ($consent->status !== StatusConsent::Disetujui) {
            throw ValidationException::withMessages(['status' => 'Hanya persetujuan yang berlaku yang dapat dicabut.']);
        }

        $consent->update([
            'status' => StatusConsent::Dicabut,
            'dicabut_at' => now(),
            'dicabut_oleh' => $user->id,
            'alasan_cabut' => $alasan,
        ]);

        return $consent;
    }

    /**
     * Semua tindakan kunjungan yang treatment-nya ber-template consent harus punya consent `disetujui`.
     * Dipanggil sebelum pemeriksaan ditutup.
     */
    public function pastikanLengkap(Kunjungan $kunjungan): void
    {
        if (! $this->pengaturan->get('rme.wajib_informed_consent')) {
            return;
        }

        $kurang = [];

        $tindakans = $kunjungan->tindakans()
            ->with(['tindakan' => fn ($q) => $q->withTrashed()->select(['id', 'nama', 'template_consent_id']), 'informedConsents:id,kunjungan_tindakan_id,status'])
            ->get();

        foreach ($tindakans as $baris) {
            if (! $baris->tindakan?->template_consent_id) {
                continue;
            }

            $status = $baris->informedConsents->pluck('status');

            if ($status->contains(StatusConsent::Disetujui)) {
                continue;
            }

            $kurang[] = $status->contains(StatusConsent::Ditolak)
                ? "Pasien menolak {$baris->tindakan->nama}. Hapus tindakan ini dari pemeriksaan."
                : "Informed consent {$baris->tindakan->nama} belum ditandatangani pasien.";
        }

        if ($kurang) {
            throw ValidationException::withMessages(['informed_consent' => $kurang]);
        }
    }

    /** Data URL PNG yang masuk akal sebagai tanda tangan: header & magic bytes PNG, ukuran wajar. */
    public static function ttdValid(mixed $nilai): bool
    {
        if (! is_string($nilai) || ! str_starts_with($nilai, 'data:image/png;base64,')) {
            return false;
        }

        $biner = base64_decode(substr($nilai, 22), true);

        return $biner !== false
            && strlen($biner) >= 100
            && strlen($biner) <= self::TTD_MAKS_BYTE
            && str_starts_with($biner, "\x89PNG\r\n\x1a\n");
    }

    private function pastikanTerbuka(Kunjungan $kunjungan): void
    {
        if (! $kunjungan->terbuka()) {
            throw ValidationException::withMessages([
                'status' => 'Informed consent hanya dapat diubah selama pemeriksaan masih berjalan.',
            ]);
        }
    }

    private function tanggal(CarbonInterface $tanggal): string
    {
        return $tanggal->day.' '.self::BULAN[$tanggal->month].' '.$tanggal->year;
    }
}
