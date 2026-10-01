<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Driver `log`: pesan hanya dicatat ke log aplikasi (dev / sebelum kredensial WhatsApp tersedia). Nomor disamarkan. */
class LogGateway implements WhatsAppGateway
{
    public function kirimTemplate(string $ke, string $template, array $parameter, array $tombol = []): string
    {
        Log::info('WhatsApp (driver log)', ['ke' => substr($ke, 0, 5).'****'.substr($ke, -3), 'template' => $template, 'tombol' => $tombol]);

        return 'log-'.Str::uuid();
    }
}
