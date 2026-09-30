<?php

namespace App\Support;

use App\Models\Cabang;
use Illuminate\Validation\ValidationException;

/**
 * Cabang yang sedang dipakai request ini. Diisi middleware `cabang` (ResolveCabang):
 * - user terikat cabang (`users.cabang_id`) -> selalu cabang itu;
 * - user lintas cabang (`cabang_id = null`) -> header `X-Cabang-Id`, atau null = semua cabang (tampilan konsolidasi).
 *
 * Model bertrait `DalamCabang` otomatis difilter ke cabang ini dan mengisi `cabang_id` saat dibuat.
 */
class CabangAktif
{
    private ?int $id = null;

    public function set(?int $id): void
    {
        $this->id = $id;
    }

    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * Cabang untuk data baru. Tanpa cabang aktif hanya boleh bila klinik baru punya satu cabang aktif.
     */
    public function untukDataBaru(): int
    {
        if ($this->id) {
            return $this->id;
        }

        $aktif = Cabang::where('is_active', true)->limit(2)->pluck('id');

        if ($aktif->count() === 1) {
            return $aktif->first();
        }

        throw ValidationException::withMessages([
            'cabang' => $aktif->isEmpty()
                ? 'Belum ada cabang aktif. Tambahkan cabang di menu Master Cabang.'
                : 'Pilih cabang aktif terlebih dahulu.',
        ]);
    }
}
