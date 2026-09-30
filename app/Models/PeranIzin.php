<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

#[Table('peran_izins')]
#[Fillable(['peran_id', 'izin'])]
class PeranIzin extends Model
{
    public $timestamps = false;
}
