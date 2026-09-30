<?php

namespace App\Services;

use App\Models\Berkas;
use App\Models\User;
use App\Support\CabangAktif;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Penyimpanan berkas klinis terenkripsi (PRD FT-03, 7.2 Enkripsi).
 * Isi file dienkripsi AES-256 (Crypt, kunci APP_KEY) sebelum ditulis ke disk `berkas` (storage/app/private/berkas),
 * dan hanya bisa diambil lewat tautan bertanda tangan yang kedaluwarsa. Setiap akses dicatat di audit log.
 */
class BerkasService
{
    public function __construct(private AuditService $audit) {}

    /**
     * @param  array{pasien_id: int, kunjungan_id?: int|null, cabang_id?: int|null, kategori: string, keterangan?: string|null}  $data
     */
    public function simpan(UploadedFile $file, array $data, User $user): Berkas
    {
        $isi = $file->get();
        $uuid = (string) Str::uuid();
        $path = now()->format('Y/m')."/{$uuid}.enc";

        Storage::disk('berkas')->put($path, Crypt::encryptString($isi));

        try {
            return Berkas::create([
                ...$data,
                'uuid' => $uuid,
                'cabang_id' => $data['cabang_id'] ?? app(CabangAktif::class)->id(),
                'nama_file' => Str::limit($file->getClientOriginalName(), 200, ''),
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'ukuran' => strlen($isi),
                'path' => $path,
                'checksum' => hash('sha256', $isi),
                'diunggah_oleh' => $user->id,
            ]);
        } catch (Throwable $e) {
            Storage::disk('berkas')->delete($path);

            throw $e;
        }
    }

    /** Isi asli (terdekripsi). Checksum dicek agar berkas yang rusak/diubah di disk tidak diam-diam dikirim. */
    public function isi(Berkas $berkas): string
    {
        $isi = Crypt::decryptString(Storage::disk('berkas')->get($berkas->path));

        if (! hash_equals($berkas->checksum, hash('sha256', $isi))) {
            throw new RuntimeException("Checksum berkas {$berkas->uuid} tidak cocok.");
        }

        return $isi;
    }

    /** Tautan unduh bertanda tangan (tanpa header Authorization, sehingga bisa dipakai <img src>). */
    public function tautan(Berkas $berkas, User $user): array
    {
        $kedaluwarsa = now()->addMinutes((int) config('eklinik.berkas.tautan_menit'));

        $this->audit->catat('akses_berkas', 'berkas', $berkas->id, [
            'pasien_id' => $berkas->pasien_id,
            'label' => $berkas->auditLabel(),
        ]);

        return [
            'url' => URL::temporarySignedRoute('berkas.unduh', $kedaluwarsa, ['berkas' => $berkas->uuid, 'u' => $user->id]),
            'kedaluwarsa' => $kedaluwarsa->toIso8601String(),
        ];
    }
}
