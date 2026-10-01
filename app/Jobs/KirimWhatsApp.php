<?php

namespace App\Jobs;

use App\Models\PesanWhatsapp;
use App\Services\WhatsApp\WhatsAppException;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Kirim satu pesan WhatsApp (BK-06, CR-01). Galat sementara dijadwalkan ulang dengan jeda; percobaan habis → gagal. */
class KirimWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /** @var list<int> detik */
    public array $backoff = [60, 300, 900];

    public function __construct(public int $pesanId) {}

    public function handle(WhatsAppService $service): void
    {
        $pesan = PesanWhatsapp::with('appointment')->find($this->pesanId);

        if (! $pesan || ! in_array($pesan->status, ['antre', 'gagal'], true)) {
            return;
        }

        try {
            $service->kirim($pesan);
        } catch (WhatsAppException) {
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }

    public function failed(?\Throwable $e): void
    {
        PesanWhatsapp::whereKey($this->pesanId)->where('status', 'antre')
            ->update(['status' => 'gagal', 'error' => mb_substr((string) $e?->getMessage(), 0, 1000)]);
    }
}
