<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Icd10;
use App\Models\KunjunganTindakan;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\StokBatch;
use App\Models\StokMutasi;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\InventoriService;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Inventori: batch & kedaluwarsa FEFO (IN-01), potong BHP otomatis (IN-02),
 * satuan fraksional & vial terbuka (IN-03).
 */
class InventoriTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        // Test ini fokus ke BHP; kewajiban informed consent botox diuji di RmeEstetikaTest.
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);
    }

    private function apoteker(): User
    {
        return User::where('role', Role::Apoteker->value)->firstOrFail();
    }

    private function obat(string $kode): Obat
    {
        return Obat::where('kode', $kode)->firstOrFail();
    }

    private function inventori(): InventoriService
    {
        return app(InventoriService::class);
    }

    public function test_pengeluaran_memakai_batch_kedaluwarsa_terdekat(): void
    {
        $apoteker = $this->apoteker();
        $cabangId = $apoteker->cabang_id;
        $obat = $this->obat('OBT-024'); // spuit, non-fraksional

        // Seeder membuat dua batch: -A (6 bulan) dan -B (18 bulan)
        $dekat = StokBatch::withoutGlobalScopes()->where('obat_id', $obat->id)->orderBy('kedaluwarsa')->firstOrFail();
        $isiDekat = $dekat->jumlah;

        $terpakai = $this->inventori()->keluarkan($obat, $cabangId, 5, $apoteker);

        $this->assertSame([$dekat->id], array_column($terpakai, 'batch_id'));
        $this->assertSame($isiDekat - 5, $dekat->refresh()->jumlah);
    }

    public function test_pengeluaran_terbagi_ke_batch_berikutnya_saat_batch_pertama_habis(): void
    {
        $apoteker = $this->apoteker();
        $obat = $this->obat('OBT-024');

        $batches = StokBatch::withoutGlobalScopes()->where('obat_id', $obat->id)->orderBy('kedaluwarsa')->get();
        $minta = $batches[0]->jumlah + 10;

        $terpakai = $this->inventori()->keluarkan($obat, $apoteker->cabang_id, $minta, $apoteker);

        $this->assertCount(2, $terpakai);
        $this->assertSame(0.0, $batches[0]->refresh()->jumlah);
        $this->assertSame($batches[1]->jumlah - 10, $batches[1]->refresh()->jumlah);
    }

    public function test_batch_kedaluwarsa_tidak_ikut_dipakai(): void
    {
        $apoteker = $this->apoteker();
        $obat = $this->obat('OBT-024');

        // Buat batch kedaluwarsa langsung di DB (service menolak tanggal lampau saat penerimaan)
        $basi = StokBatch::withoutGlobalScopes()->create([
            'obat_id' => $obat->id, 'cabang_id' => $apoteker->cabang_id, 'no_batch' => 'BASI',
            'kedaluwarsa' => today()->subDay(), 'jumlah' => 100, 'jumlah_awal' => 100,
        ]);

        $terpakai = $this->inventori()->keluarkan($obat, $apoteker->cabang_id, 5, $apoteker);

        $this->assertNotContains($basi->id, array_column($terpakai, 'batch_id'));
        $this->assertSame(100.0, $basi->refresh()->jumlah);
    }

    public function test_mutasi_antar_cabang_memindahkan_isi_batch_dengan_jejak_kartu_stok(): void
    {
        Sanctum::actingAs(User::where('role', Role::Admin->value)->firstOrFail());
        $cabangBaru = $this->postJson('/api/cabangs', ['kode' => 'CAB2', 'nama' => 'Cabang Dua', 'is_active' => true])->assertCreated()->json('id');

        $apoteker = $this->apoteker();
        Sanctum::actingAs($apoteker);
        $obat = $this->obat('OBT-022');
        $batch = StokBatch::where('obat_id', $obat->id)->where('cabang_id', $apoteker->cabang_id)->where('jumlah', '>', 2)->orderBy('id')->firstOrFail();
        $awal = (float) $batch->jumlah;
        $totalAwal = (float) $obat->stok;

        $res = $this->postJson("/api/stok-batches/{$batch->id}/mutasi", ['cabang_tujuan_id' => $cabangBaru, 'jumlah' => 2, 'keterangan' => 'Kebutuhan promo'])
            ->assertOk();
        $this->assertEquals($awal - 2, $res->json('jumlah'));

        $tujuan = StokBatch::withoutGlobalScopes()->where('obat_id', $obat->id)->where('cabang_id', $cabangBaru)->firstOrFail();
        $this->assertSame(2.0, (float) $tujuan->jumlah);
        $this->assertSame($batch->no_batch, $tujuan->no_batch);
        $this->assertSame($batch->kedaluwarsa?->toDateString(), $tujuan->kedaluwarsa?->toDateString());
        // Total lintas cabang tidak berubah; dua baris kartu stok dengan referensi sama
        $this->assertSame($totalAwal, (float) $obat->refresh()->stok);
        $ref = StokMutasi::withoutGlobalScopes()->where('batch_id', $tujuan->id)->value('referensi');
        $this->assertNotNull($ref);
        $this->assertSame(2, StokMutasi::withoutGlobalScopes()->where('referensi', $ref)->count());

        // Melebihi isi batch, cabang sendiri, dan obat non-fraksional desimal ditolak
        $this->postJson("/api/stok-batches/{$batch->id}/mutasi", ['cabang_tujuan_id' => $cabangBaru, 'jumlah' => 99999])->assertStatus(422)->assertJsonValidationErrors('jumlah');
        $this->postJson("/api/stok-batches/{$batch->id}/mutasi", ['cabang_tujuan_id' => $apoteker->cabang_id, 'jumlah' => 1])->assertStatus(422)->assertJsonValidationErrors('cabang_tujuan_id');
    }

    public function test_stok_terpisah_per_cabang(): void
    {
        Sanctum::actingAs(User::where('role', Role::Admin->value)->firstOrFail());

        $cabangBaru = $this->postJson('/api/cabangs', [
            'kode' => 'CAB2', 'nama' => 'Cabang Dua', 'is_active' => true,
        ])->assertCreated()->json('id');

        $obat = $this->obat('OBT-024');
        $stokCabang1 = $obat->stokDi($this->apoteker()->cabang_id);

        // Cabang baru belum punya stok apa pun
        $this->assertSame(0.0, $obat->stokDi($cabangBaru));

        $this->inventori()->terima($obat, $cabangBaru, 50, 'B-CAB2', today()->addYear(), $this->apoteker());

        $this->assertSame(50.0, $obat->stokDi($cabangBaru));
        $this->assertSame($stokCabang1, $obat->stokDi($this->apoteker()->cabang_id));
        // Total lintas cabang ikut bertambah
        $this->assertSame($stokCabang1 + 50, (float) $obat->refresh()->stok);
    }

    public function test_obat_non_fraksional_menolak_jumlah_desimal(): void
    {
        $apoteker = $this->apoteker();

        // Spuit tidak fraksional
        $this->expectException(ValidationException::class);
        $this->inventori()->keluarkan($this->obat('OBT-024'), $apoteker->cabang_id, 0.5, $apoteker);
    }

    public function test_obat_fraksional_boleh_dipakai_sebagian_dan_vial_terbuka_punya_masa_pakai(): void
    {
        $apoteker = $this->apoteker();
        $botox = $this->obat('OBT-021'); // fraksional, 24 jam setelah dibuka

        $this->assertTrue($botox->fraksional);

        $terpakai = $this->inventori()->keluarkan($botox, $apoteker->cabang_id, 0.3, $apoteker);

        $batch = StokBatch::withoutGlobalScopes()->findOrFail($terpakai[0]['batch_id']);

        $this->assertNotNull($batch->dibuka_at);
        $this->assertNotNull($batch->kedaluwarsa_dibuka_at);
        $this->assertSame(24, (int) $batch->dibuka_at->diffInHours($batch->kedaluwarsa_dibuka_at));

        // Lewat 25 jam, sisa vial tidak boleh dipakai lagi meski tanggal kedaluwarsa batch masih jauh
        $this->travel(25)->hours();

        $this->expectException(ValidationException::class);
        $this->inventori()->keluarkan($botox, $apoteker->cabang_id, 0.1, $apoteker, batchId: $batch->id);
    }

    public function test_bhp_standar_otomatis_memotong_stok_saat_pemeriksaan_selesai(): void
    {
        // TRT-001 botox: BHP standar 0.3 vial OBT-021 + 2 pcs OBT-024
        $dokter = User::where('email', 'dokter@eklinik.test')->firstOrFail();
        $botox = $this->obat('OBT-021');
        $spuit = $this->obat('OBT-024');

        $stokBotox = $botox->stokDi($dokter->cabang_id);
        $stokSpuit = $spuit->stokDi($dokter->cabang_id);

        Sanctum::actingAs(User::where('role', Role::Pendaftaran->value)->firstOrFail());
        $id = $this->postJson('/api/kunjungans', [
            'pasien_id' => Pasien::value('id'),
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'),
            'penjamin' => 'umum',
        ])->assertCreated()->json('id');

        Sanctum::actingAs($dokter);
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();

        $trt = Tindakan::where('kode', 'TRT-001')->firstOrFail();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::value('id')]],
            'tindakans' => [['tindakan_id' => $trt->id]],
        ])->assertOk();

        // Draft BHP sudah dibuat dari katalog, stok belum berkurang
        $kt = KunjunganTindakan::where('kunjungan_id', $id)->firstOrFail();
        $this->assertSame(2, $kt->bhps()->count());
        $this->assertSame($stokBotox, $botox->stokDi($dokter->cabang_id));

        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        // Stok terpotong sesuai BHP standar
        $this->assertSame($stokBotox - 0.3, $botox->stokDi($dokter->cabang_id));
        $this->assertSame($stokSpuit - 2, $spuit->stokDi($dokter->cabang_id));
        $this->assertSame(2, $kt->bhps()->where('stok_dipotong', true)->count());
    }

    public function test_koreksi_pemakaian_bhp_sebelum_stok_dipotong(): void
    {
        $dokter = User::where('email', 'dokter@eklinik.test')->firstOrFail();
        $botox = $this->obat('OBT-021');
        $stokAwal = $botox->stokDi($dokter->cabang_id);

        Sanctum::actingAs(User::where('role', Role::Pendaftaran->value)->firstOrFail());
        $id = $this->postJson('/api/kunjungans', [
            'pasien_id' => Pasien::value('id'),
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'),
            'penjamin' => 'umum',
        ])->assertCreated()->json('id');

        Sanctum::actingAs($dokter);
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::value('id')]],
            'tindakans' => [['tindakan_id' => Tindakan::where('kode', 'TRT-001')->value('id')]],
        ])->assertOk();

        $kt = KunjunganTindakan::where('kunjungan_id', $id)->firstOrFail();

        // Pemakaian nyata lebih banyak dari standar (0.3 -> 0.5)
        $this->putJson("/api/kunjungan-tindakans/{$kt->id}/bhps", [
            'bhps' => [['obat_id' => $botox->id, 'jumlah' => 0.5]],
        ])->assertOk();

        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        $this->assertSame($stokAwal - 0.5, $botox->stokDi($dokter->cabang_id));

        // Standar tetap tersimpan sebagai pembanding (LP-04)
        $baris = $kt->bhps()->where('obat_id', $botox->id)->firstOrFail();
        $this->assertSame(0.3, $baris->jumlah_standar);
        $this->assertSame(0.5, $baris->jumlah);

        // Setelah dipotong, koreksi ditolak
        $this->putJson("/api/kunjungan-tindakans/{$kt->id}/bhps", [
            'bhps' => [['obat_id' => $botox->id, 'jumlah' => 0.2]],
        ])->assertStatus(422)->assertJsonValidationErrors('bhps');
    }

    public function test_penerimaan_dan_stok_opname_lewat_api(): void
    {
        Sanctum::actingAs($this->apoteker());

        $obat = $this->obat('OBT-024');

        $batch = $this->postJson('/api/stok-batches', [
            'obat_id' => $obat->id,
            'jumlah' => 100,
            'no_batch' => 'BATCH-BARU',
            'kedaluwarsa' => today()->addYear()->toDateString(),
        ])->assertCreated()->json();

        $this->assertSame(100, $batch['jumlah']);

        // Stok opname: hasil hitung fisik 95
        $this->postJson("/api/stok-batches/{$batch['id']}/sesuaikan", [
            'jumlah' => 95, 'keterangan' => 'Stok opname bulanan',
        ])->assertOk()->assertJsonPath('jumlah', 95);

        $this->assertDatabaseHas('stok_mutasis', [
            'batch_id' => $batch['id'], 'jenis' => 'penyesuaian', 'jumlah' => -5,
        ]);

        // Kedaluwarsa lampau ditolak saat penerimaan
        $this->postJson('/api/stok-batches', [
            'obat_id' => $obat->id, 'jumlah' => 10, 'kedaluwarsa' => today()->subDay()->toDateString(),
        ])->assertStatus(422)->assertJsonValidationErrors('kedaluwarsa');
    }

    public function test_daftar_batch_akan_kedaluwarsa(): void
    {
        Sanctum::actingAs($this->apoteker());

        $obat = $this->obat('OBT-024');

        StokBatch::withoutGlobalScopes()->create([
            'obat_id' => $obat->id, 'cabang_id' => $this->apoteker()->cabang_id, 'no_batch' => 'SEGERA',
            'kedaluwarsa' => today()->addDays(10), 'jumlah' => 20, 'jumlah_awal' => 20,
        ]);

        $res = $this->getJson('/api/stok-batches/kedaluwarsa?hari=30')->assertOk();

        $this->assertContains('SEGERA', array_column($res->json(), 'no_batch'));
        // Batch seeder 18 bulan tidak ikut
        $this->assertNotContains('B024-B', array_column($res->json(), 'no_batch'));
    }
}
