<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Baris audit bersifat append-only: update & delete lewat model ditolak.
 * Tulis hanya lewat AuditService.
 */
#[Table('audit_logs')]
#[Fillable([
    'user_id', 'cabang_id', 'aksi', 'tipe', 'subjek_id', 'pasien_id', 'label', 'perubahan',
    'ip_address', 'user_agent', 'created_at',
])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Audit log tidak boleh diubah.'));
        static::deleting(fn () => throw new LogicException('Audit log tidak boleh dihapus.'));
    }

    protected function casts(): array
    {
        return [
            'perubahan' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class)->withTrashed();
    }
}
