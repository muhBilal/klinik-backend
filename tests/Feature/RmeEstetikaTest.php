<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Icd10;
use App\Models\Icd9cm;
use App\Models\KodeFavorit;
use App\Models\Kunjungan;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\Pemeriksaan;
use App\Models\PemeriksaanAddendum;
use App\Models\Peran;
use App\Models\Poli;
use App\Models\StokBatch;
use App\Models\SumberDaya;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\Concerns\BuatBerkasUji;
use Tests\TestCase;

/**
 * RME estetika: template SOAP (RM-01, DR-03), ICD-9-CM & favorit (RM-02), informed consent (RM-03),
 * catatan tindakan & face chart & parameter laser (RM-05, ES-01, ES-02), tanda tangan & addendum (RM-07),
 * akses terbatas kasus IMS (DR-03).
 */
class RmeEstetikaTest extends TestCase
{
    use BuatBerkasUji, RefreshDatabase;

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

    /** Kunjungan baru di poli tertentu yang sudah dipanggil dokter demo (dr. Andi). */
    private function kunjunganDiperiksa(string $poli = 'ESTETIKA', ?Pasien $pasien = null): int
    {
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', [
            'pasien_id' => ($pasien ?? Pasien::first())->id,
            'poli_id' => Poli::where('kode', $poli)->value('id'),
            'penjamin' => 'umum',
        ])->assertCreated()->json('id');

        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();

        return $id;
    }

    private function icd10(string $kode): int
    {
        return Icd10::where('kode', $kode)->value('id');
    }

    private function tindakan(string $kode): Tindakan
    {
        return Tindakan::where('kode', $kode)->firstOrFail();
    }

    private function tanpaWajibConsent(): void
    {
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);
    }

    public function test_template_soap_per_poli_dan_treatment(): void
    {
        $kulit = Poli::where('kode', 'KULIT')->value('id');

        $this->as('admin@eklinik.test');
        $this->postJson('/api/template-soaps', ['nama' => 'Kontrol umum', 'subjektif' => 'Kontrol ...', 'icd10_ids' => [$this->icd10('Z00.0')]])
            ->assertCreated()->assertJsonPath('diagnosas.0.kode', 'Z00.0');
        $this->postJson('/api/template-soaps', ['nama' => 'Salah', 'icd10_ids' => [999999]])
            ->assertUnprocessable()->assertJsonValidationErrors('icd10_ids.0');

        $this->as('dokter@eklinik.test');
        $list = collect($this->getJson("/api/template-soaps?aktif=1&poli_id={$kulit}")->assertOk()->json());
        $nama = $list->pluck('nama');

        // Template poli kulit + template umum; template poli lain tidak ikut
        $this->assertContains('Akne vulgaris', $nama);
        $this->assertNotContains('Injeksi toksin botulinum', $nama);
        $this->assertSame('Kontrol umum', $list->last()['nama'], 'Template umum tampil setelah template poli');
        $this->assertSame('L70.0', $list->firstWhere('nama', 'Akne vulgaris')['diagnosas'][0]['kode']);
        $this->assertTrue($list->firstWhere('nama', 'Infeksi menular seksual (akses terbatas)')['akses_terbatas']);

        // Template per treatment
        $botox = collect($this->getJson('/api/template-soaps?aktif=1')->json())->firstWhere('nama', 'Injeksi toksin botulinum');
        $this->assertSame('TRT-001', Tindakan::find($botox['tindakan']['id'])->kode);

        // Kelola template butuh master.kelola
        $this->postJson('/api/template-soaps', ['nama' => 'Punya dokter'])->assertForbidden();
    }

    public function test_icd9cm_dan_favorit_kode_dokter(): void
    {
        $this->as('dokter@eklinik.test');
        $this->getJson('/api/icd9cms?q=86.3')->assertOk()->assertJsonPath('data.0.kode', '86.3')->assertJsonPath('data.0.favorit', false);

        $akne = $this->icd10('L70.0');
        $this->postJson('/api/kode-favorits', ['jenis' => 'icd10', 'kode_id' => $akne])->assertCreated();
        $this->postJson('/api/kode-favorits', ['jenis' => 'icd10', 'kode_id' => $akne])->assertCreated();
        $this->assertSame(1, KodeFavorit::count(), 'Menandai favorit dua kali tidak menduplikasi');

        $this->getJson('/api/icd10s?favorit=1')->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kode', 'L70.0')->assertJsonPath('data.0.favorit', true);
        // Favorit tampil paling atas pada daftar biasa
        $this->getJson('/api/icd10s')->assertJsonPath('data.0.kode', 'L70.0');

        // Favorit bersifat pribadi
        $this->as('dokter.gigi@eklinik.test');
        $this->getJson('/api/icd10s?favorit=1')->assertJsonCount(0, 'data');

        $this->as('dokter@eklinik.test');
        $this->deleteJson('/api/kode-favorits', ['jenis' => 'icd10', 'kode_id' => $akne])->assertOk();
        $this->getJson('/api/icd10s?favorit=1')->assertJsonCount(0, 'data');
        $this->postJson('/api/kode-favorits', ['jenis' => 'icd9cm', 'kode_id' => 999999])->assertUnprocessable();

        $this->as('perawat@eklinik.test');
        $this->postJson('/api/kode-favorits', ['jenis' => 'icd10', 'kode_id' => $akne])->assertForbidden();

        // Master: kode dipakai treatment tidak bisa dihapus; format kode; ICD-10 IMS otomatis sensitif
        $this->as('admin@eklinik.test');
        $this->deleteJson('/api/icd9cms/'.Icd9cm::where('kode', '99.29')->value('id'))->assertUnprocessable();
        $this->postJson('/api/icd9cms', ['kode' => '8x.1', 'nama' => 'Salah'])->assertUnprocessable()->assertJsonValidationErrors('kode');
        $this->postJson('/api/icd10s', ['kode' => 'A57', 'nama' => 'Chancroid'])->assertCreated()->assertJsonPath('sensitif', true);
        $this->postJson('/api/icd10s', ['kode' => 'L70.1', 'nama' => 'Akne konglobata'])->assertCreated()->assertJsonPath('sensitif', false);
    }

    public function test_tindakan_kunjungan_menyimpan_icd9cm_petugas_dan_stabil_saat_disimpan_ulang(): void
    {
        $id = $this->kunjunganDiperiksa();
        $dokter = User::where('email', 'dokter@eklinik.test')->first();
        $terapis = User::where('email', 'terapis@eklinik.test')->first();
        $botox = $this->tindakan('TRT-001');
        $url = "/api/kunjungans/{$id}/pemeriksaan";

        // ICD-9-CM default dari katalog, petugas default = dokter yang mengisi
        $res = $this->putJson($url, ['tindakans' => [['tindakan_id' => $botox->id]]])->assertOk()
            ->assertJsonPath('tindakans.0.icd9cm.kode', '99.29')
            ->assertJsonPath('tindakans.0.petugas.id', $dokter->id);
        $ktId = $res->json('tindakans.0.id');

        $this->putJson("/api/kunjungan-tindakans/{$ktId}/catatan", [
            'area' => 'Glabella',
            'titiks' => [['x' => 0.5, 'y' => 0.3, 'area' => 'Procerus', 'jumlah' => 4, 'satuan' => 'U']],
        ])->assertOk()->assertJsonPath('jenis', 'injeksi');

        // Disimpan ulang (tanpa id, seperti klien lama): baris, catatan & draft BHP tetap
        $res = $this->putJson($url, ['tindakans' => [['tindakan_id' => $botox->id, 'petugas_id' => $terapis->id,
            'icd9cm_id' => Icd9cm::where('kode', '86.02')->value('id')]]])->assertOk();
        $this->assertSame($ktId, $res->json('tindakans.0.id'));
        $res->assertJsonPath('tindakans.0.petugas.id', $terapis->id)
            ->assertJsonPath('tindakans.0.icd9cm.kode', '86.02')
            ->assertJsonPath('tindakans.0.catatan.area', 'Glabella')
            ->assertJsonPath('tindakans.0.catatan.titiks.0.area', 'Procerus');

        // Petugas harus tenaga pelayanan
        $kasir = User::where('email', 'kasir@eklinik.test')->first();
        $this->putJson($url, ['tindakans' => [['id' => $ktId, 'tindakan_id' => $botox->id, 'petugas_id' => $kasir->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.petugas_id');

        // Tindakan dihapus -> catatannya ikut terhapus per model (tercatat audit)
        $this->putJson($url, ['tindakans' => []])->assertOk()->assertJsonCount(0, 'tindakans');
        $this->assertDatabaseMissing('catatan_tindakans', ['kunjungan_tindakan_id' => $ktId]);
        $this->assertTrue(AuditLog::where('tipe', 'catatan_tindakan')->where('aksi', 'hapus')->exists());

        // Daftar petugas untuk pilihan di pemeriksaan: tenaga medis saja
        $petugas = collect($this->getJson('/api/petugas')->assertOk()->json())->pluck('id');
        $this->assertContains($terapis->id, $petugas);
        $this->assertNotContains($kasir->id, $petugas);
    }

    public function test_catatan_laser_dan_face_chart_injeksi(): void
    {
        $id = $this->kunjunganDiperiksa();
        $res = $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['tindakans' => [
            ['tindakan_id' => $this->tindakan('TRT-011')->id], ['tindakan_id' => $this->tindakan('TRT-002')->id],
        ]])->assertOk();
        [$ktLaser, $ktFiller] = [$res->json('tindakans.0.id'), $res->json('tindakans.1.id')];
        $alat = SumberDaya::withoutGlobalScopes()->where('kode', 'LASER-01')->first();

        // Terapis mengisi parameter laser (ES-02); kunci tak dikenal dibuang
        $this->as('terapis@eklinik.test');
        $this->getJson("/api/kunjungan-tindakans/{$ktLaser}/catatan")->assertOk()
            ->assertJsonPath('jenis', 'energi')->assertJsonPath('catatan', null)->assertJsonPath('terkunci', false);
        $this->putJson("/api/kunjungan-tindakans/{$ktLaser}/catatan", [
            'area' => 'Seluruh wajah',
            'sumber_daya_id' => $alat->id,
            'parameter' => ['panjang_gelombang_nm' => 1064, 'fluence_j_cm2' => 2.5, 'spot_size_mm' => 6, 'jumlah_shot' => 1500,
                'reaksi_kulit' => 'eritema_ringan', 'asal' => 'dibuang'],
        ])->assertOk()
            ->assertJsonPath('parameter.panjang_gelombang_nm', 1064)
            ->assertJsonPath('alat.kode', 'LASER-01')
            ->assertJsonMissingPath('parameter.asal');
        $this->putJson("/api/kunjungan-tindakans/{$ktLaser}/catatan", ['parameter' => ['reaksi_kulit' => 'meledak']])
            ->assertUnprocessable()->assertJsonValidationErrors('parameter.reaksi_kulit');

        // Face chart filler (ES-01): batch harus milik produk itu di cabang kunjungan
        $filler = Obat::where('kode', 'OBT-022')->first();
        $batch = StokBatch::withoutGlobalScopes()->where('obat_id', $filler->id)->first();
        $batchLain = StokBatch::withoutGlobalScopes()->where('obat_id', '!=', $filler->id)->first();
        $titik = ['x' => 0.42, 'y' => 0.55, 'area' => 'Nasolabial kiri', 'obat_id' => $filler->id, 'jumlah' => 0.5,
            'satuan' => 'ml', 'kedalaman' => 'subdermal', 'alat' => 'Kanula 25G'];

        $this->putJson("/api/kunjungan-tindakans/{$ktFiller}/catatan", ['titiks' => [[...$titik, 'batch_id' => $batchLain->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('titiks.0.batch_id');
        $this->putJson("/api/kunjungan-tindakans/{$ktFiller}/catatan", ['titiks' => [
            [...$titik, 'batch_id' => $batch->id],
            [...$titik, 'x' => 0.58, 'area' => 'Nasolabial kanan', 'batch_id' => $batch->id],
        ]])->assertOk()
            ->assertJsonPath('jenis', 'injeksi')
            ->assertJsonCount(2, 'titiks')
            ->assertJsonPath('titiks.0.batch.no_batch', $batch->no_batch);

        // Tampil di rekam medis kunjungan
        $this->as('dokter@eklinik.test');
        $this->getJson("/api/kunjungans/{$id}")->assertOk()
            ->assertJsonPath('tindakans.0.catatan.parameter.jumlah_shot', 1500)
            ->assertJsonPath('tindakans.1.catatan.titiks.1.area', 'Nasolabial kanan');

        $this->as('pendaftaran@eklinik.test');
        $this->putJson("/api/kunjungan-tindakans/{$ktLaser}/catatan", ['area' => 'x'])->assertForbidden();
    }

    public function test_informed_consent_wajib_sebelum_pemeriksaan_ditutup(): void
    {
        $id = $this->kunjunganDiperiksa();
        $botox = $this->tindakan('TRT-001');
        $ktId = $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => $this->icd10('Z41.1')]],
            'tindakans' => [['tindakan_id' => $botox->id]],
        ])->assertOk()->json('tindakans.0.id');
        $selesai = "/api/kunjungans/{$id}/selesai";
        $consents = "/api/kunjungans/{$id}/informed-consents";

        $this->postJson($selesai)->assertUnprocessable()->assertJsonValidationErrors('informed_consent');

        // Naskah di-render server dengan data pasien & tindakan
        $pasien = Kunjungan::withoutGlobalScopes()->find($id)->pasien;
        $isi = $this->getJson("{$consents}/pratinjau?template_consent_id={$botox->template_consent_id}&kunjungan_tindakan_id={$ktId}")
            ->assertOk()->json('isi');
        $this->assertStringContainsString($pasien->nama, $isi);
        $this->assertStringContainsString($botox->nama, $isi);
        $this->assertStringNotContainsString('{nama_pasien}', $isi);

        $payload = ['template_consent_id' => $botox->template_consent_id, 'kunjungan_tindakan_id' => $ktId, 'keputusan' => 'setuju',
            'penandatangan_nama' => $pasien->nama, 'hubungan' => 'pasien', 'ttd_penandatangan' => $this->ttd()];

        $this->postJson($consents, [...$payload, 'ttd_penandatangan' => 'data:image/png;base64,'.base64_encode('bukan png')])
            ->assertUnprocessable()->assertJsonValidationErrors('ttd_penandatangan');

        // Perawat mengambil consent di tablet (izin rme.tindakan)
        $this->as('perawat@eklinik.test');
        $consent = $this->postJson($consents, $payload)->assertCreated()
            ->assertJsonPath('status', 'disetujui')
            ->assertJsonPath('dokter.name', 'dr. Andi Wijaya')
            ->assertJsonMissingPath('ttd_penandatangan');
        $uuid = $consent->json('uuid');

        $this->postJson($consents, $payload)->assertUnprocessable()->assertJsonValidationErrors('kunjungan_tindakan_id');
        $this->assertStringNotContainsString('data:image/png', DB::table('informed_consents')->where('uuid', $uuid)->value('ttd_penandatangan'),
            'Tanda tangan tersimpan terenkripsi');

        // Detail berisi naskah & tanda tangan, tercatat audit
        $this->as('dokter@eklinik.test');
        $this->getJson("/api/informed-consents/{$uuid}")->assertOk()
            ->assertJsonPath('checksum_valid', true)
            ->assertJsonPath('isi', $isi)
            ->assertJsonPath('ttd_penandatangan', $payload['ttd_penandatangan']);
        $this->assertTrue(AuditLog::where('aksi', 'lihat')->where('tipe', 'informed_consent')->exists());

        // Dicabut -> wajib lagi; ditolak -> pesan berbeda; disetujui -> bisa ditutup
        $this->postJson("/api/informed-consents/{$uuid}/cabut", ['alasan' => 'Pasien berubah pikiran'])->assertOk()->assertJsonPath('status', 'dicabut');
        $this->postJson($selesai)->assertUnprocessable()->assertJsonValidationErrors('informed_consent');

        $this->postJson($consents, [...$payload, 'keputusan' => 'tolak'])->assertCreated()->assertJsonPath('status', 'ditolak');
        $this->assertStringContainsString('menolak', $this->postJson($selesai)->assertUnprocessable()->json('errors.informed_consent.0'));

        $this->postJson($consents, $payload)->assertCreated();
        $this->postJson($selesai)->assertOk()->assertJsonPath('status', 'menunggu_pembayaran')
            ->assertJsonCount(3, 'informed_consents');

        // Setelah pemeriksaan ditutup consent terkunci
        $this->postJson("/api/informed-consents/{$uuid}/cabut", ['alasan' => 'x'])->assertUnprocessable();
    }

    public function test_consent_tidak_wajib_bila_pengaturan_dimatikan(): void
    {
        $this->tanpaWajibConsent();
        $id = $this->kunjunganDiperiksa();

        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => $this->icd10('Z41.1')]],
            'tindakans' => [['tindakan_id' => $this->tindakan('TRT-011')->id]],
        ])->assertOk();
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();
    }

    public function test_selesai_menandatangani_rme_dan_hash_bisa_diverifikasi(): void
    {
        $this->tanpaWajibConsent();
        $id = $this->kunjunganDiperiksa();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'subjektif' => 'Kerutan dahi',
            'diagnosas' => [['icd10_id' => $this->icd10('Z41.1')]],
            'tindakans' => [['tindakan_id' => $this->tindakan('TRT-001')->id]],
        ])->assertOk();
        $selesai = "/api/kunjungans/{$id}/selesai";

        // Hanya dokter ber-SIP aktif yang boleh menandatangani
        $this->as('admin@eklinik.test');
        $this->postJson($selesai)->assertUnprocessable()->assertJsonValidationErrors('sip');

        $dokter = User::where('email', 'dokter@eklinik.test')->first();
        $dokter->update(['sip_berlaku_sampai' => today()->subDay()]);
        $this->as('dokter@eklinik.test');
        $this->getJson('/api/me')->assertJsonPath('sip_aktif', false);
        $this->postJson($selesai)->assertUnprocessable()->assertJsonValidationErrors('sip');

        $dokter->update(['sip_berlaku_sampai' => today()->addYear()]);
        $this->as('dokter@eklinik.test');
        $res = $this->postJson($selesai)->assertOk()->assertJsonPath('pemeriksaan.penandatangan.id', $dokter->id);
        $this->assertNotNull($res->json('pemeriksaan.ditandatangani_at'));

        $verifikasi = "/api/kunjungans/{$id}/verifikasi";
        $this->getJson($verifikasi)->assertOk()->assertJsonPath('ditandatangani', true)->assertJsonPath('valid', true);

        // Terkunci di level model
        $pemeriksaan = Pemeriksaan::where('kunjungan_id', $id)->first();
        try {
            $pemeriksaan->update(['subjektif' => 'diubah']);
            $this->fail('Rekam medis yang ditandatangani tidak boleh diubah.');
        } catch (LogicException) {
            // diharapkan
        }

        // Perubahan di luar aplikasi terdeteksi
        DB::table('pemeriksaans')->where('id', $pemeriksaan->id)->update(['subjektif' => 'diubah diam-diam']);
        $this->getJson($verifikasi)->assertJsonPath('valid', false);
    }

    public function test_addendum_setelah_rme_ditandatangani(): void
    {
        $this->tanpaWajibConsent();
        $id = $this->kunjunganDiperiksa('KULIT');
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['asesmen' => 'Akne', 'diagnosas' => [['icd10_id' => $this->icd10('L70.0')]]])->assertOk();
        $addendum = ['bagian' => 'asesmen', 'isi' => 'Akne vulgaris derajat sedang', 'alasan' => 'Koreksi derajat keparahan'];
        $url = "/api/kunjungans/{$id}/addendum";

        $this->postJson($url, $addendum)->assertUnprocessable()->assertJsonValidationErrors('addendum');
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['asesmen' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson($url, $addendum)->assertCreated()->assertJsonPath('user.name', 'dr. Andi Wijaya');
        $this->postJson($url, ['bagian' => 'asesmen', 'isi' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('alasan');

        $this->getJson("/api/kunjungans/{$id}")
            ->assertJsonPath('pemeriksaan.asesmen', 'Akne')
            ->assertJsonPath('pemeriksaan.addendums.0.isi', 'Akne vulgaris derajat sedang');
        $this->getJson("/api/kunjungans/{$id}/verifikasi")->assertJsonPath('valid', true);
        $this->assertTrue(AuditLog::where('tipe', 'pemeriksaan_addendum')->where('aksi', 'buat')->exists());

        try {
            PemeriksaanAddendum::first()->delete();
            $this->fail('Addendum tidak boleh dihapus.');
        } catch (LogicException) {
            // diharapkan
        }

        $this->as('perawat@eklinik.test');
        $this->postJson($url, $addendum)->assertForbidden();
    }

    public function test_kunjungan_ims_berakses_terbatas(): void
    {
        Storage::fake('berkas');
        $pasien = Pasien::first();
        $id = $this->kunjunganDiperiksa('KULIT', $pasien);
        $url = "/api/kunjungans/{$id}/pemeriksaan";

        // Diagnosa IMS otomatis membatasi akses dan tidak bisa dilepas selama diagnosanya ada
        $this->putJson($url, ['subjektif' => 'Duh tubuh', 'diagnosas' => [['icd10_id' => $this->icd10('A54.9')]]])
            ->assertOk()->assertJsonPath('akses_terbatas', true);
        $this->putJson($url, ['akses_terbatas' => false])->assertOk()->assertJsonPath('akses_terbatas', true);

        $uuid = $this->post('/api/berkas', [
            'file' => UploadedFile::fake()->createWithContent('lesi.jpg', self::jpeg()),
            // Bukan foto_klinis: foto butuh consent foto (diuji di FotoKlinisTest)
            'kategori' => 'hasil_penunjang', 'pasien_id' => $pasien->id, 'kunjungan_id' => $id,
        ], ['Accept' => 'application/json'])->assertCreated()->json('uuid');
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        // Dokter lain yang tidak menangani: kunjungan tampil tanpa isi rekam medis
        $lain = User::factory()->create(['role' => 'dokter', 'poli_id' => Poli::where('kode', 'KULIT')->value('id'),
            'cabang_id' => Kunjungan::withoutGlobalScopes()->find($id)->cabang_id, 'sip' => '503/SIP/KULIT/2026']);
        Sanctum::actingAs($lain);

        $this->getJson("/api/kunjungans/{$id}")->assertOk()->assertJsonPath('rme_disembunyikan', true)->assertJsonMissingPath('pemeriksaan');
        $this->getJson("/api/pasiens/{$pasien->id}/riwayat")->assertOk()
            ->assertJsonPath('0.id', $id)->assertJsonPath('0.rme_disembunyikan', true)->assertJsonMissingPath('0.pemeriksaan');
        $this->getJson("/api/pasiens/{$pasien->id}")->assertOk()->assertJsonMissingPath('kunjungans.0.pemeriksaan');
        $this->getJson("/api/berkas?pasien_id={$pasien->id}")->assertOk()->assertJsonCount(0);
        $this->getJson("/api/berkas/{$uuid}/tautan")->assertForbidden();
        $this->getJson("/api/kunjungans/{$id}/verifikasi")->assertForbidden();

        // Dokter yang menangani tetap melihat lengkap
        $this->as('dokter@eklinik.test');
        $this->getJson("/api/kunjungans/{$id}")->assertJsonPath('rme_disembunyikan', false)
            ->assertJsonPath('pemeriksaan.diagnosas.0.icd10.kode', 'A54.9');
        $this->getJson("/api/berkas?pasien_id={$pasien->id}")->assertJsonCount(1);

        // Pemegang izin rme.terbatas melihat semua
        $peran = Peran::where('kode', 'dokter')->first();
        $peran->aturIzin([...$peran->izin, 'rme.terbatas']);
        Sanctum::actingAs($lain->fresh());
        $this->getJson("/api/kunjungans/{$id}")->assertJsonPath('rme_disembunyikan', false)
            ->assertJsonPath('pemeriksaan.subjektif', 'Duh tubuh');
    }
}
