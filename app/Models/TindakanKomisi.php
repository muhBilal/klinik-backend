<?php

namespace App\Models;

use App\Enums\JenisKomisi;
use App\Enums\PeranKomisi;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Komisi satu peran pada satu treatment (PRD KM-01, TR-01), diatur di master treatment. Peran: dokter = dokter kunjungan,
 * terapis = pelaksana tindakan, asisten. Persen dari dasar (pengaturan `komisi.dasar`) atau nominal per unit tindakan.
 * Tidak ada baris untuk suatu peran = peran itu tidak mendapat komisi dari treatment ini.
 */
#[Table('tindakan_komisis')]
#[Fillable(['tindakan_id', 'peran', 'jenis', 'nilai'])]
class TindakanKomisi extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return [
            'peran' => PeranKomisi::class,
            'jenis' => JenisKomisi::class,
            'nilai' => 'float',
            'tindakan_id' => 'integer',
        ];
    }

    public function tindakan(): BelongsTo
    {
        return $this->belongsTo(Tindakan::class)->withTrashed();
    }

    /** Komisi dari dasar (rupiah) & jumlah tindakan: persen dari dasar, atau nominal × jumlah. */
    public function hitung(int $dasar, int $jumlah): int
    {
        return $this->jenis === JenisKomisi::Persen
            ? (int) round($dasar * $this->nilai / 100)
            : (int) round($this->nilai * $jumlah);
    }

    /** Teks nilai, mis. "10%" atau "Rp 50.000". */
    public function teksNilai(): string
    {
        return $this->jenis === JenisKomisi::Persen
            ? rtrim(rtrim(number_format($this->nilai, 2, ',', '.'), '0'), ',').'%'
            : 'Rp '.number_format($this->nilai, 0, ',', '.');
    }

    public function auditLabel(): ?string
    {
        $tindakan = $this->tindakan()->value('kode');

        return "{$tindakan} · komisi {$this->peran->label()} {$this->teksNilai()}";
    }
}
