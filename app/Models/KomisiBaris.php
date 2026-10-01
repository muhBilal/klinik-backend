<?php

namespace App\Models;

use App\Enums\JenisKomisi;
use App\Enums\PeranKomisi;
use App\Enums\StatusKomisiPeriode;
use App\Enums\SumberKomisi;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Satu baris komisi (PRD KM-03): siapa, peran, sumber (tindakan/konsultasi/penyesuaian), treatment, dasar, jenis & nilai komisi
 * treatment yang dipakai (snapshot), komisi.
 * Hasil hitung ulang draf dibuat massal (tidak diaudit per baris — periodenya yang diaudit); penyesuaian manual tercatat audit lewat
 * `AuditService` di service. Baris periode yang disetujui terkunci.
 */
#[Table('komisi_barises')]
#[Fillable([
    'komisi_periode_id', 'user_id', 'peran', 'sumber', 'kunjungan_id', 'kunjungan_tindakan_id', 'tagihan_id', 'tindakan_id',
    'tanggal', 'deskripsi', 'dasar', 'jenis', 'nilai', 'komisi', 'dibuat_oleh',
])]
class KomisiBaris extends Model
{
    protected static function booted(): void
    {
        $kunci = function (KomisiBaris $baris) {
            $status = KomisiPeriode::withoutGlobalScope('cabang')->whereKey($baris->komisi_periode_id)->toBase()->value('status');
            if ($status === StatusKomisiPeriode::Disetujui->value) {
                throw new LogicException('Baris komisi pada rekap yang sudah disetujui terkunci.');
            }
        };
        static::updating($kunci);
        static::deleting($kunci);
    }

    protected function casts(): array
    {
        return [
            'peran' => PeranKomisi::class,
            'sumber' => SumberKomisi::class,
            'jenis' => JenisKomisi::class,
            'nilai' => 'float',
            'tanggal' => 'date:Y-m-d',
            'dasar' => 'integer',
            'komisi' => 'integer',
            'user_id' => 'integer',
        ];
    }

    public function periode(): BelongsTo
    {
        return $this->belongsTo(KomisiPeriode::class, 'komisi_periode_id')->withoutGlobalScope('cabang');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function kunjungan(): BelongsTo
    {
        return $this->belongsTo(Kunjungan::class)->withoutGlobalScope('cabang');
    }
}
