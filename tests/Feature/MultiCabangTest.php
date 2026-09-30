<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\Icd10;
use App\Models\Pasien;
use App\Models\Tagihan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Multi-cabang (PRD AD-01): transaksi terpisah per cabang, pasien & riwayat lintas cabang.
 */
class MultiCabangTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $utama;

    private Cabang $selatan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->utama = Cabang::where('kode', 'UTAMA')->first();
        $this->selatan = Cabang::create(['kode' => 'SEL', 'nama' => 'Cabang Selatan']);

        $poliUmum = User::where('email', 'dokter@eklinik.test')->value('poli_id');
        User::factory()->create(['email' => 'pendaftaran.sel@eklinik.test', 'role' => 'pendaftaran', 'cabang_id' => $this->selatan->id]);
        User::factory()->create(['email' => 'dokter.sel@eklinik.test', 'role' => 'dokter', 'poli_id' => $poliUmum, 'cabang_id' => $this->selatan->id]);
        User::factory()->create(['email' => 'kasir.sel@eklinik.test', 'role' => 'kasir', 'cabang_id' => $this->selatan->id]);
    }

    private function as(string $email, ?int $cabangHeader = null): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);
        $this->withHeaders($cabangHeader ? ['X-Cabang-Id' => (string) $cabangHeader] : ['X-Cabang-Id' => '']);

        return $user;
    }

    private function daftar(string $email, Pasien $pasien, array $extra = []): TestResponse
    {
        $this->as($email);

        return $this->postJson('/api/kunjungans', [
            'pasien_id' => $pasien->id,
            'poli_id' => User::where('email', 'dokter@eklinik.test')->value('poli_id'),
            'penjamin' => 'umum',
            ...$extra,
        ]);
    }

    public function test_antrian_dan_daftar_kunjungan_terpisah_per_cabang(): void
    {
        [$p1, $p2, $p3] = Pasien::take(3)->get();

        $a = $this->daftar('pendaftaran@eklinik.test', $p1)->assertCreated()
            ->assertJsonPath('no_antrian', 1)->assertJsonPath('cabang.kode', 'UTAMA')->json('id');
        $this->daftar('pendaftaran@eklinik.test', $p2)->assertJsonPath('no_antrian', 2);
        $s = $this->daftar('pendaftaran.sel@eklinik.test', $p3)->assertCreated()
            ->assertJsonPath('no_antrian', 1)->assertJsonPath('cabang.kode', 'SEL')->json('id');

        // Pasien yang sama boleh terdaftar di poli yang sama pada cabang berbeda
        $this->daftar('pendaftaran.sel@eklinik.test', $p1)->assertCreated()->assertJsonPath('no_antrian', 2);

        $this->as('pendaftaran.sel@eklinik.test');
        $this->assertEqualsCanonicalizing([$s, $s + 1], array_column($this->getJson('/api/kunjungans')->json('data'), 'id'));
        $this->postJson("/api/kunjungans/{$a}/batal")->assertNotFound();

        // User terikat cabang tidak bisa pindah cabang lewat header
        $this->as('pendaftaran.sel@eklinik.test', $this->utama->id);
        $this->assertNotContains($a, array_column($this->getJson('/api/kunjungans')->json('data'), 'id'));

        // Admin lintas cabang: semua cabang, atau satu cabang lewat header
        $this->as('admin@eklinik.test');
        $this->getJson('/api/kunjungans')->assertJsonCount(4, 'data');
        $this->as('admin@eklinik.test', $this->utama->id);
        $this->getJson('/api/kunjungans')->assertJsonCount(2, 'data');
    }

    public function test_data_baru_wajib_cabang_bila_klinik_punya_banyak_cabang(): void
    {
        $pasien = Pasien::first();

        $this->daftar('admin@eklinik.test', $pasien)->assertUnprocessable()->assertJsonValidationErrors('cabang');

        $this->as('admin@eklinik.test', $this->selatan->id);
        $this->postJson('/api/kunjungans', [
            'pasien_id' => $pasien->id, 'poli_id' => User::where('email', 'dokter@eklinik.test')->value('poli_id'), 'penjamin' => 'umum',
        ])->assertCreated()->assertJsonPath('cabang_id', $this->selatan->id);
    }

    public function test_dokter_harus_bertugas_di_cabang_pendaftaran(): void
    {
        $dokterSel = User::where('email', 'dokter.sel@eklinik.test')->first();

        $this->daftar('pendaftaran@eklinik.test', Pasien::first(), ['dokter_id' => $dokterSel->id])
            ->assertUnprocessable()->assertJsonValidationErrors('dokter_id');

        $this->as('pendaftaran@eklinik.test');
        $ids = array_column($this->getJson('/api/dokters')->json(), 'id');
        $this->assertNotContains($dokterSel->id, $ids);
        $this->assertContains(User::where('email', 'dokter@eklinik.test')->value('id'), $ids);
    }

    public function test_tagihan_ikut_cabang_kunjungan_dan_riwayat_lintas_cabang(): void
    {
        $pasien = Pasien::first();
        $id = $this->daftar('pendaftaran@eklinik.test', $pasien)->json('id');

        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['diagnosas' => [['icd10_id' => Icd10::first()->id]]])->assertOk();
        $tagihanId = $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk()->json('tagihan.id');

        $this->assertSame($this->utama->id, Tagihan::withoutGlobalScopes()->find($tagihanId)->cabang_id);

        // Kasir cabang lain tidak melihat tagihan ini
        $this->as('kasir.sel@eklinik.test');
        $this->getJson('/api/tagihans')->assertJsonCount(0, 'data');
        $this->getJson("/api/tagihans/{$tagihanId}")->assertNotFound();

        // Dokter cabang lain tetap melihat riwayat & detail rekam medis pasien (master pasien pusat)
        $this->as('dokter.sel@eklinik.test');
        $this->getJson("/api/pasiens/{$pasien->id}/riwayat")->assertOk()
            ->assertJsonPath('0.id', $id)->assertJsonPath('0.cabang.kode', 'UTAMA');
        $this->getJson("/api/kunjungans/{$id}")->assertOk()->assertJsonPath('cabang.kode', 'UTAMA');
        // ...tetapi tidak bisa mengubahnya
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['objektif' => 'x'])->assertNotFound();
    }

    public function test_pemilih_cabang_dan_master_cabang(): void
    {
        $this->as('admin@eklinik.test');
        $this->assertEqualsCanonicalizing(['UTAMA', 'SEL'], array_column($this->getJson('/api/me')->json('cabangs'), 'kode'));

        $this->postJson('/api/cabangs', ['kode' => 'utr-1', 'nama' => 'Cabang Utara', 'jam_buka' => '09:00', 'jam_tutup' => '08:00'])
            ->assertUnprocessable()->assertJsonValidationErrors('jam_tutup');
        $this->postJson('/api/cabangs', ['kode' => 'utr-1', 'nama' => 'Cabang Utara', 'jam_buka' => '09:00', 'jam_tutup' => '20:00'])
            ->assertCreated()->assertJsonPath('kode', 'UTR-1')->assertJsonPath('jam_buka', '09:00');
        $this->deleteJson("/api/cabangs/{$this->selatan->id}")->assertUnprocessable();

        // Staf hanya melihat cabangnya sendiri
        $this->as('pendaftaran.sel@eklinik.test');
        $this->getJson('/api/me')->assertJsonCount(1, 'cabangs')->assertJsonPath('cabangs.0.kode', 'SEL');
        $this->getJson('/api/cabangs')->assertOk()->assertJsonCount(1);
        $this->postJson('/api/cabangs', ['kode' => 'X', 'nama' => 'X'])->assertForbidden();
    }
}
