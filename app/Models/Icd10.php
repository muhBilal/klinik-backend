<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `sensitif` = diagnosa rahasia (IMS, HIV). Kunjungan yang memakainya otomatis berakses terbatas (PRD DR-03).
 */
#[Table('icd10s')]
#[Fillable(['kode', 'nama', 'sensitif'])]
class Icd10 extends Model
{
    use Auditable;

    /** A50-A64 infeksi menular seksual, B20-B24 penyakit HIV, Z21 status HIV asimtomatik, R75 bukti lab HIV. */
    private const POLA_SENSITIF = '/^(A5\d|A6[0-4]|B2[0-4]|Z21|R75)/';

    protected function casts(): array
    {
        return [
            'sensitif' => 'boolean',
        ];
    }

    /** Saran awal penanda sensitif untuk kode baru; admin tetap bisa mengubahnya. */
    public static function kodeSensitif(string $kode): bool
    {
        return preg_match(self::POLA_SENSITIF, strtoupper($kode)) === 1;
    }

    public function favorits(): HasMany
    {
        return $this->hasMany(KodeFavorit::class, 'kode_id')->where('jenis', KodeFavorit::ICD10);
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
