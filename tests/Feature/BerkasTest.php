<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Berkas;
use App\Models\Pasien;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Berkas klinis terenkripsi (PRD FT-03, 7.2 Enkripsi): tersimpan terenkripsi, dibuka lewat tautan
 * bertanda tangan yang kedaluwarsa, setiap akses tercatat.
 */
class BerkasTest extends TestCase
{
    use RefreshDatabase;

    /** JPEG 1x1 piksel (image/jpeg terdeteksi dari isinya; GD tidak terpasang di image Docker). */
    private const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    private Pasien $pasien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Storage::fake('berkas');
        $this->pasien = Pasien::first();
    }

    private function as(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function kunjungan(Pasien $pasien): int
    {
        $this->as('pendaftaran@eklinik.test');

        return $this->postJson('/api/kunjungans', [
            'pasien_id' => $pasien->id, 'poli_id' => User::where('email', 'dokter@eklinik.test')->value('poli_id'), 'penjamin' => 'umum',
        ])->assertCreated()->json('id');
    }

    private function unggah(array $data = []): TestResponse
    {
        return $this->post('/api/berkas', [
            'file' => UploadedFile::fake()->createWithContent('wajah-depan.jpg', base64_decode(self::JPEG)),
            'kategori' => 'foto_klinis',
            'pasien_id' => $this->pasien->id,
            ...$data,
        ], ['Accept' => 'application/json']);
    }

    public function test_berkas_tersimpan_terenkripsi_dan_bisa_dibuka_lewat_tautan(): void
    {
        $kunjunganId = $this->kunjungan($this->pasien);
        $perawat = $this->as('perawat@eklinik.test');

        $uuid = $this->unggah(['kunjungan_id' => $kunjunganId, 'keterangan' => 'Sebelum tindakan'])
            ->assertCreated()
            ->assertJsonPath('kategori', 'foto_klinis')
            ->assertJsonPath('mime', 'image/jpeg')
            ->assertJsonPath('pengunggah.id', $perawat->id)
            ->assertJsonMissingPath('path')
            ->assertJsonMissingPath('checksum')
            ->json('uuid');

        $berkas = Berkas::where('uuid', $uuid)->firstOrFail();
        $tersimpan = Storage::disk('berkas')->get($berkas->path);
        $asli = Crypt::decryptString($tersimpan);
        $this->assertStringStartsWith("\xFF\xD8", $asli, 'Isi asli adalah JPEG');
        $this->assertStringNotContainsString("\xFF\xD8\xFF", $tersimpan, 'Isi di disk harus terenkripsi');

        $this->getJson("/api/berkas?kunjungan_id={$kunjunganId}")->assertOk()->assertJsonCount(1)->assertJsonPath('0.uuid', $uuid);

        $url = $this->getJson("/api/berkas/{$uuid}/tautan")->assertOk()->assertJsonStructure(['url', 'kedaluwarsa'])->json('url');

        $unduh = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', $unduh->headers->get('Cache-Control'));
        $this->assertSame($asli, $unduh->getContent());

        $this->get($url.'x')->assertForbidden();
        $this->travel(6)->minutes();
        $this->get($url)->assertForbidden();

        $this->assertTrue(AuditLog::where(['aksi' => 'akses_berkas', 'user_id' => $perawat->id, 'pasien_id' => $this->pasien->id])->exists());
        $this->assertSame(1, AuditLog::where(['aksi' => 'unduh_berkas', 'user_id' => $perawat->id])->count());
    }

    public function test_hak_akses_dan_validasi_unggah(): void
    {
        $this->as('pendaftaran@eklinik.test');
        $this->unggah()->assertForbidden();
        $this->getJson("/api/berkas?pasien_id={$this->pasien->id}")->assertForbidden();

        $this->as('dokter@eklinik.test');
        $this->unggah(['file' => UploadedFile::fake()->create('catatan.txt', 1, 'text/plain')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->unggah(['kategori' => 'rahasia'])->assertUnprocessable()->assertJsonValidationErrors('kategori');

        // Kunjungan milik pasien lain ditolak
        $lain = Pasien::whereKeyNot($this->pasien->id)->first();
        $kunjunganLain = $this->kunjungan($lain);
        $this->as('dokter@eklinik.test');
        $this->unggah(['kunjungan_id' => $kunjunganLain])->assertUnprocessable()->assertJsonValidationErrors('kunjungan_id');

        $this->getJson('/api/berkas')->assertUnprocessable();
    }

    public function test_hapus_berkas_adalah_soft_delete(): void
    {
        $this->as('dokter@eklinik.test');
        $uuid = $this->unggah()->assertCreated()->json('uuid');

        $this->deleteJson("/api/berkas/{$uuid}")->assertOk();
        $this->getJson("/api/berkas?pasien_id={$this->pasien->id}")->assertJsonCount(0);

        $berkas = Berkas::withTrashed()->where('uuid', $uuid)->first();
        $this->assertNotNull($berkas->deleted_at);
        Storage::disk('berkas')->assertExists($berkas->path);
        $this->getJson("/api/berkas/{$uuid}/tautan")->assertNotFound();
    }
}
