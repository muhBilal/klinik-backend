<?php

namespace App\Services\WhatsApp;

use RuntimeException;

/** `sementara` = layak dicoba ulang (jaringan, 5xx, 429). */
class WhatsAppException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $sementara = false)
    {
        parent::__construct($message);
    }
}
