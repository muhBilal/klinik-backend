<?php

namespace App\Models;

use App\Enums\KategoriBerkas;
use App\Enums\TahapFoto;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Berkas klinis terenkripsi. Tidak dibatasi cabang (riwayat pasien lintas cabang), `cabang_id` hanya informasi.
 * Isi file hanya lewat BerkasService; `path`, `thumbnail_path` & `checksum` tidak pernah dikirim ke klien.
 * Foto klinis (F1-06) punya metadata protokol, posisi, tahap (sebelum/sesudah/kontrol) dan tindakan kunjungan.
 */
#[Table('berkas')]
#[Fillable([
    'uuid', 'cabang_id', 'pasien_id', 'kunjungan_id', 'kunjungan_tindakan_id', 'kategori', 'protokol_foto_id', 'posisi', 'tahap',
    'keterangan', 'nama_file', 'mime', 'ukuran', 'diambil_at', 'lebar', 'tinggi', 'path', 'thumbnail_path', 'checksum', 'diunggah_oleh',
])]
#[Hidden(['id', 'path', 'thumbnail_path', 'checksum'])]
class Berkas extends Model
{
    use Auditable, SoftDeletes;

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'kategori' => KategoriBerkas::class,
            'ukuran' => 'integer',
            'tahap' => TahapFoto::class,
            'diambil_at' => 'datetime',
            'lebar' => 'integer',
            'tinggi' => 'integer',
        ];
    }

    public function pasien(): BelongsTo
    {
        return $this->belongsTo(Pasien::class)->withTrashed();
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }

    public function protokol(): BelongsTo
    {
        return $this->belongsTo(ProtokolFoto::class, 'protokol_foto_id')->withTrashed();
    }

    public function kunjunganTindakan(): BelongsTo
    {
        return $this->belongsTo(KunjunganTindakan::class);
    }

    public function punyaThumbnail(): bool
    {
        return filled($this->thumbnail_path);
    }

    public function pengunggah(): BelongsTo
    {
        return $this->belongsTo(User::class, 'diunggah_oleh')->withTrashed();
    }

    public function auditLabel(): ?string
    {
        return "{$this->kategori?->label()} · {$this->nama_file}";
    }

    public function auditPasienId(): ?int
    {
        return $this->pasien_id;
    }

    public function auditAbaikan(): array
    {
        return ['path', 'thumbnail_path', 'checksum'];
    }
}
