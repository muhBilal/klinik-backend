<?php

namespace Tests\Feature;

use App\Enums\StatusAppointment;
use App\Models\Appointment;
use App\Models\Icd10;
use App\Models\Kunjungan;
use App\Models\Paket;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Tagihan;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Laporan (PRD LP-01 dashboard, LP-02 penjualan, LP-03 paket, AD-01 konsolidasi, LP-06 ekspor CSV sebagian).
 */
class LaporanTest extends TestCase
{
    use RefreshDatabase;

    private Pasien $pasien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);
        $this->pasien = Pasien::first();
    }

    private function as(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function bayar(int $tagihanId, int $diskon = 0, string $metode = 'tunai'): void
    {
        $this->as('kasir@eklinik.test');
        $grand = $this->getJson("/api/tagihans/{$tagihanId}")->json('grand_total');
        // Admin tidak dibatasi diskon; kasir tanpa batas (opt-in) juga boleh
        $this->postJson("/api/tagihans/{$tagihanId}/bayar", ['pembayarans' => [['metode' => $metode, 'jumlah' => $grand - $diskon]], 'diskon' => $diskon])->assertOk();
    }

    /** Kunjungan Estetika (dokter demo) dengan satu treatment, ditutup → id tagihan. */
    private function kunjungan(string $kode = 'TRT-001', ?int $paketItemId = null): int
    {
        $this->travel(1)->days();
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $this->pasien->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');
        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => [array_filter(['tindakan_id' => Tindakan::where('kode', $kode)->value('id'), 'jumlah' => 1, 'paket_pasien_item_id' => $paketItemId])],
        ])->assertOk();
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        return Kunjungan::withoutGlobalScopes()->findOrFail($id)->tagihans()->withoutGlobalScopes()->value('id');
    }

    public function test_penjualan_per_treatment_metode_dokter_dan_refund(): void
    {
        $dari = today()->toDateString();

        // 1) Kunjungan botulinum + konsultasi, diskon 100.000, bayar QRIS
        $t1 = $this->kunjungan('TRT-001');
        $this->bayar($t1, 100000, 'qris');
        // 2) Penjualan produk langsung, tunai
        $this->as('kasir@eklinik.test');
        $t2 = $this->postJson('/api/tagihans', ['pasien_id' => $this->pasien->id, 'items' => [
            ['kategori' => 'produk', 'deskripsi' => 'Sunscreen', 'jumlah' => 2, 'harga' => 150000],
        ]])->assertCreated()->json('id');
        $this->bayar($t2);
        // 3) Penjualan produk lain lalu direfund
        $t3 = $this->postJson('/api/tagihans', ['pasien_id' => $this->pasien->id, 'items' => [
            ['kategori' => 'produk', 'deskripsi' => 'Serum', 'jumlah' => 1, 'harga' => 200000],
        ]])->assertCreated()->json('id');
        $this->bayar($t3);
        $this->as('admin@eklinik.test');
        $this->postJson("/api/tagihans/{$t3}/refund", ['alasan_refund' => 'Rusak'])->assertOk();

        $tagihan1 = Tagihan::withoutGlobalScopes()->findOrFail($t1);
        $botox = Tindakan::where('kode', 'TRT-001')->firstOrFail();
        $sampai = now()->toDateString();

        $this->as('manajer@eklinik.test');
        $q = fn (string $kelompok) => $this->getJson("/api/laporan/penjualan?dari={$dari}&sampai={$sampai}&kelompok={$kelompok}")->assertOk();

        $res = $q('treatment')
            ->assertJsonPath('ringkasan.transaksi', 3)
            ->assertJsonPath('ringkasan.bruto', $tagihan1->total + 300000 + 200000)
            ->assertJsonPath('ringkasan.diskon', 100000)
            ->assertJsonPath('ringkasan.refund', 200000)
            ->assertJsonPath('ringkasan.bersih', $tagihan1->grand_total + 300000)
            ->assertJsonPath('baris.0.kunci', (string) $botox->id)
            ->assertJsonPath('baris.0.jumlah', 1)
            ->json();
        // Neto treatment = tarif × porsi setelah diskon
        $this->assertSame((int) round($botox->tarif * ($tagihan1->total - 100000) / $tagihan1->total), $res['baris'][0]['neto']);

        $kategori = collect($q('kategori')->json('baris'))->keyBy('kunci');
        $this->assertSame(300000, $kategori['produk']['bersih']); // 500.000 terjual − 200.000 refund
        $this->assertSame(200000, $kategori['produk']['refund']);

        $metode = collect($q('metode')->json('baris'))->keyBy('kunci');
        $this->assertSame($tagihan1->grand_total, $metode['qris']['masuk']);
        $this->assertSame(500000, $metode['tunai']['masuk']);
        $this->assertSame(200000, $metode['tunai']['refund']);

        $dokter = collect($q('dokter')->json('baris'))->keyBy('kunci');
        $idDokter = (string) User::where('email', 'dokter@eklinik.test')->value('id');
        $this->assertSame($tagihan1->grand_total, $dokter[$idDokter]['bersih']);
        $this->assertSame(300000, $dokter['langsung']['bersih']);

        // Konsolidasi cabang (admin lintas cabang tanpa pilihan cabang)
        $this->as('admin@eklinik.test');
        $this->getJson("/api/laporan/penjualan?dari={$dari}&sampai={$sampai}&kelompok=cabang")->assertOk()
            ->assertJsonPath('cabang_id', null)
            ->assertJsonPath('baris.0.bersih', $tagihan1->grand_total + 300000);

        // Ekspor CSV
        $csv = $this->get("/api/laporan/penjualan?dari={$dari}&sampai={$sampai}&kelompok=kategori&format=csv");
        $csv->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Kategori;Jumlah;"Sesi paket"', $csv->streamedContent());

        // Izin
        $this->as('dokter@eklinik.test');
        $this->getJson("/api/laporan/penjualan?dari={$dari}&sampai={$sampai}")->assertForbidden();
    }

    public function test_laporan_paket_kewajiban_sisa_sesi(): void
    {
        $dari = today()->toDateString();
        $this->as('kasir@eklinik.test');
        $paket = $this->postJson("/api/pasiens/{$this->pasien->id}/pakets", ['paket_id' => Paket::where('kode', 'PKT-LSR6')->value('id')])->assertCreated()->json();
        $this->bayar($paket['tagihan_id']);
        $detail = $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertOk()->json();
        $item = $detail['items'][0];

        $t = $this->kunjungan('TRT-011', $item['id']);
        $this->bayar($t);

        $this->as('manajer@eklinik.test');
        $sampai = now()->toDateString();
        $res = $this->getJson("/api/laporan/paket?dari={$dari}&sampai={$sampai}")->assertOk()
            ->assertJsonPath('ringkasan.terjual.paket', 1)
            ->assertJsonPath('ringkasan.terjual.nilai', $detail['nilai'])
            ->assertJsonPath('ringkasan.terpakai.sesi', 1)
            ->assertJsonPath('ringkasan.terpakai.nilai', $item['nilai_per_sesi'])
            ->assertJsonPath('ringkasan.kewajiban.sesi', $item['jumlah_sesi'] - 1)
            ->assertJsonPath('ringkasan.kewajiban.nilai', ($item['jumlah_sesi'] - 1) * $item['nilai_per_sesi'])
            ->assertJsonPath('kewajiban.0.no_paket', $detail['no_paket'])
            ->json();
        $this->assertSame(0, $res['ringkasan']['kedaluwarsa_bersisa']['paket']);

        // CSV aman dari formula injection (nama pasien diawali "=")
        $this->pasien->update(['nama' => '=HYPERLINK("http://contoh")']);
        $csv = $this->get("/api/laporan/paket?dari={$dari}&sampai={$sampai}&format=csv")->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);

        // Lewat masa berlaku → sisa menjadi "kedaluwarsa bersisa", bukan kewajiban
        $this->travelTo(now()->addDays(400));
        $setelah = now()->toDateString();
        $this->getJson("/api/laporan/paket?dari={$setelah}&sampai={$setelah}")->assertOk()
            ->assertJsonPath('ringkasan.kewajiban.paket', 0)
            ->assertJsonPath('ringkasan.kedaluwarsa_bersisa.sesi', $item['jumlah_sesi'] - 1);
    }

    public function test_dashboard_no_show_top_treatment_dan_per_cabang(): void
    {
        $dokter = User::where('email', 'dokter@eklinik.test')->firstOrFail();
        $tindakan = Tindakan::where('kode', 'TRT-001')->firstOrFail();
        $cabangId = $dokter->cabang_id;

        // 3 booking lalu: 2 hadir, 1 tidak hadir → no-show 33,3%
        foreach ([StatusAppointment::Hadir, StatusAppointment::Hadir, StatusAppointment::TidakHadir] as $i => $status) {
            Appointment::withoutGlobalScopes()->create([
                'cabang_id' => $cabangId, 'no_booking' => "BOK-T{$i}", 'pasien_id' => $this->pasien->id, 'petugas_id' => $dokter->id,
                'mulai_at' => now()->subDays(3 + $i), 'selesai_at' => now()->subDays(3 + $i)->addMinutes(30), 'status' => $status,
            ]);
        }

        $this->kunjungan('TRT-001');

        $this->as('manajer@eklinik.test');
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('booking.no_show_30_hari.tidak_hadir', 1)
            ->assertJsonPath('booking.no_show_30_hari.persen', 33.3)
            ->assertJsonPath('top_treatment.0.tindakan_id', $tindakan->id)
            ->assertJsonPath('per_cabang', null);

        $this->as('admin@eklinik.test');
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('per_cabang.0.kunjungan', 1);
    }
}
