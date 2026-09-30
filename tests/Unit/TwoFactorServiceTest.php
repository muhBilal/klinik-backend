<?php

namespace Tests\Unit;

use App\Services\TwoFactorService;
use Tests\TestCase;

class TwoFactorServiceTest extends TestCase
{
    /** Secret ASCII "12345678901234567890" (vektor uji RFC 6238 untuk SHA1) dalam base32. */
    private const SECRET_RFC = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function test_kode_sesuai_vektor_rfc_6238(): void
    {
        $service = new TwoFactorService;

        // RFC 6238 Lampiran B (8 digit) -> 6 digit terakhir
        $this->assertSame('287082', $service->kode(self::SECRET_RFC, intdiv(59, 30)));
        $this->assertSame('081804', $service->kode(self::SECRET_RFC, intdiv(1111111109, 30)));
        $this->assertSame('050471', $service->kode(self::SECRET_RFC, intdiv(1111111111, 30)));
        $this->assertSame('005924', $service->kode(self::SECRET_RFC, intdiv(1234567890, 30)));
        $this->assertSame('279037', $service->kode(self::SECRET_RFC, intdiv(2000000000, 30)));
    }

    public function test_cocokkan_toleransi_satu_langkah_dan_anti_replay(): void
    {
        $service = new TwoFactorService;
        $secret = $service->buatSecret();
        $sekarang = $service->langkahSaatIni();

        $this->assertSame(32, strlen($secret));
        $this->assertSame($sekarang, $service->cocokkan($secret, $service->kode($secret, $sekarang)));
        $this->assertSame($sekarang - 1, $service->cocokkan($secret, $service->kode($secret, $sekarang - 1)));
        $this->assertNull($service->cocokkan($secret, $service->kode($secret, $sekarang - 3)));
        $this->assertNull($service->cocokkan($secret, 'abc123'));

        // Kode langkah yang sudah dipakai ditolak
        $this->assertNull($service->cocokkan($secret, $service->kode($secret, $sekarang), setelahLangkah: $sekarang));
    }
}
