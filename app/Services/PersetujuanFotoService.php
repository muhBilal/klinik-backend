<?php

namespace App\Services;

use App\Enums\StatusPersetujuanFoto;
use App\Enums\TingkatPersetujuanFoto;
use App\Models\Kunjungan;
use App\Models\Pasien;
use App\Models\PersetujuanFoto;
use App\Models\User;
use App\Support\CabangAktif;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Consent foto klinis bertingkat per pasien (PRD FT-04). Naskah dari pengaturan `foto.naskah_consent`, di-render server
 * dan di-snapshot bersama tanda tangan. Persetujuan baru menggantikan yang lama; pencabutan menghentikan pengambilan foto baru.
 */
class PersetujuanFotoService
{
    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September',
        'Oktober', 'November', 'Desember'];

    public function __construct(private PengaturanService $pengaturan, private CabangAktif $cabang) {}

    public function aktif(Pasien $pasien): ?PersetujuanFoto
    {
        return $pasien->persetujuanFotoAktif()->first();
    }

    /** Naskah yang akan ditandatangani untuk tingkat tertentu. */
    public function naskah(Pasien $pasien, TingkatPersetujuanFoto $tingkat): string
    {
        $pilihan = collect(TingkatPersetujuanFoto::cases())
            ->map(fn (TingkatPersetujuanFoto $t) => ($t === $tingkat ? '[x] ' : '[ ] ').$t->label().' — '.$t->keterangan())
            ->implode("\n");

        return strtr((string) $this->pengaturan->get('foto.naskah_consent'), [
            '{nama_pasien}' => $pasien->nama,
            '{no_rm}' => $pasien->no_rm,
            '{klinik}' => (string) $this->pengaturan->get('klinik.nama'),
            '{tanggal}' => now()->day.' '.self::BULAN[now()->month].' '.now()->year,
            '{tingkat}' => $tingkat->label(),
            '{pilihan}' => $pilihan,
        ]);
    }

    /**
     * @param  array{tingkat: string, penandatangan_nama: string, hubungan: string, ttd: string, kunjungan_id?: int|null}  $data
     */
    public function simpan(Pasien $pasien, array $data, User $user): PersetujuanFoto
    {
        return DB::transaction(function () use ($pasien, $data, $user) {
            $kunjunganId = $data['kunjungan_id'] ?? null;
            if ($kunjunganId && ! Kunjungan::withoutGlobalScope('cabang')->whereKey($kunjunganId)->where('pasien_id', $pasien->id)->exists()) {
                throw ValidationException::withMessages(['kunjungan_id' => 'Kunjungan tidak valid untuk pasien ini.']);
            }

            // Hanya satu persetujuan berlaku per pasien; yang lama ditandai diganti (tetap tersimpan).
            PersetujuanFoto::where('pasien_id', $pasien->id)->where('status', StatusPersetujuanFoto::Berlaku)
                ->lockForUpdate()->get()
                ->each(fn (PersetujuanFoto $lama) => $lama->update(['status' => StatusPersetujuanFoto::Diganti, 'berakhir_at' => now()]));

            $tingkat = TingkatPersetujuanFoto::from($data['tingkat']);

            $persetujuan = new PersetujuanFoto([
                'uuid' => (string) Str::uuid(),
                'pasien_id' => $pasien->id,
                'cabang_id' => $this->cabang->id(),
                'kunjungan_id' => $kunjunganId,
                'tingkat' => $tingkat,
                'isi' => $this->naskah($pasien, $tingkat),
                'status' => StatusPersetujuanFoto::Berlaku,
                'penandatangan_nama' => $data['penandatangan_nama'],
                'hubungan' => $data['hubungan'],
                'ttd' => $data['ttd'],
                'dibuat_oleh' => $user->id,
                'ditandatangani_at' => now()->startOfSecond(),
                'ip_address' => request()->ip(),
            ]);
            $persetujuan->checksum = $persetujuan->hitungChecksum();
            $persetujuan->save();

            return $persetujuan;
        });
    }

    /** Pasien menarik persetujuan: foto baru tidak boleh diambil; foto lama tetap bagian rekam medis. */
    public function cabut(PersetujuanFoto $persetujuan, string $alasan, User $user): PersetujuanFoto
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

    /** Syarat unggah foto klinis bila pengaturan `foto.wajib_consent` aktif. */
    public function pastikanBolehFoto(int $pasienId): void
    {
        if (! $this->pengaturan->get('foto.wajib_consent')) {
            return;
        }

        $ada = PersetujuanFoto::where('pasien_id', $pasienId)->where('status', StatusPersetujuanFoto::Berlaku)->exists();

        if (! $ada) {
            throw ValidationException::withMessages([
                'consent_foto' => 'Pasien belum menandatangani persetujuan foto klinis (atau sudah mencabutnya).',
            ]);
        }
    }
}
