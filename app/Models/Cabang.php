<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Table('cabangs')]
#[Fillable(['kode', 'nama', 'alamat', 'telepon', 'email', 'jam_buka', 'jam_tutup', 'satusehat_location_id', 'is_active'])]
class Cabang extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /** Kolom TIME dikirim sebagai "HH:MM" (PostgreSQL mengembalikan "HH:MM:SS"). */
    protected function jamBuka(): Attribute
    {
        return Attribute::get(fn (?string $value) => $value ? substr($value, 0, 5) : null);
    }

    protected function jamTutup(): Attribute
    {
        return Attribute::get(fn (?string $value) => $value ? substr($value, 0, 5) : null);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function kunjungans(): HasMany
    {
        return $this->hasMany(Kunjungan::class)->withoutGlobalScope('cabang');
    }

    public function auditLabel(): ?string
    {
        return "{$this->kode} · {$this->nama}";
    }
}
