<?php

namespace App\Models;

use App\Enums\Izin;
use App\Models\Concerns\Auditable;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Peran (role) pengguna. `users.role` menyimpan `perans.kode`.
 * Peran sistem (`is_sistem`) tidak bisa dihapus/diganti kodenya; `akses_penuh` = semua izin.
 */
#[Table('perans')]
#[Fillable(['kode', 'nama', 'deskripsi'])]
#[Appends(['izin'])]
#[Hidden(['izins'])]
class Peran extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'is_sistem' => 'boolean',
            'akses_penuh' => 'boolean',
        ];
    }

    public function izins(): HasMany
    {
        return $this->hasMany(PeranIzin::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role', 'kode');
    }

    /** Daftar kode izin efektif. */
    protected function izin(): Attribute
    {
        return Attribute::get(fn () => $this->akses_penuh
            ? Izin::values()
            : $this->izins->pluck('izin')->intersect(Izin::values())->values()->all());
    }

    public function punya(Izin|string $izin): bool
    {
        return in_array($izin instanceof Izin ? $izin->value : $izin, $this->izin, true);
    }

    /** Ganti seluruh izin peran; dicatat sebagai satu baris audit berisi daftar lama & baru. */
    public function aturIzin(array $izin): void
    {
        $lama = $this->izins()->pluck('izin')->sort()->values()->all();
        $baru = collect($izin)->unique()->sort()->values()->all();

        if ($lama === $baru) {
            return;
        }

        $this->izins()->delete();
        $this->izins()->createMany(array_map(fn ($kode) => ['izin' => $kode], $baru));
        $this->unsetRelation('izins');

        app(AuditService::class)->catat('ubah_izin', 'peran', $this->id, [
            'label' => $this->kode,
            'perubahan' => ['izin' => ['lama' => $lama, 'baru' => $baru]],
        ]);
    }

    public function auditLabel(): ?string
    {
        return $this->kode;
    }
}
