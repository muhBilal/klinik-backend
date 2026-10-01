<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Protokol foto klinis (PRD FT-01): daftar posisi standar yang diambil berurutan, mis. wajah depan / 45° / profil.
 * `posisi` = [{kode, label, petunjuk}]; `kode` dipakai untuk mencocokkan foto before-after lintas kunjungan.
 */
#[Table('protokol_fotos')]
#[Fillable(['nama', 'deskripsi', 'posisi', 'is_active'])]
class ProtokolFoto extends Model
{
    use Auditable, SoftDeletes;

    protected function casts(): array
    {
        return [
            'posisi' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function tindakans(): HasMany
    {
        return $this->hasMany(Tindakan::class);
    }

    /** @return list<string> */
    public function kodePosisi(): array
    {
        return array_column($this->posisi ?? [], 'kode');
    }

    public function auditLabel(): ?string
    {
        return $this->nama;
    }
}
