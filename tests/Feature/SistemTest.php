<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** Observabilitas operasional (PRD v2 7.2): detak scheduler, antrean, job gagal. */
class SistemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_status_scheduler_dan_job_gagal(): void
    {
        Sanctum::actingAs(User::where('email', 'admin@eklinik.test')->firstOrFail());

        $this->getJson('/api/sistem/status')->assertOk()->assertJsonPath('scheduler.sehat', false);
        Cache::put('eklinik.scheduler.detak', now()->subMinutes(2)->toIso8601String());
        $this->getJson('/api/sistem/status')->assertOk()->assertJsonPath('scheduler.sehat', true)->assertJsonPath('scheduler.menit_lalu', 2);

        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\KirimSatuSehat', 'data' => ['rahasia' => 'data pasien']]),
            'exception' => "RuntimeException: Server sibuk\n#0 trace...", 'failed_at' => now(),
        ]);

        $res = $this->getJson('/api/sistem/job-gagal')->assertOk()
            ->assertJsonPath('0.job', 'App\\Jobs\\KirimSatuSehat')
            ->assertJsonPath('0.galat', 'RuntimeException: Server sibuk');
        $this->assertStringNotContainsString('data pasien', $res->getContent());
        $this->getJson('/api/sistem/status')->assertJsonPath('antrean.gagal', 1);

        $this->deleteJson("/api/sistem/job-gagal/{$uuid}")->assertOk();
        $this->getJson('/api/sistem/status')->assertJsonPath('antrean.gagal', 0);

        Sanctum::actingAs(User::where('email', 'manajer@eklinik.test')->firstOrFail());
        $this->getJson('/api/sistem/status')->assertForbidden();
    }
}
