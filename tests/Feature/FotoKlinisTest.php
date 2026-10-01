<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Berkas;
use App\Models\Kunjungan;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\ProtokolFoto;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuatBerkasUji;
use Tests\TestCase;

/**
 * Foto klinis terstruktur (PRD FT-01, FT-02, FT-04, RM-04): protokol posisi, metadata before-after, thumbnail terenkripsi,
 * tautan galeri, dan consent foto bertingkat.
 */
class FotoKlinisTest extends TestCase
{
    use BuatBerkasUji, RefreshDatabase;

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

    private function protokol(string $nama): ProtokolFoto
    {
        return ProtokolFoto::where('nama', $nama)->firstOrFail();
    }

    /** Kunjungan Poli Estetika yang sudah dipanggil dokter + satu tindakan botox. @return array{0: int, 1: int} */
    private function kunjunganBotox(?Pasien $pasien = null): array
    {
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => ($pasien ?? $this->pasien)->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');

        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $ktId = $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['tindakans' => [['tindakan_id' => Tindakan::where('kode', 'TRT-001')->value('id')]]])
            ->assertOk()->json('tindakans.0.id');

        return [$id, $ktId];
    }

    private function setujuiFoto(string $tingkat = 'klinis', ?Pasien $pasien = null): TestResponse
    {
        $pasien ??= $this->pasien;

        return $this->postJson("/api/pasiens/{$pasien->id}/persetujuan-foto", [
            'tingkat' => $tingkat, 'penandatangan_nama' => $pasien->nama, 'hubungan' => 'pasien', 'ttd' => $this->ttd(),
        ]);
    }

    private function unggahFoto(array $data = [], string $isi = 'foto'): TestResponse
    {
        return $this->post('/api/berkas', [
            'file' => UploadedFile::fake()->createWithContent('depan.jpg', self::jpeg().$isi),
            'thumbnail' => UploadedFile::fake()->createWithContent('depan-thumb.jpg', self::jpeg()),
            'kategori' => 'foto_klinis',
            'pasien_id' => $this->pasien->id,
            ...$data,
        ], ['Accept' => 'application/json']);
    }

    public function test_protokol_foto_bawaan_dan_kelola(): void
    {
        $this->as('dokter@eklinik.test');
        $wajah = collect($this->getJson('/api/protokol-fotos?aktif=1')->assertOk()->json())->firstWhere('nama', 'Wajah standar');
        $this->assertSame(['depan', 'kanan_45', 'kiri_45', 'kanan_90', 'kiri_90'], array_column($wajah['posisi'], 'kode'));
        $this->postJson('/api/protokol-fotos', ['nama' => 'X', 'posisi' => [['label' => 'A']]])->assertForbidden();

        $this->as('admin@eklinik.test');
        // Kode posisi dibentuk dari label bila kosong
        $this->postJson('/api/protokol-fotos', ['nama' => 'Leher', 'posisi' => [['label' => 'Depan leher'], ['label' => 'Samping', 'kode' => 'samping']]])
            ->assertCreated()->assertJsonPath('posisi.0.kode', 'depan_leher')->assertJsonPath('posisi.1.kode', 'samping');
        $this->postJson('/api/protokol-fotos', ['nama' => 'Dobel', 'posisi' => [['label' => 'Depan'], ['label' => 'depan']]])
            ->assertUnprocessable()->assertJsonValidationErrors('posisi.1.kode');

        // Protokol yang dipasang ke treatment tidak bisa dihapus
        $this->deleteJson('/api/protokol-fotos/'.$this->protokol('Wajah standar')->id)->assertUnprocessable();
        $this->assertSame($this->protokol('Gigi intraoral')->id, Tindakan::where('kode', 'TND-101')->value('protokol_foto_id'));
    }

    public function test_persetujuan_foto_bertingkat_diganti_dan_dicabut(): void
    {
        $url = "/api/pasiens/{$this->pasien->id}/persetujuan-foto";

        $this->as('kasir@eklinik.test');
        $this->setujuiFoto()->assertForbidden();

        // Front office mengambil persetujuan (izin pasien.kelola)
        $this->as('pendaftaran@eklinik.test');
        $isi = $this->getJson("{$url}/pratinjau?tingkat=edukasi")->assertOk()->json('isi');
        $this->assertStringContainsString($this->pasien->nama, $isi);
        $this->assertStringContainsString('[x] Klinis & edukasi', $isi);
        $this->assertStringContainsString('[ ] Klinis, edukasi & marketing', $isi);

        $pertama = $this->setujuiFoto('edukasi')->assertCreated()
            ->assertJsonPath('status', 'berlaku')->assertJsonPath('tingkat', 'edukasi')->assertJsonMissingPath('ttd')->json('uuid');
        $this->setujuiFoto('bebas')->assertUnprocessable()->assertJsonValidationErrors('tingkat');

        // Persetujuan baru menggantikan yang lama (satu yang berlaku)
        $kedua = $this->setujuiFoto('marketing')->assertCreated()->json('uuid');
        $status = $this->getJson($url)->assertOk()
            ->assertJsonPath('aktif.uuid', $kedua)->assertJsonPath('aktif.tingkat', 'marketing')
            ->assertJsonCount(2, 'riwayat')->assertJsonCount(3, 'tingkat');
        $this->assertSame('diganti', collect($status->json('riwayat'))->firstWhere('uuid', $pertama)['status']);

        $this->assertStringNotContainsString('data:image/png', DB::table('persetujuan_fotos')->where('uuid', $kedua)->value('ttd'));
        $this->getJson("/api/persetujuan-fotos/{$kedua}")->assertOk()->assertJsonPath('checksum_valid', true)
            ->assertJsonPath('pasien.no_rm', $this->pasien->no_rm);
        $this->assertStringStartsWith('data:image/png', $this->getJson("/api/persetujuan-fotos/{$kedua}")->json('ttd'));
        $this->assertTrue(AuditLog::where('aksi', 'lihat')->where('tipe', 'persetujuan_foto')->exists());

        $this->postJson("/api/persetujuan-fotos/{$kedua}/cabut", ['alasan' => 'Tidak ingin difoto'])->assertOk()->assertJsonPath('status', 'dicabut');
        $this->getJson($url)->assertJsonPath('aktif', null);
        $this->postJson("/api/persetujuan-fotos/{$kedua}/cabut", ['alasan' => 'lagi'])->assertUnprocessable();
    }

    public function test_foto_klinis_butuh_persetujuan_dan_menyimpan_metadata(): void
    {
        [$kunjunganId, $ktId] = $this->kunjunganBotox();
        $protokol = $this->protokol('Wajah standar');
        $meta = ['kunjungan_id' => $kunjunganId, 'kunjungan_tindakan_id' => $ktId, 'protokol_foto_id' => $protokol->id,
            'posisi' => 'depan', 'tahap' => 'sebelum', 'lebar' => 1920, 'tinggi' => 1080];

        $this->unggahFoto($meta)->assertUnprocessable()->assertJsonValidationErrors('consent_foto');

        $this->as('perawat@eklinik.test');
        $this->setujuiFoto()->assertCreated();

        $foto = $this->unggahFoto($meta)->assertCreated()
            ->assertJsonPath('protokol.nama', 'Wajah standar')
            ->assertJsonPath('posisi', 'depan')
            ->assertJsonPath('tahap', 'sebelum')
            ->assertJsonPath('kunjungan_tindakan_id', $ktId)
            ->assertJsonPath('kunjungan.id', $kunjunganId)
            ->assertJsonPath('ada_thumbnail', true)
            ->assertJsonMissingPath('thumbnail_path');
        $this->assertNotNull($foto->json('diambil_at'));

        // Thumbnail ikut terenkripsi di disk
        $berkas = Berkas::where('uuid', $foto->json('uuid'))->first();
        Storage::disk('berkas')->assertExists($berkas->thumbnail_path);
        $this->assertStringNotContainsString("\xFF\xD8\xFF", Storage::disk('berkas')->get($berkas->thumbnail_path));

        $this->unggahFoto([...$meta, 'posisi' => 'atas_kepala'])->assertUnprocessable()->assertJsonValidationErrors('posisi');
        [, $ktLain] = $this->kunjunganBotox(Pasien::skip(1)->first());
        $this->as('perawat@eklinik.test');
        $this->unggahFoto([...$meta, 'kunjungan_tindakan_id' => $ktLain])->assertUnprocessable()->assertJsonValidationErrors('kunjungan_tindakan_id');

        // Filter galeri
        $this->getJson("/api/berkas?pasien_id={$this->pasien->id}&kategori=foto_klinis&posisi=depan&tahap=sebelum")->assertOk()->assertJsonCount(1);
        $this->getJson("/api/berkas?pasien_id={$this->pasien->id}&tahap=sesudah")->assertJsonCount(0);

        // Dicabut: foto baru ditolak, lampiran non-foto tetap boleh; pengaturan dimatikan = boleh
        $uuid = $this->getJson("/api/pasiens/{$this->pasien->id}/persetujuan-foto")->json('aktif.uuid');
        $this->postJson("/api/persetujuan-fotos/{$uuid}/cabut", ['alasan' => 'Berubah pikiran'])->assertOk();
        $this->unggahFoto($meta)->assertUnprocessable()->assertJsonValidationErrors('consent_foto');
        $this->unggahFoto(['kategori' => 'hasil_penunjang'])->assertCreated()->assertJsonPath('posisi', null);

        app(PengaturanService::class)->simpan(['foto' => ['wajib_consent' => false]]);
        $this->unggahFoto($meta)->assertCreated();
    }

    public function test_tautan_galeri_memakai_thumbnail_dan_tercatat(): void
    {
        [$kunjunganId] = $this->kunjunganBotox();
        $this->setujuiFoto()->assertCreated();
        $protokol = $this->protokol('Wajah standar');

        $uuids = collect(['depan', 'kanan_45'])->map(fn ($posisi) => $this->unggahFoto([
            'kunjungan_id' => $kunjunganId, 'protokol_foto_id' => $protokol->id, 'posisi' => $posisi, 'tahap' => 'sebelum',
        ], "foto-{$posisi}")->assertCreated()->json('uuid'))->all();

        $aksesAwal = AuditLog::where('aksi', 'akses_berkas')->count();
        $tautan = $this->postJson('/api/berkas/tautan', ['uuids' => $uuids, 'pratinjau' => true])->assertOk()->assertJsonCount(2)->json();
        $this->assertSame($aksesAwal + 2, AuditLog::where('aksi', 'akses_berkas')->count());
        $this->assertStringContainsString('(pratinjau)', AuditLog::where('aksi', 'akses_berkas')->latest('id')->value('label'));

        // Tautan pratinjau berisi thumbnail; menghapus `t` merusak tanda tangan URL
        $url = $tautan[0]['url'];
        $this->assertStringContainsString('t=1', $url);
        $isi = $this->get($url)->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->assertStringContainsString('no-store', $isi->headers->get('Cache-Control'));
        $this->assertSame(self::jpeg(), $isi->getContent());
        $this->get(preg_replace('/([?&])t=1&?/', '$1', $url))->assertForbidden();

        // Tautan foto penuh berisi foto asli dan tercatat unduh
        $penuh = $this->postJson('/api/berkas/tautan', ['uuids' => [$uuids[0]]])->json('0.url');
        $this->assertStringNotContainsString('t=1', $penuh);
        $this->assertStringEndsWith('foto-depan', $this->get($penuh)->assertOk()->getContent());
        $this->assertTrue(AuditLog::where('aksi', 'unduh_berkas')->exists());

        // Foto kunjungan berakses terbatas dilewati untuk dokter lain
        DB::table('kunjungans')->where('id', $kunjunganId)->update(['akses_terbatas' => true]);
        Kunjungan::withoutGlobalScopes()->whereKey($kunjunganId)->update(['status' => 'selesai']);
        Sanctum::actingAs(User::factory()->create(['role' => 'dokter', 'poli_id' => Poli::where('kode', 'KULIT')->value('id'),
            'cabang_id' => Kunjungan::withoutGlobalScopes()->find($kunjunganId)->cabang_id]));
        $this->postJson('/api/berkas/tautan', ['uuids' => $uuids, 'pratinjau' => true])->assertOk()->assertJsonCount(0);
    }
}
