<?php

namespace Tests\Feature;

use App\Enums\StatusTagihan;
use App\Models\Kunjungan;
use App\Models\PaketPasien;
use App\Models\PersetujuanData;
use App\Models\Tagihan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Smoke test data demo (versi pendek) — memastikan DemoSeeder tetap berjalan lewat service aplikasi saat aturan bisnis berubah.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_membuat_transaksi_yang_konsisten(): void
    {
        $this->seed(DatabaseSeeder::class);
        DemoSeeder::$hariKeBelakang = 4;
        try {
            $this->seed(DemoSeeder::class);
        } finally {
            DemoSeeder::$hariKeBelakang = 42;
        }

        $this->assertGreaterThan(5, Kunjungan::withoutGlobalScope('cabang')->count());
        $this->assertGreaterThan(3, Tagihan::withoutGlobalScope('cabang')->where('status', StatusTagihan::Lunas)->count());
        $this->assertTrue(PersetujuanData::exists());
        $this->assertFalse(Carbon::hasTestNow(), 'Waktu simulasi harus dilepas setelah seeding.');

        // Paket yang dipesan dari pemeriksaan ditagihkan lewat tagihan kunjungannya sendiri (atau kunjungannya masih terbuka)
        foreach (PaketPasien::withoutGlobalScope('cabang')->whereNotNull('kunjungan_id')->with(['kunjungan', 'tagihan.items'])->get() as $p) {
            if ($p->tagihan_id) {
                $this->assertSame($p->kunjungan_id, $p->tagihan->kunjungan_id);
                $this->assertTrue($p->tagihan->items->contains('paket_id', $p->paket_id));
            } else {
                $this->assertTrue($p->kunjungan->terbuka(), "Pesanan {$p->no_paket} tanpa tagihan padahal kunjungannya sudah ditutup.");
            }
        }

        // Laporan & dashboard terbaca dengan data demo (administrator, semua cabang)
        Sanctum::actingAs(User::where('email', 'admin@eklinik.test')->firstOrFail());
        $this->getJson('/api/laporan/penjualan?mulai='.today()->subDays(5)->toDateString())->assertOk()
            ->assertJsonPath('ringkasan.transaksi', fn ($n) => $n > 0)->assertJsonCount(2, 'per_cabang');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(2, 'per_cabang');

        // Menjalankan ulang tidak menggandakan data
        $jumlah = Kunjungan::withoutGlobalScope('cabang')->count();
        $this->seed(DemoSeeder::class);
        $this->assertSame($jumlah, Kunjungan::withoutGlobalScope('cabang')->count());
    }
}
