<?php

namespace Tests\Feature;

use App\Models\Icd10;
use App\Models\Kunjungan;
use App\Models\Paket;
use App\Models\PaketPasien;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Laporan & dashboard (PRD LP-01..03): penjualan per treatment/dokter/cabang/metode bayar dengan refund di periode refund,
 * kembalian tunai tidak dihitung sebagai penerimaan, laporan paket (terjual, terpakai, sisa kewajiban, hangus), dashboard harian.
 */
class LaporanTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);
    }

    private function as(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function tindakan(string $kode): int
    {
        return Tindakan::where('kode', $kode)->value('id');
    }

    /** Kunjungan Poli Estetika oleh dokter@ sampai selesai diperiksa; mengembalikan kunjungan + tagihannya. */
    private function kunjungan(Pasien $pasien, array $tindakans): Kunjungan
    {
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $pasien->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');
        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => $tindakans])->assertOk();
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        return Kunjungan::findOrFail($id);
    }

    private function bayar(int $tagihanId, string $metode, int $jumlah, int $diskon = 0): void
    {
        $this->as('kasir@eklinik.test');
        $this->postJson("/api/tagihans/{$tagihanId}/bayar", ['pembayarans' => [['metode' => $metode, 'jumlah' => $jumlah]], 'diskon' => $diskon])
            ->assertOk()->assertJsonPath('status', 'lunas');
    }

    /** Jual paket katalog ke pasien & lunasi (transfer). */
    private function jualPaket(string $kode, Pasien $pasien): array
    {
        $this->as('kasir@eklinik.test');
        $paket = $this->postJson("/api/pasiens/{$pasien->id}/pakets", ['paket_id' => Paket::where('kode', $kode)->value('id')])->assertCreated()->json();
        $this->bayar($paket['tagihan_id'], 'transfer', Paket::where('kode', $kode)->value('harga'));

        return $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertOk()->json();
    }

    public function test_penjualan_per_treatment_dokter_metode_refund_dan_kembalian(): void
    {
        [$p1, $p2, $p3, $p4] = Pasien::orderBy('id')->take(4)->get()->all();
        $this->as('kasir@eklinik.test');
        $shift = $this->postJson('/api/shift-kas', ['modal_awal' => 500000])->assertCreated()->json();

        // A: konsultasi 100rb + botox 3,5 jt + facial 350rb = 3.950.000, tunai 4 jt (kembalian 50rb)
        $a = $this->kunjungan($p1, [['tindakan_id' => $this->tindakan('TRT-001')], ['tindakan_id' => $this->tindakan('TRT-021')]]);
        $this->bayar($a->tagihan->id, 'tunai', 4000000);
        // B: konsultasi 100rb + laser 1,2 jt = 1.300.000, diskon 130rb, QRIS
        $b = $this->kunjungan($p2, [['tindakan_id' => $this->tindakan('TRT-011')]]);
        $this->bayar($b->tagihan->id, 'qris', 1170000, 130000);
        // Penjualan paket di kasir (tanpa kunjungan) 6 jt transfer; C belum dibayar → tidak dihitung
        $this->jualPaket('PKT-LSR6', $p3);
        $this->kunjungan($p4, [['tindakan_id' => $this->tindakan('TRT-021')]]);

        // Rekap shift: tunai = uang yang benar-benar masuk (tanpa kembalian)
        $this->as('kasir@eklinik.test');
        $this->getJson("/api/shift-kas/{$shift['id']}")->assertOk()
            ->assertJsonPath('rekap.kas_seharusnya', 500000 + 3950000)
            ->assertJsonPath('rekap.total', 3950000 + 1170000 + 6000000);

        // A direfund: tetap penjualan periode ini, refund dicatat sebagai pengurang
        $this->as('admin@eklinik.test');
        $this->postJson("/api/tagihans/{$a->tagihan->id}/refund", ['alasan_refund' => 'Komplain'])->assertOk();
        $this->as('kasir@eklinik.test');
        $this->getJson("/api/shift-kas/{$shift['id']}")->assertOk()->assertJsonPath('rekap.total_refund', 3950000);

        $r = $this->getJson('/api/laporan/penjualan')->assertOk()->json();
        $this->assertSame([
            'transaksi' => 3, 'bruto' => 11250000, 'diskon' => 130000, 'promo' => 0, 'penjualan_bersih' => 11120000, 'pajak' => 0,
            'total' => 11120000, 'refund' => ['transaksi' => 1, 'total' => 3950000, 'paket_sisa' => 0], 'total_setelah_refund' => 7170000,
        ], $r['ringkasan']);

        $treatment = collect($r['per_treatment'])->keyBy('kode');
        $this->assertSame([1, 3500000, 3500000], [$treatment['TRT-001']['jumlah'], $treatment['TRT-001']['bruto'], $treatment['TRT-001']['neto']]);
        // Diskon tagihan B dialokasikan proporsional: laser 1,2 jt − 120rb, konsultasi 100rb − 10rb
        $this->assertSame(1080000, $treatment['TRT-011']['neto']);
        $this->assertSame([2, 200000, 190000], [$treatment['KNS-001']['jumlah'], $treatment['KNS-001']['bruto'], $treatment['KNS-001']['neto']]);
        $this->assertSame(6000000, collect($r['per_kategori'])->firstWhere('kategori', 'paket')['neto']);

        $dokter = collect($r['per_dokter'])->keyBy('nama');
        $this->assertSame([2, 5120000], [$dokter['dr. Andi Wijaya']['transaksi'], $dokter['dr. Andi Wijaya']['penjualan_bersih']]);
        $this->assertSame(6000000, $dokter['Penjualan langsung (tanpa kunjungan)']['penjualan_bersih']);
        $this->assertSame([11120000], array_column($r['per_cabang'], 'total'));
        $this->assertSame([[today()->toDateString(), 3, 11120000]], array_map(fn ($h) => array_values($h), $r['per_hari']));

        $metode = collect($r['per_metode'])->keyBy('metode');
        $this->assertSame(['diterima' => 3950000, 'dikembalikan' => 3950000, 'bersih' => 0], collect($metode['tunai'])->except('metode')->all());
        $this->assertSame(1170000, $metode['qris']['bersih']);
        $this->assertSame(6000000, $metode['transfer']['bersih']);

        // Periode lain kosong; refund tercatat di periode refund-nya
        $kemarin = today()->subDay()->toDateString();
        $this->getJson("/api/laporan/penjualan?mulai={$kemarin}&selesai={$kemarin}")->assertOk()
            ->assertJsonPath('ringkasan.transaksi', 0)->assertJsonPath('ringkasan.refund.total', 0);
    }

    public function test_laporan_paket_terjual_terpakai_sisa_kewajiban_dan_hangus(): void
    {
        [$p1, $p2] = Pasien::orderBy('id')->take(2)->get()->all();

        // Laser 6x (6 jt → 1 jt/sesi), dua sesi dipakai di satu kunjungan
        $laser = $this->jualPaket('PKT-LSR6', $p1);
        $k = $this->kunjungan($p1, [['tindakan_id' => $this->tindakan('TRT-011'), 'jumlah' => 2, 'paket_pasien_item_id' => $laser['items'][0]['id']]]);
        $this->bayar($k->tagihan->id, 'tunai', $k->tagihan->grand_total);
        PaketPasien::whereKey($laser['id'])->update(['berlaku_sampai' => today()->addDays(10)]);

        // Scaling 2x (450rb) sudah kedaluwarsa kemarin tanpa dipakai → hangus
        $scaling = $this->jualPaket('PKT-SCL2', $p2);
        PaketPasien::whereKey($scaling['id'])->update(['berlaku_sampai' => today()->subDay()]);

        $mulai = today()->subDays(3)->toDateString();
        $r = $this->getJson("/api/laporan/paket?mulai={$mulai}")->assertOk()->json();
        $this->assertSame([
            'terjual' => 2, 'nilai_terjual' => 6450000, 'sesi_dipakai' => 2, 'nilai_dipakai' => 2000000, 'refund' => 0, 'hangus' => 450000,
            'paket_aktif' => 1, 'sisa_sesi' => 4, 'sisa_kewajiban' => 4000000,
        ], $r['ringkasan']);

        $perPaket = collect($r['per_paket'])->keyBy('nama');
        $this->assertSame([1, 6000000, 2, 2000000, 4000000], [$perPaket['Laser toning 6x']['terjual'], $perPaket['Laser toning 6x']['nilai_terjual'],
            $perPaket['Laser toning 6x']['sesi_dipakai'], $perPaket['Laser toning 6x']['nilai_dipakai'], $perPaket['Laser toning 6x']['sisa_kewajiban']]);
        $this->assertSame([$laser['no_paket']], array_column($r['segera_kedaluwarsa'], 'no_paket'));
        $this->assertSame(4000000, $r['segera_kedaluwarsa'][0]['sisa_nilai']);

        // Penjualan treatment: sesi paket dihitung terpisah (ditagih Rp 0), bernilai per sesi
        $laserTreatment = collect($this->getJson('/api/laporan/penjualan')->json('per_treatment'))->firstWhere('kode', 'TRT-011');
        $this->assertSame([0, 0, 2, 2000000], [$laserTreatment['jumlah'], $laserTreatment['neto'], $laserTreatment['sesi_paket'], $laserTreatment['nilai_sesi_paket']]);
    }

    public function test_hak_akses_validasi_dan_dashboard_harian(): void
    {
        $this->as('dokter@eklinik.test');
        $this->getJson('/api/laporan/penjualan')->assertForbidden();
        $this->as('kasir@eklinik.test');
        $this->getJson('/api/laporan/paket?mulai=2026-10-05&selesai=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors('selesai');
        $this->getJson('/api/laporan/penjualan?mulai=2025-01-01&selesai=2026-06-01')->assertUnprocessable()->assertJsonValidationErrors('selesai');

        // Kunjungan facial hari ini + satu booking tidak hadir (no-show)
        $pasien = Pasien::orderBy('id')->first();
        $k = $this->kunjungan($pasien, [['tindakan_id' => $this->tindakan('TRT-021')]]);
        DB::table('appointments')->insert(['cabang_id' => $k->cabang_id, 'no_booking' => 'BK-UJI-1', 'pasien_id' => $pasien->id,
            'mulai_at' => today()->setTime(9, 0), 'selesai_at' => today()->setTime(10, 0), 'status' => 'tidak_hadir',
            'created_at' => now(), 'updated_at' => now()]);

        $this->as('kasir@eklinik.test');
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('booking.tidak_hadir', 1)
            ->assertJsonPath('top_treatment.0.nama', 'Facial acne')
            ->assertJsonPath('per_cabang', null);

        // Administrator lintas cabang tanpa pilihan cabang → rincian per cabang
        $this->as('admin@eklinik.test');
        $this->getJson('/api/dashboard')->assertOk()
            ->assertJsonPath('per_cabang.0.kode', 'UTAMA')->assertJsonPath('per_cabang.0.kunjungan', 1)
            ->assertJsonPath('per_cabang.0.tidak_hadir', 1);
        // Dokter: tanpa omzet
        $this->as('dokter@eklinik.test');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonPath('pendapatan_hari_ini', null);
    }
}
