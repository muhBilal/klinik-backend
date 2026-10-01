<?php

namespace Tests\Feature;

use App\Models\Icd10;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\Peran;
use App\Models\Poli;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * RBAC dinamis (PRD AD-02): hak akses ditentukan izin peran, bukan kode peran.
 */
class PeranIzinTest extends TestCase
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

    private function kunjunganBaru(): int
    {
        $dokter = User::where('email', 'dokter@eklinik.test')->first();
        $this->as('pendaftaran@eklinik.test');

        return $this->postJson('/api/kunjungans', [
            'pasien_id' => Pasien::first()->id, 'poli_id' => $dokter->poli_id, 'penjamin' => 'umum',
        ])->assertCreated()->json('id');
    }

    public function test_me_berisi_izin_dan_nama_peran(): void
    {
        $this->as('kasir@eklinik.test');

        $this->getJson('/api/me')->assertOk()
            ->assertJsonPath('role', 'kasir')
            ->assertJsonPath('role_label', 'Kasir')
            // pasien.lihat: kasir menjual paket ke pasien tertentu (F1-08)
            ->assertJsonPath('izin', ['kasir.shift', 'kasir.tagihan', 'laporan.keuangan', 'pasien.lihat'])
            ->assertJsonPath('two_factor.aktif', false)
            ->assertJsonMissingPath('peran');

        $this->as('admin@eklinik.test');
        $this->assertContains('audit.lihat', $this->getJson('/api/me')->json('izin'));
    }

    public function test_peran_kustom_mengatur_hak_akses(): void
    {
        $this->as('admin@eklinik.test');

        $this->getJson('/api/izins')->assertOk()->assertJsonStructure([['grup', 'izin' => [['kode', 'label']]]]);

        $this->postJson('/api/perans', [
            'kode' => 'kasir_farmasi', 'nama' => 'Kasir Farmasi', 'izin' => ['kasir.tagihan', 'farmasi.resep'],
        ])->assertCreated()->assertJsonPath('izin', ['farmasi.resep', 'kasir.tagihan']);

        $this->postJson('/api/perans', ['kode' => 'Salah Kode', 'nama' => 'x', 'izin' => ['tidak.ada']])
            ->assertUnprocessable()->assertJsonValidationErrors(['kode', 'izin.0']);

        $user = User::factory()->create(['role' => 'kasir_farmasi', 'cabang_id' => User::where('email', 'kasir@eklinik.test')->value('cabang_id')]);
        Sanctum::actingAs($user);
        $this->getJson('/api/tagihans')->assertOk();
        $this->getJson('/api/reseps')->assertOk();
        $this->getJson('/api/obats/'.Obat::value('id').'/mutasi')->assertForbidden();

        // Izin dicabut -> akses hilang
        $this->as('admin@eklinik.test');
        $peran = Peran::where('kode', 'kasir_farmasi')->first();
        $this->putJson("/api/perans/{$peran->id}", ['kode' => 'kasir_farmasi', 'nama' => 'Kasir Farmasi', 'izin' => ['kasir.tagihan']])->assertOk();

        Sanctum::actingAs($user->fresh());
        $this->getJson('/api/reseps')->assertForbidden();
    }

    public function test_peran_sistem_dan_peran_terpakai_dilindungi(): void
    {
        $this->as('admin@eklinik.test');
        $admin = Peran::where('kode', 'admin')->first();
        $dokter = Peran::where('kode', 'dokter')->first();
        $marketing = Peran::where('kode', 'marketing')->first();

        $this->deleteJson("/api/perans/{$dokter->id}")->assertUnprocessable();

        // Kode peran sistem tetap, izin administrator tidak berubah
        $this->putJson("/api/perans/{$dokter->id}", ['kode' => 'dokter_baru', 'nama' => 'Dokter Umum', 'izin' => ['pemeriksaan.dokter']])->assertOk()
            ->assertJsonPath('kode', 'dokter')->assertJsonPath('nama', 'Dokter Umum');
        $this->putJson("/api/perans/{$admin->id}", ['nama' => 'Admin', 'izin' => []])->assertOk();
        $this->assertTrue(User::where('email', 'admin@eklinik.test')->first()->punyaIzin('audit.lihat'));

        // Peran kustom yang masih dipakai tidak bisa dihapus; yang tidak dipakai bisa
        User::factory()->create(['role' => 'marketing']);
        $this->deleteJson("/api/perans/{$marketing->id}")->assertUnprocessable();
        $this->postJson('/api/perans', ['kode' => 'sementara', 'nama' => 'Sementara', 'izin' => []])->assertCreated();
        $this->deleteJson('/api/perans/'.Peran::where('kode', 'sementara')->value('id'))->assertOk();

        $this->as('kasir@eklinik.test');
        $this->getJson('/api/perans')->assertForbidden();
    }

    public function test_dokter_ditentukan_izin_pemeriksaan_dokter(): void
    {
        $this->as('admin@eklinik.test');
        $this->postJson('/api/perans', ['kode' => 'dokter_estetika', 'nama' => 'Dokter Estetika',
            'izin' => ['pasien.lihat', 'pemeriksaan.panggil', 'pemeriksaan.dokter', 'rme.lihat']])->assertCreated();

        // Poli wajib untuk peran berizin pemeriksaan.dokter
        $payload = ['name' => 'dr. Estetika', 'email' => 'estetika@eklinik.test', 'password' => 'password123', 'role' => 'dokter_estetika'];
        $this->postJson('/api/users', $payload)->assertUnprocessable()->assertJsonValidationErrors('poli_id');
        $this->postJson('/api/users', [...$payload, 'poli_id' => Poli::value('id')])->assertCreated();

        $dokters = collect($this->getJson('/api/dokters')->assertOk()->json());
        $this->assertContains('estetika@eklinik.test', User::whereIn('id', $dokters->pluck('id'))->pluck('email'));
        $this->assertNotContains('admin@eklinik.test', User::whereIn('id', $dokters->pluck('id'))->pluck('email'));
    }

    public function test_tanpa_izin_dokter_hanya_tanda_vital_yang_tersimpan(): void
    {
        $id = $this->kunjunganBaru();

        // Terapis: izin pemeriksaan.vital tanpa pemeriksaan.dokter -> diperlakukan seperti perawat
        $this->as('terapis@eklinik.test');
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'tekanan_darah' => '110/70', 'subjektif' => 'Konsultasi jerawat', 'asesmen' => 'tidak boleh tersimpan',
            'diagnosas' => [['icd10_id' => Icd10::first()->id]],
        ])->assertOk()
            ->assertJsonPath('pemeriksaan.tekanan_darah', '110/70')
            ->assertJsonPath('pemeriksaan.asesmen', null)
            ->assertJsonCount(0, 'pemeriksaan.diagnosas');
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertForbidden();
    }

    public function test_rekam_medis_hanya_untuk_izin_rme_lihat(): void
    {
        $id = $this->kunjunganBaru();

        // Pendaftaran (front office): data administrasi saja
        $this->getJson("/api/kunjungans/{$id}")->assertOk()
            ->assertJsonPath('id', $id)
            ->assertJsonMissingPath('pemeriksaan')
            ->assertJsonMissingPath('tindakans');
        $pasienId = Pasien::first()->id;
        $this->getJson("/api/pasiens/{$pasienId}")->assertOk()->assertJsonMissingPath('kunjungans.0.pemeriksaan');
        $this->getJson("/api/pasiens/{$pasienId}/riwayat")->assertForbidden();

        // Dokter: rekam medis lengkap
        $this->as('dokter@eklinik.test');
        $this->getJson("/api/kunjungans/{$id}")->assertOk()->assertJsonStructure(['pemeriksaan', 'tindakans']);
    }
}
