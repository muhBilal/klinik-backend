<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Table('pemeriksaan_diagnosas')]
#[Fillable(['pemeriksaan_id', 'icd10_id', 'jenis'])]
class PemeriksaanDiagnosa extends Model
{
    public function icd10(): BelongsTo
    {
        return $this->belongsTo(Icd10::class);
    }
}
