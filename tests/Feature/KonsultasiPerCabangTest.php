<?php

namespace Tests\Feature;

use App\Models\Cabang;
use App\Models\Icd10;
use App\Models\Kunjungan;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Tagihan;
use App\Models\Tindakan;
use App\Models\TindakanHarga;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * AD-01: tarif konsultasi poli per cabang lewat treatment yang ditautkan.
 */
class KonsultasiPerCabangTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $email): User
    {
        Sanctum::actingAs(User::where('email', $email)->firstOrFail());

        return User::where('email', $email)->firstOrFail();
    }

    public function test_konsultasi_memakai_tarif_cabang_dari_treatment_tertaut(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);

        $cabang = Cabang::firstOrFail();
        $poli = Poli::where('kode', 'ESTETIKA')->firstOrFail();
        $tindakan = Tindakan::where('kode', 'TRT-001')->firstOrFail();

        // Harga konsultasi khusus cabang ini berbeda dari tarif flat poli.
        TindakanHarga::create(['tindakan_id' => $tindakan->id, 'cabang_id' => $cabang->id, 'tarif' => 123456, 'tersedia' => true]);
        $poli->update(['tindakan_konsultasi_id' => $tindakan->id, 'tarif_konsultasi' => 50000]);

        $pasien = Pasien::firstOrFail();
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $pasien->id, 'poli_id' => $poli->id, 'penjamin' => 'umum'])
            ->assertCreated()->json('id');
        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => [],
        ])->assertOk();
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        $tagihanId = Kunjungan::withoutGlobalScopes()->findOrFail($id)->tagihans()->withoutGlobalScopes()->value('id');
        $konsultasi = collect(Tagihan::withoutGlobalScopes()->findOrFail($tagihanId)->items)->firstWhere('kategori', 'konsultasi');

        $this->assertSame(123456, (int) $konsultasi['harga'], 'konsultasi harus memakai tarif cabang treatment, bukan tarif flat poli');
    }

    public function test_tanpa_treatment_tertaut_pakai_tarif_flat_poli(): void
    {
        $this->seed(DatabaseSeeder::class);
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);

        $poli = Poli::where('kode', 'ESTETIKA')->firstOrFail();
        $poli->update(['tindakan_konsultasi_id' => null, 'tarif_konsultasi' => 77000]);

        $pasien = Pasien::firstOrFail();
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $pasien->id, 'poli_id' => $poli->id, 'penjamin' => 'umum'])
            ->assertCreated()->json('id');
        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => [],
        ])->assertOk();
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        $tagihanId = Kunjungan::withoutGlobalScopes()->findOrFail($id)->tagihans()->withoutGlobalScopes()->value('id');
        $konsultasi = collect(Tagihan::withoutGlobalScopes()->findOrFail($tagihanId)->items)->firstWhere('kategori', 'konsultasi');

        $this->assertSame(77000, (int) $konsultasi['harga']);
    }
}
