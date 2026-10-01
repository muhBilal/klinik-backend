<?php

namespace App\Services\SatuSehat;

use RuntimeException;

/**
 * Galat integrasi SATUSEHAT. `sementara` = layak dicoba ulang otomatis (jaringan, 5xx, 429);
 * selain itu (4xx, data belum lengkap) butuh perbaikan data lalu kirim ulang manual.
 */
class SatuSehatException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $sementara = false)
    {
        parent::__construct($message);
    }
}
