<?php

namespace App\Enums;

enum StatusResep: string
{
    case Menunggu = 'menunggu';
    case Diserahkan = 'diserahkan';
    case Batal = 'batal';
}
