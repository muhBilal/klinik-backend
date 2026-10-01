<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Enkripsi ulang nilai terenkripsi setelah rotasi APP_KEY (PRD v2 7.2 keamanan).
 */
class EnkripsiUlangTest extends TestCase
{
    use RefreshDatabase;

    public function test_re_wrap_mengganti_ciphertext_tanpa_mengubah_plaintext(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::first();
        $user->forceFill(['two_factor_secret' => 'RAHASIA-TOTP-123'])->save();

        $sebelum = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertSame('RAHASIA-TOTP-123', Crypt::decryptString($sebelum));

        $this->artisan('eklinik:enkripsi-ulang')->assertExitCode(0);

        $sesudah = DB::table('users')->where('id', $user->id)->value('two_factor_secret');
        $this->assertNotSame($sebelum, $sesudah, 'ciphertext harus berganti (IV/kunci baru)');
        $this->assertSame('RAHASIA-TOTP-123', Crypt::decryptString($sesudah), 'plaintext harus tetap sama');
    }

    public function test_mode_dry_tidak_menulis(): void
    {
        $this->seed(DatabaseSeeder::class);
        $user = User::first();
        $user->forceFill(['two_factor_secret' => 'JANGAN-DIUBAH'])->save();
        $sebelum = DB::table('users')->where('id', $user->id)->value('two_factor_secret');

        $this->artisan('eklinik:enkripsi-ulang --dry')->assertExitCode(0);

        $this->assertSame($sebelum, DB::table('users')->where('id', $user->id)->value('two_factor_secret'));
    }
}
