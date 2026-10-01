<?php

namespace App\Jobs;

use App\Models\SatuSehatKirim;
use App\Services\SatuSehat\SatuSehatException;
use App\Services\SatuSehat\SatuSehatService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Kirim satu kunjungan ke SATUSEHAT (PRD v2 SS-05). Galat sementara (jaringan/5xx/429) dicoba ulang dengan jeda bertahap;
 * galat data langsung berstatus `gagal` dan menunggu perbaikan + kirim ulang manual.
 */
class KirimSatuSehat implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /** @var list<int> detik */
    public array $backoff = [60, 300, 900, 3600];

    public function __construct(public int $kirimId) {}

    public function handle(SatuSehatService $service): void
    {
        $kirim = SatuSehatKirim::withoutGlobalScope('cabang')->find($this->kirimId);

        if (! $kirim || $kirim->status === SatuSehatKirim::TERKIRIM) {
            return;
        }

        try {
            $service->kirim($kirim);
        } catch (SatuSehatException $e) {
            // Galat sementara: jadwalkan ulang dengan jeda bertahap (tanpa melempar ke pemicu job)
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);
        }
    }

    /** Semua percobaan habis → gagal, menunggu kirim ulang manual. */
    public function failed(?\Throwable $e): void
    {
        SatuSehatKirim::withoutGlobalScope('cabang')->whereKey($this->kirimId)
            ->where('status', '!=', SatuSehatKirim::TERKIRIM)
            ->update(['status' => SatuSehatKirim::GAGAL, 'error' => mb_substr((string) $e?->getMessage(), 0, 2000)]);
    }
}
