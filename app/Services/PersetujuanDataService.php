<?php

namespace App\Services;

use App\Enums\JenisPersetujuanData;
use App\Enums\StatusPersetujuanFoto;
use App\Models\Pasien;
use App\Models\PersetujuanData;
use App\Models\User;
use App\Support\CabangAktif;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Consent UU PDP (PRD PS-04): pemrosesan data pribadi & kesehatan, dan opt-in pemasaran — dua persetujuan terpisah.
 * Naskah dari pengaturan `pdp.naskah_pemrosesan` / `pdp.naskah_marketing`, di-render & di-snapshot bersama tanda tangan.
 */
class PersetujuanDataService
{
    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September',
        'Oktober', 'November', 'Desember'];

    public function __construct(private PengaturanService $pengaturan, private CabangAktif $cabang) {}

    public function naskah(Pasien $pasien, JenisPersetujuanData $jenis, bool $setuju): string
    {
        $kunci = $jenis === JenisPersetujuanData::Pemrosesan ? 'pdp.naskah_pemrosesan' : 'pdp.naskah_marketing';

        return strtr((string) $this->pengaturan->get($kunci), [
            '{nama_pasien}' => $pasien->nama,
            '{no_rm}' => $pasien->no_rm,
            '{klinik}' => (string) $this->pengaturan->get('klinik.nama'),
            '{tanggal}' => now()->day.' '.self::BULAN[now()->month].' '.now()->year,
            '{keputusan}' => $setuju ? 'MENYETUJUI' : 'TIDAK MENYETUJUI',
        ]);
    }

    /** Ringkasan untuk identitas pasien: status berlaku per jenis (null = belum pernah). */
    public function ringkasan(Pasien $pasien): array
    {
        $aktif = PersetujuanData::where('pasien_id', $pasien->id)->where('status', StatusPersetujuanFoto::Berlaku)->get()->keyBy(fn ($p) => $p->jenis->value);

        return [
            'pemrosesan' => isset($aktif['pemrosesan']) ? $aktif['pemrosesan']->setuju : null,
            'marketing' => isset($aktif['marketing']) ? $aktif['marketing']->setuju : null,
        ];
    }

    /** @param  array{jenis: string, setuju: bool, penandatangan_nama: string, hubungan: string, ttd: string}  $data */
    public function simpan(Pasien $pasien, array $data, User $user): PersetujuanData
    {
        $jenis = JenisPersetujuanData::from($data['jenis']);
        $setuju = (bool) $data['setuju'];

        if ($jenis === JenisPersetujuanData::Pemrosesan && ! $setuju) {
            throw ValidationException::withMessages([
                'setuju' => 'Persetujuan pemrosesan data dibutuhkan untuk pelayanan. Bila pasien menolak, catat secara manual dan konsultasikan ke penanggung jawab.',
            ]);
        }

        return DB::transaction(function () use ($pasien, $data, $user, $jenis, $setuju) {
            PersetujuanData::where('pasien_id', $pasien->id)->where('jenis', $jenis)->where('status', StatusPersetujuanFoto::Berlaku)
                ->lockForUpdate()->get()
                ->each(fn (PersetujuanData $lama) => $lama->update(['status' => StatusPersetujuanFoto::Diganti, 'berakhir_at' => now()]));

            $p = new PersetujuanData([
                'uuid' => (string) Str::uuid(),
                'pasien_id' => $pasien->id,
                'cabang_id' => $this->cabang->id(),
                'jenis' => $jenis,
                'setuju' => $setuju,
                'isi' => $this->naskah($pasien, $jenis, $setuju),
                'status' => StatusPersetujuanFoto::Berlaku,
                'penandatangan_nama' => $data['penandatangan_nama'],
                'hubungan' => $data['hubungan'],
                'ttd' => $data['ttd'],
                'dibuat_oleh' => $user->id,
                'ditandatangani_at' => now()->startOfSecond(),
                'ip_address' => request()->ip(),
            ]);
            $p->checksum = $p->hitungChecksum();
            $p->save();

            return $p;
        });
    }

    public function cabut(PersetujuanData $persetujuan, string $alasan, User $user): PersetujuanData
    {
        if ($persetujuan->status !== StatusPersetujuanFoto::Berlaku) {
            throw ValidationException::withMessages(['status' => 'Hanya persetujuan yang berlaku yang dapat dicabut.']);
        }

        $persetujuan->update([
            'status' => StatusPersetujuanFoto::Dicabut,
            'berakhir_at' => now(),
            'dicabut_oleh' => $user->id,
            'alasan_cabut' => $alasan,
        ]);

        return $persetujuan;
    }

    /** Syarat pendaftaran kunjungan & booking bila pengaturan `pdp.wajib_consent` aktif. */
    public function pastikanAda(int $pasienId, string $field = 'pasien_id'): void
    {
        if (! $this->pengaturan->get('pdp.wajib_consent')) {
            return;
        }

        $ada = PersetujuanData::where('pasien_id', $pasienId)->where('jenis', JenisPersetujuanData::Pemrosesan)
            ->where('setuju', true)->where('status', StatusPersetujuanFoto::Berlaku)->exists();

        if (! $ada) {
            throw ValidationException::withMessages([
                $field => 'Pasien belum menandatangani persetujuan pemrosesan data (UU PDP). Minta tanda tangan di profil pasien.',
                'consent_data' => 'wajib',
            ]);
        }
    }
}
