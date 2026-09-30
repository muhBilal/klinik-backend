<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Icd10;
use App\Models\Pasien;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * Audit log (PRD AD-03): perubahan data, akses rekam medis, login — append-only.
 */
class AuditLogTest extends TestCase
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

    public function test_perubahan_pasien_tercatat_dengan_nilai_lama_dan_baru(): void
    {
        $petugas = $this->as('pendaftaran@eklinik.test');

        $id = $this->postJson('/api/pasiens', ['nama' => 'Rina Audit', 'jenis_kelamin' => 'P', 'tanggal_lahir' => '1995-01-01'])
            ->assertCreated()->json('id');

        $buat = AuditLog::where(['tipe' => 'pasien', 'subjek_id' => $id, 'aksi' => 'buat'])->firstOrFail();
        $this->assertSame($petugas->id, $buat->user_id);
        $this->assertSame($id, $buat->pasien_id);
        $this->assertSame('Rina Audit', $buat->perubahan['nama']['baru']);
        $this->assertStringContainsString('Rina Audit', $buat->label);

        $this->putJson("/api/pasiens/{$id}", ['nama' => 'Rina Audit', 'jenis_kelamin' => 'P', 'tanggal_lahir' => '1995-01-01', 'no_hp' => '0812'])->assertOk();
        $ubah = AuditLog::where(['tipe' => 'pasien', 'subjek_id' => $id, 'aksi' => 'ubah'])->firstOrFail();
        $this->assertSame(['no_hp' => ['lama' => null, 'baru' => '0812']], $ubah->perubahan);

        // Simpan tanpa perubahan tidak menambah baris audit
        $this->putJson("/api/pasiens/{$id}", ['nama' => 'Rina Audit', 'jenis_kelamin' => 'P', 'tanggal_lahir' => '1995-01-01', 'no_hp' => '0812'])->assertOk();
        $this->assertSame(1, AuditLog::where(['tipe' => 'pasien', 'subjek_id' => $id, 'aksi' => 'ubah'])->count());
    }

    public function test_akses_dan_perubahan_rekam_medis_tercatat(): void
    {
        $pasien = Pasien::first();
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', [
            'pasien_id' => $pasien->id, 'poli_id' => User::where('email', 'dokter@eklinik.test')->value('poli_id'), 'penjamin' => 'umum',
        ])->json('id');

        // Front office membuka detail administrasi: bukan akses rekam medis
        $this->getJson("/api/kunjungans/{$id}")->assertOk();
        $this->assertSame(0, AuditLog::where(['aksi' => 'lihat', 'tipe' => 'kunjungan'])->count());

        $dokter = $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        [$j02, $j06] = [Icd10::where('kode', 'J02.9')->value('id'), Icd10::where('kode', 'J06.9')->value('id')];
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['asesmen' => 'Faringitis', 'diagnosas' => [['icd10_id' => $j02]]])->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['diagnosas' => [['icd10_id' => $j06]]])->assertOk();
        $this->getJson("/api/kunjungans/{$id}")->assertOk();

        $lihat = AuditLog::where(['aksi' => 'lihat', 'tipe' => 'kunjungan', 'subjek_id' => $id])->firstOrFail();
        $this->assertSame([$dokter->id, $pasien->id], [$lihat->user_id, $lihat->pasien_id]);

        // Diagnosa lama yang diganti tercatat sebagai hapus (replace-all per model, bukan query massal)
        $hapus = AuditLog::where(['aksi' => 'hapus', 'tipe' => 'pemeriksaan_diagnosa'])->firstOrFail();
        $this->assertSame($j02, $hapus->perubahan['icd10_id']['lama']);
        $this->assertSame($pasien->id, $hapus->pasien_id);
        $this->assertTrue(AuditLog::where(['aksi' => 'buat', 'tipe' => 'pemeriksaan', 'pasien_id' => $pasien->id])->exists());

        // Jejak per pasien lewat API
        $this->as('admin@eklinik.test');
        $jejak = $this->getJson("/api/audit-logs?pasien_id={$pasien->id}&aksi=lihat")->assertOk();
        $this->assertSame([$dokter->id], array_values(array_unique(array_column($jejak->json('data'), 'user_id'))));
    }

    public function test_login_tercatat_dan_password_tidak_pernah_dicatat(): void
    {
        $this->postJson('/api/login', ['email' => 'kasir@eklinik.test', 'password' => 'salah'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => 'kasir@eklinik.test', 'password' => 'password'])->assertOk();

        $kasir = User::where('email', 'kasir@eklinik.test')->first();
        $this->assertTrue(AuditLog::where(['aksi' => 'login_gagal', 'user_id' => $kasir->id])->exists());
        $this->assertTrue(AuditLog::where(['aksi' => 'login', 'user_id' => $kasir->id, 'cabang_id' => $kasir->cabang_id])->exists());

        $this->as('admin@eklinik.test');
        $id = $this->postJson('/api/users', ['name' => 'Baru', 'email' => 'baru@eklinik.test', 'password' => 'rahasia123', 'role' => 'kasir'])
            ->assertCreated()->json('id');
        $log = AuditLog::where(['tipe' => 'user', 'subjek_id' => $id, 'aksi' => 'buat'])->firstOrFail();
        $this->assertArrayNotHasKey('password', $log->perubahan);
        $this->assertSame('kasir', $log->perubahan['role']['baru']);
    }

    public function test_audit_log_tidak_bisa_diubah_dan_hanya_untuk_izin_audit(): void
    {
        $log = AuditLog::firstOrFail();

        try {
            $log->update(['aksi' => 'palsu']);
            $this->fail('Audit log seharusnya tidak bisa diubah.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_endpoint_audit_dibatasi_izin(): void
    {
        $this->as('kasir@eklinik.test');
        $this->getJson('/api/audit-logs')->assertForbidden();

        $this->as('manajer@eklinik.test');
        $response = $this->getJson('/api/audit-logs?tipe=pasien&aksi=buat')->assertOk()->assertJsonStructure(['data' => [['id', 'aksi', 'tipe', 'label', 'user', 'created_at']]]);
        $this->assertSame(Pasien::count(), $response->json('total'));
        $this->getJson('/api/audit-logs/'.$response->json('data.0.id'))->assertOk()->assertJsonStructure(['perubahan']);
    }

    public function test_hapus_pasien_adalah_soft_delete(): void
    {
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/pasiens', ['nama' => 'Hapus Saya', 'jenis_kelamin' => 'L', 'tanggal_lahir' => '2000-01-01'])->json('id');

        $this->as('admin@eklinik.test');
        $this->deleteJson("/api/pasiens/{$id}")->assertOk();

        $this->assertNull(Pasien::find($id));
        $this->assertNotNull(Pasien::withTrashed()->find($id)?->deleted_at);
        $this->assertTrue(AuditLog::where(['aksi' => 'hapus', 'tipe' => 'pasien', 'subjek_id' => $id])->exists());
        $this->getJson("/api/pasiens/{$id}")->assertNotFound();
    }
}
