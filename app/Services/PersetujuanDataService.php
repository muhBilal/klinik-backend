<?php

namespace App\Services;

use App\Enums\JenisPersetujuanData;
use App\Enums\KanalMarketing;
use App\Enums\StatusPersetujuanData;
use App\Models\Pasien;
use App\Models\PersetujuanData;
use App\Models\User;
use App\Support\CabangAktif;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Persetujuan data pribadi pasien (PRD PS-04, UU No. 27/2022 PDP). Satu formulir bertanda tangan menghasilkan persetujuan
 * pemrosesan (selalu) dan — terpisah — opt-in marketing bila pasien bersedia. Naskah dari pengaturan `pdp.naskah_*`, di-snapshot.
 */
class PersetujuanDataService
{
    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September',
        'Oktober', 'November', 'Desember'];

    public function __construct(private PengaturanService $pengaturan, private CabangAktif $cabang) {}

    public function aktif(Pasien $pasien, JenisPersetujuanData $jenis): ?PersetujuanData
    {
        return PersetujuanData::where('pasien_id', $pasien->id)->where('jenis', $jenis)
            ->where('status', StatusPersetujuanData::Berlaku)->latest('id')->first();
    }

    /** @param  list<string>  $kanal */
    public function naskah(Pasien $pasien, JenisPersetujuanData $jenis, array $kanal = []): string
    {
        $kunci = $jenis === JenisPersetujuanData::Pemrosesan ? 'pdp.naskah_pemrosesan' : 'pdp.naskah_marketing';

        return strtr((string) $this->pengaturan->get($kunci), [
            '{nama_pasien}' => $pasien->nama,
            '{no_rm}' => $pasien->no_rm,
            '{klinik}' => (string) $this->pengaturan->get('klinik.nama'),
            '{tanggal}' => now()->day.' '.self::BULAN[now()->month].' '.now()->year,
            '{kanal}' => collect($kanal)->map(fn ($k) => KanalMarketing::from($k)->label())->implode(', ') ?: '-',
        ]);
    }

    /**
     * Formulir persetujuan: pemrosesan selalu ditandatangani (baru/perbarui); marketing mengikuti pilihan pasien — bersedia =
     * opt-in baru dengan kanal, tidak bersedia = opt-in yang masih berlaku dicabut.
     *
     * @param  array{marketing: bool, kanal?: list<string>, penandatangan_nama: string, hubungan: string, ttd: string}  $data
     * @return Collection<int, PersetujuanData>
     */
    public function simpan(Pasien $pasien, array $data, User $user): Collection
    {
        return DB::transaction(function () use ($pasien, $data, $user) {
            $waktu = now()->startOfSecond();
            $hasil = collect([$this->buat($pasien, JenisPersetujuanData::Pemrosesan, null, $data, $user, $waktu)]);

            if ($data['marketing']) {
                $hasil->push($this->buat($pasien, JenisPersetujuanData::Marketing, array_values($data['kanal'] ?? []), $data, $user, $waktu));
            } else {
                $this->cabutBerlaku($pasien, JenisPersetujuanData::Marketing, 'Pasien menyatakan tidak bersedia menerima promosi (formulir baru).', $user);
            }

            return $hasil;
        });
    }

    /** Hak subjek data menarik persetujuan. Menarik pemrosesan ikut menarik opt-in marketing. */
    public function cabut(PersetujuanData $persetujuan, string $alasan, User $user): PersetujuanData
    {
        if ($persetujuan->status !== StatusPersetujuanData::Berlaku) {
            throw ValidationException::withMessages(['status' => 'Hanya persetujuan yang berlaku yang dapat dicabut.']);
        }

        return DB::transaction(function () use ($persetujuan, $alasan, $user) {
            $persetujuan->update(['status' => StatusPersetujuanData::Dicabut, 'berakhir_at' => now(), 'dicabut_oleh' => $user->id,
                'alasan_cabut' => $alasan]);

            if ($persetujuan->jenis === JenisPersetujuanData::Pemrosesan) {
                $this->cabutBerlaku($persetujuan->pasien, JenisPersetujuanData::Marketing, 'Ikut dicabut bersama persetujuan pemrosesan data.', $user);
            }

            return $persetujuan;
        });
    }

    /** Syarat pendaftaran kunjungan (termasuk check-in booking) bila pengaturan `pdp.wajib_persetujuan` aktif. */
    public function pastikanBolehDaftar(int $pasienId): void
    {
        if (! $this->pengaturan->get('pdp.wajib_persetujuan')) {
            return;
        }

        $ada = PersetujuanData::where('pasien_id', $pasienId)->where('jenis', JenisPersetujuanData::Pemrosesan)
            ->where('status', StatusPersetujuanData::Berlaku)->exists();

        if (! $ada) {
            throw ValidationException::withMessages([
                'persetujuan_data' => 'Pasien belum menandatangani persetujuan pemrosesan data pribadi (atau sudah mencabutnya).',
            ]);
        }
    }

    /** @param  list<string>|null  $kanal */
    private function buat(Pasien $pasien, JenisPersetujuanData $jenis, ?array $kanal, array $data, User $user, Carbon $waktu): PersetujuanData
    {
        // Hanya satu persetujuan berlaku per pasien per jenis; yang lama ditandai diganti (tetap tersimpan sebagai bukti).
        PersetujuanData::where('pasien_id', $pasien->id)->where('jenis', $jenis)->where('status', StatusPersetujuanData::Berlaku)
            ->lockForUpdate()->get()
            ->each(fn (PersetujuanData $lama) => $lama->update(['status' => StatusPersetujuanData::Diganti, 'berakhir_at' => now()]));

        $persetujuan = new PersetujuanData([
            'uuid' => (string) Str::uuid(),
            'pasien_id' => $pasien->id,
            'cabang_id' => $this->cabang->id(),
            'jenis' => $jenis,
            'kanal' => $kanal,
            'isi' => $this->naskah($pasien, $jenis, $kanal ?? []),
            'status' => StatusPersetujuanData::Berlaku,
            'penandatangan_nama' => $data['penandatangan_nama'],
            'hubungan' => $data['hubungan'],
            'ttd' => $data['ttd'],
            'dibuat_oleh' => $user->id,
            'ditandatangani_at' => $waktu,
            'ip_address' => request()->ip(),
        ]);
        $persetujuan->checksum = $persetujuan->hitungChecksum();
        $persetujuan->save();

        return $persetujuan;
    }

    private function cabutBerlaku(Pasien $pasien, JenisPersetujuanData $jenis, string $alasan, User $user): void
    {
        PersetujuanData::where('pasien_id', $pasien->id)->where('jenis', $jenis)->where('status', StatusPersetujuanData::Berlaku)
            ->get()
            ->each(fn (PersetujuanData $p) => $p->update(['status' => StatusPersetujuanData::Dicabut, 'berakhir_at' => now(),
                'dicabut_oleh' => $user->id, 'alasan_cabut' => $alasan]));
    }
}
