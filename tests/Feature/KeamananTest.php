<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\PengaturanService;
use App\Services\TwoFactorService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Keamanan sesi & akun (PRD 7.2): masa berlaku token, idle timeout, 2FA TOTP, ganti password.
 * Memakai token asli (bukan Sanctum::actingAs) agar validasi token ikut diuji.
 */
class KeamananTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function login(string $email = 'dokter@eklinik.test'): string
    {
        return $this->postJson('/api/login', ['email' => $email, 'password' => 'password'])->assertOk()->json('token');
    }

    private function api(string $token): static
    {
        // Guard sanctum menyimpan user antar-request di satu test; lupakan agar setiap request memvalidasi token.
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    public function test_token_berakhir_setelah_idle_dan_masa_berlaku(): void
    {
        $token = $this->login();

        $akses = PersonalAccessToken::findToken($token);
        $this->assertEqualsWithDelta(now()->addMinutes(720)->timestamp, $akses->expires_at->timestamp, 5);

        $this->travel(10)->minutes();
        $this->api($token)->getJson('/api/me')->assertOk();

        // Dipakai 10 menit lalu -> idle 16 menit melewati batas 15 menit
        $this->travel(16)->minutes();
        $this->api($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_idle_timeout_mengikuti_pengaturan_dan_user_nonaktif_ditolak(): void
    {
        app(PengaturanService::class)->simpan(['keamanan' => ['idle_timeout_menit' => 60]]);
        $token = $this->login('kasir@eklinik.test');

        $this->travel(45)->minutes();
        $this->api($token)->getJson('/api/me')->assertOk();

        User::where('email', 'kasir@eklinik.test')->update(['is_active' => false]);
        $this->api($token)->getJson('/api/me')->assertUnauthorized();
    }

    public function test_alur_2fa_aktifkan_login_dan_kode_pemulihan(): void
    {
        $totp = app(TwoFactorService::class);
        $token = $this->login();

        $secret = $this->api($token)->postJson('/api/me/2fa')->assertOk()
            ->assertJsonStructure(['secret', 'otpauth_url'])->json('secret');
        $this->api($token)->postJson('/api/me/2fa/konfirmasi', ['kode' => '000000'])->assertUnprocessable();
        $pemulihan = $this->api($token)->postJson('/api/me/2fa/konfirmasi', ['kode' => $totp->kode($secret, $totp->langkahSaatIni())])
            ->assertOk()->assertJsonCount(8, 'kode_pemulihan')->json('kode_pemulihan');
        $this->api($token)->getJson('/api/me')->assertJsonPath('two_factor.aktif', true);
        $this->assertTrue(AuditLog::where('aksi', '2fa_aktif')->exists());

        // Login kini butuh langkah kedua
        $this->travel(1)->minutes();
        $tantangan = $this->postJson('/api/login', ['email' => 'dokter@eklinik.test', 'password' => 'password'])
            ->assertOk()->assertJsonPath('two_factor', true)->assertJsonMissingPath('token')->json('tantangan');

        $this->postJson('/api/login/2fa', ['tantangan' => $tantangan, 'kode' => '123456'])
            ->assertUnprocessable()->assertJsonValidationErrors('kode');
        $this->postJson('/api/login/2fa', ['tantangan' => $tantangan, 'kode' => $totp->kode($secret, $totp->langkahSaatIni())])
            ->assertOk()->assertJsonStructure(['token', 'user']);
        // Tantangan sekali pakai
        $this->postJson('/api/login/2fa', ['tantangan' => $tantangan, 'kode' => $pemulihan[0]])
            ->assertUnprocessable()->assertJsonValidationErrors('tantangan');

        // Kode pemulihan berlaku sekali
        $this->travel(1)->minutes();
        $masuk = fn () => $this->postJson('/api/login', ['email' => 'dokter@eklinik.test', 'password' => 'password'])->json('tantangan');
        $this->postJson('/api/login/2fa', ['tantangan' => $masuk(), 'kode' => strtoupper($pemulihan[0])])->assertOk();
        $this->postJson('/api/login/2fa', ['tantangan' => $masuk(), 'kode' => $pemulihan[0]])->assertUnprocessable();

        // Nonaktifkan butuh password
        $token = $this->postJson('/api/login/2fa', ['tantangan' => $masuk(), 'kode' => $pemulihan[1]])->json('token');
        $this->api($token)->deleteJson('/api/me/2fa', ['password' => 'salah'])->assertUnprocessable();
        $this->api($token)->deleteJson('/api/me/2fa', ['password' => 'password'])->assertOk();
        $this->postJson('/api/login', ['email' => 'dokter@eklinik.test', 'password' => 'password'])->assertJsonStructure(['token']);
    }

    public function test_peran_wajib_2fa_diarahkan_ke_profil(): void
    {
        app(PengaturanService::class)->simpan(['keamanan' => ['wajib_2fa' => ['dokter']]]);
        $token = $this->login();

        $this->api($token)->getJson('/api/dashboard')->assertForbidden()->assertJsonPath('kode', 'wajib_2fa');
        $this->api($token)->getJson('/api/me')->assertOk()->assertJsonPath('two_factor.wajib', true);
        $this->api($token)->postJson('/api/me/2fa')->assertOk();

        // Peran lain tidak terpengaruh
        $this->api($this->login('kasir@eklinik.test'))->getJson('/api/dashboard')->assertOk();
    }

    public function test_ganti_password_mencabut_sesi_lain(): void
    {
        $lama = $this->login('kasir@eklinik.test');
        $saatIni = $this->login('kasir@eklinik.test');

        $this->api($saatIni)->putJson('/api/me/password', ['password_lama' => 'salah', 'password' => 'passwordbaru', 'password_confirmation' => 'passwordbaru'])
            ->assertUnprocessable()->assertJsonValidationErrors('password_lama');
        $this->api($saatIni)->putJson('/api/me/password', ['password_lama' => 'password', 'password' => 'passwordbaru', 'password_confirmation' => 'passwordbaru'])
            ->assertOk();

        $this->api($saatIni)->getJson('/api/me')->assertOk();
        $this->api($lama)->getJson('/api/me')->assertUnauthorized();
        $this->postJson('/api/login', ['email' => 'kasir@eklinik.test', 'password' => 'passwordbaru'])->assertOk();
        $this->assertTrue(AuditLog::where('aksi', 'ubah_password')->exists());
    }
}
