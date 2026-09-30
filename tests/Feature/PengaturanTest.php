<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Pasien;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pengaturan klinik (PRD AD-04): identitas klinik, kop struk, prefix nomor, keamanan.
 */
class PengaturanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function as(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_info_publik_tanpa_login_dan_tanpa_pengaturan_rahasia(): void
    {
        $this->getJson('/api/info')->assertOk()
            ->assertJsonPath('klinik.nama', config('app.name'))
            ->assertJsonPath('cetak.lebar_struk', '80mm')
            ->assertJsonMissingPath('keamanan')
            ->assertJsonMissingPath('penomoran');
    }

    public function test_admin_mengubah_pengaturan_dan_prefix_nomor_dipakai(): void
    {
        $this->as('admin@eklinik.test');

        $this->getJson('/api/pengaturan')->assertOk()
            ->assertJsonPath('penomoran.prefix_registrasi', 'REG')
            ->assertJsonPath('keamanan.idle_timeout_menit', 15);

        $this->putJson('/api/pengaturan', [
            'klinik' => ['nama' => 'Klinik Cantik Sehat', 'telepon' => '021-777'],
            'penomoran' => ['prefix_registrasi' => 'RJ'],
            'keamanan' => ['wajib_2fa' => ['admin']],
        ])->assertOk()
            ->assertJsonPath('klinik.nama', 'Klinik Cantik Sehat')
            ->assertJsonPath('klinik.alamat', null)
            ->assertJsonPath('penomoran.prefix_tagihan', 'INV');

        $this->getJson('/api/info')->assertJsonPath('klinik.nama', 'Klinik Cantik Sehat')->assertJsonPath('klinik.telepon', '021-777');

        $log = AuditLog::where(['aksi' => 'ubah', 'tipe' => 'pengaturan'])->firstOrFail();
        $this->assertSame(['lama' => 'REG', 'baru' => 'RJ'], $log->perubahan['penomoran.prefix_registrasi']);

        $this->as('pendaftaran@eklinik.test');
        $this->postJson('/api/kunjungans', [
            'pasien_id' => Pasien::first()->id, 'poli_id' => User::where('email', 'dokter@eklinik.test')->value('poli_id'), 'penjamin' => 'umum',
        ])->assertCreated()->assertJsonPath('no_registrasi', fn ($nomor) => str_starts_with($nomor, 'RJ'.now()->format('Ymd')));
    }

    public function test_validasi_pengaturan(): void
    {
        $this->as('admin@eklinik.test');

        $this->putJson('/api/pengaturan', [
            'penomoran' => ['prefix_resep' => 'rs1'],
            'keamanan' => ['idle_timeout_menit' => 1, 'wajib_2fa' => ['tidak_ada']],
            'cetak' => ['lebar_struk' => 'a3'],
            'klinik' => ['nama' => ''],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'penomoran.prefix_resep', 'keamanan.idle_timeout_menit', 'keamanan.wajib_2fa.0', 'cetak.lebar_struk', 'klinik.nama',
        ]);

        $this->as('kasir@eklinik.test');
        $this->getJson('/api/pengaturan')->assertForbidden();
        $this->putJson('/api/pengaturan', ['klinik' => ['nama' => 'X']])->assertForbidden();
    }
}
