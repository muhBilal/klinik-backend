<?php

namespace Tests\Feature;

use App\Jobs\KirimSatuSehat;
use App\Models\Cabang;
use App\Models\Icd10;
use App\Models\Kunjungan;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\SatuSehatKirim;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use App\Services\SatuSehat\SatuSehatService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Integrasi SATUSEHAT (PRD v2 SS-01..05, PS-05) dengan respons HTTP tiruan: token OAuth, lookup IHS pasien & praktisi via NIK,
 * Bundle Encounter + Condition + Observation + Procedure setelah RME ditandatangani, galat data vs sementara, kirim ulang.
 */
class SatuSehatTest extends TestCase
{
    use RefreshDatabase;

    private const FHIR = 'https://fhir.test/fhir-r4/v1';

    private Pasien $pasien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);
        config(['services.satusehat' => [
            'aktif' => true, 'env' => 'sandbox', 'client_id' => 'cid', 'client_secret' => 'rahasia', 'organization_id' => 'ORG-1',
            'auth_url' => 'https://auth.test/oauth2/v1', 'base_url' => self::FHIR, 'timeout' => 5,
        ]]);

        $this->pasien = Pasien::whereNotNull('nik')->firstOrFail();
        User::where('email', 'dokter@eklinik.test')->update(['nik' => '3174000000000001']);
        Cabang::query()->update(['satusehat_location_id' => 'LOC-1']);
    }

    private function fakeSatuSehat(int $statusBundle = 200): void
    {
        Http::fake([
            'https://auth.test/*' => Http::response(['access_token' => 'TKN', 'expires_in' => '3599']),
            self::FHIR.'/Patient*' => Http::response(['resourceType' => 'Bundle', 'entry' => [['resource' => ['resourceType' => 'Patient', 'id' => 'P02478375538']]]]),
            self::FHIR.'/Practitioner*' => Http::response(['resourceType' => 'Bundle', 'entry' => [['resource' => ['resourceType' => 'Practitioner', 'id' => 'N10000001']]]]),
            self::FHIR.'/' => $statusBundle === 200
                ? Http::response(['resourceType' => 'Bundle', 'type' => 'transaction-response', 'entry' => [
                    ['response' => ['status' => '201 Created', 'location' => 'Encounter/ENC-123/_history/1']],
                    ['response' => ['status' => '201 Created', 'location' => 'Condition/CON-1/_history/1']],
                ]])
                : Http::response(['resourceType' => 'OperationOutcome', 'issue' => [['code' => 'exception', 'details' => ['text' => 'Server sibuk']]]], $statusBundle),
        ]);
    }

    /** Kunjungan estetika lengkap (vital, diagnosa, tindakan ber-ICD-9-CM) yang ditutup dokter → id kunjungan. */
    private function tutupKunjungan(): int
    {
        Sanctum::actingAs(User::where('email', 'pendaftaran@eklinik.test')->firstOrFail());
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $this->pasien->id, 'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])
            ->assertCreated()->json('id');
        Sanctum::actingAs(User::where('email', 'dokter@eklinik.test')->firstOrFail());
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'tekanan_darah' => '120/80', 'nadi' => 82, 'suhu' => 36.6,
            'diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => [['tindakan_id' => Tindakan::where('kode', 'TRT-001')->value('id'), 'jumlah' => 1]],
        ])->assertOk();
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        return $id;
    }

    public function test_kunjungan_bertanda_tangan_terkirim_sebagai_bundle_fhir(): void
    {
        $this->fakeSatuSehat();
        $id = $this->tutupKunjungan();

        $kirim = SatuSehatKirim::withoutGlobalScopes()->where('kunjungan_id', $id)->firstOrFail();
        $this->assertSame('terkirim', $kirim->status);
        $this->assertSame('ENC-123', $kirim->encounter_id);
        $this->assertSame(['ENC-123'], $kirim->hasil['Encounter']);
        $this->assertSame('ENC-123', Kunjungan::withoutGlobalScopes()->find($id)->satusehat_encounter_id);
        $this->assertSame('P02478375538', $this->pasien->refresh()->ihs_id);
        $this->assertSame('N10000001', User::where('email', 'dokter@eklinik.test')->value('ihs_id'));

        Http::assertSent(function (HttpRequest $r) {
            if ($r->url() !== self::FHIR.'/' || $r->method() !== 'POST') {
                return false;
            }
            $entri = collect($r->data()['entry']);
            $tipe = $entri->pluck('resource.resourceType');
            $encounter = $entri->firstWhere('resource.resourceType', 'Encounter')['resource'];
            $loinc = $entri->where('resource.resourceType', 'Observation')->pluck('resource.code.coding.0.code');

            return $r->hasHeader('Authorization', 'Bearer TKN')
                && $tipe->contains('Condition') && $tipe->contains('Procedure')
                && $encounter['identifier'][0]['system'] === 'http://sys-ids.kemkes.go.id/encounter/ORG-1'
                && $encounter['subject']['reference'] === 'Patient/P02478375538'
                && $encounter['location'][0]['location']['reference'] === 'Location/LOC-1'
                && $loinc->contains('8867-4') && $loinc->contains('8480-6') && $loinc->contains('8462-4') && $loinc->contains('8310-5')
                && $entri->firstWhere('resource.resourceType', 'Condition')['resource']['code']['coding'][0]['code'] === 'Z41.1';
        });

        // Status & kepatuhan untuk admin
        Sanctum::actingAs(User::where('email', 'admin@eklinik.test')->firstOrFail());
        $this->getJson('/api/satusehat/status')->assertOk()
            ->assertJsonPath('per_status.terkirim', 1)
            ->assertJsonPath('kepatuhan_30_hari.persen', 100);
    }

    public function test_galat_data_menjadi_gagal_lalu_bisa_dikirim_ulang(): void
    {
        $this->fakeSatuSehat();
        Cabang::query()->update(['satusehat_location_id' => null]);
        $id = $this->tutupKunjungan();

        $kirim = SatuSehatKirim::withoutGlobalScopes()->where('kunjungan_id', $id)->firstOrFail();
        $this->assertSame('gagal', $kirim->status);
        $this->assertStringContainsString('Location SATUSEHAT', $kirim->error);

        Cabang::query()->update(['satusehat_location_id' => 'LOC-1']);
        Sanctum::actingAs(User::where('email', 'admin@eklinik.test')->firstOrFail());
        $this->getJson('/api/satusehat/kirims?status=gagal')->assertOk()->assertJsonPath('data.0.id', $kirim->id);
        $this->postJson("/api/satusehat/kirims/{$kirim->id}/ulang")->assertOk();
        $this->assertSame('terkirim', $kirim->refresh()->status);
        $this->assertSame(2, $kirim->percobaan);

        // Kasir tidak boleh memantau integrasi
        Sanctum::actingAs(User::where('email', 'kasir@eklinik.test')->firstOrFail());
        $this->getJson('/api/satusehat/status')->assertForbidden();
    }

    public function test_galat_sementara_dijadwalkan_ulang_tanpa_menggagalkan_pemeriksaan(): void
    {
        $this->fakeSatuSehat(503);
        Queue::fake();
        $id = $this->tutupKunjungan(); // pemeriksaan tetap tertutup walau SATUSEHAT sibuk

        Queue::assertPushed(KirimSatuSehat::class);
        $kirim = SatuSehatKirim::withoutGlobalScopes()->where('kunjungan_id', $id)->firstOrFail();

        // Jalankan job langsung: 503 → tetap "menunggu" dengan pesan galat, job dilepas untuk dicoba lagi
        $job = (new KirimSatuSehat($kirim->id))->withFakeQueueInteractions();
        $job->handle(app(SatuSehatService::class));
        $job->assertReleased(60);
        $this->assertSame('menunggu', $kirim->refresh()->status);
        $this->assertStringContainsString('Server sibuk', $kirim->error);

        // Semua percobaan habis → gagal
        $job->failed(new \RuntimeException('Percobaan habis'));
        $this->assertSame('gagal', $kirim->refresh()->status);
    }

    public function test_integrasi_nonaktif_tidak_membuat_antrean_dan_lookup_ihs_pasien(): void
    {
        config(['services.satusehat.aktif' => false]);
        $this->fakeSatuSehat();
        $id = $this->tutupKunjungan();
        $this->assertFalse(SatuSehatKirim::withoutGlobalScopes()->where('kunjungan_id', $id)->exists());
        Http::assertNotSent(fn (HttpRequest $r) => $r->url() === self::FHIR.'/');

        // Lookup IHS manual (PS-05) oleh front office
        Sanctum::actingAs(User::where('email', 'pendaftaran@eklinik.test')->firstOrFail());
        $this->postJson("/api/pasiens/{$this->pasien->id}/satusehat")->assertOk()->assertJsonPath('ihs_id', 'P02478375538');

        $tanpaNik = Pasien::create(['nama' => 'Tanpa NIK', 'jenis_kelamin' => 'L', 'tanggal_lahir' => '1990-01-01']);
        $this->postJson("/api/pasiens/{$tanpaNik->id}/satusehat")->assertStatus(422)->assertJsonValidationErrors('ihs');
    }
}
