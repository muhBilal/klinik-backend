<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Kode diagnosa (ICD-10) / tindakan (ICD-9-CM) favorit seorang dokter (PRD RM-02).
 * Preferensi pribadi, bukan data klinis — tidak diaudit.
 */
#[Table('kode_favorits')]
#[Fillable(['user_id', 'jenis', 'kode_id'])]
class KodeFavorit extends Model
{
    public const ICD10 = 'icd10';

    public const ICD9CM = 'icd9cm';

    /** jenis => model kode */
    public const MODEL = [
        self::ICD10 => Icd10::class,
        self::ICD9CM => Icd9cm::class,
    ];
}
