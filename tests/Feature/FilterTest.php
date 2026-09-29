<?php

namespace Tests\Feature;

use App\Models\Icd10;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Tindakan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Filter di setiap tabel harus dijalankan di server (bukan hanya menyaring halaman yang sedang tampil).
 */
class FilterTest extends TestCase
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

    /** Kunjungan lewat alur API asli sampai tagihan terbit; bila $metode diisi, tagihan langsung dibayar. */
    private function kunjunganSelesai(Pasien $pasien, string $penjamin, ?string $metode = null): array
    {
        $dokter = User::where('email', 'dokter@eklinik.test')->firstOrFail();

        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', [
            'pasien_id' => $pasien->id,
            'poli_id' => $dokter->poli_id,
            'dokter_id' => $dokter->id,
            'penjamin' => $penjamin,
            'no_penjamin' => $penjamin === 'umum' ? null : '0001234567890',
        ])->assertCreated()->json('id');

        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::where('kode', 'J02.9')->value('id')]],
            'resep' => [['obat_id' => Obat::where('kode', 'OBT-001')->value('id'), 'jumlah' => 1, 'aturan_pakai' => '3 x 1']],
        ])->assertOk();
        $kunjungan = $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk()->json();

        if ($metode) {
            $this->as('kasir@eklinik.test');
            $this->postJson("/api/tagihans/{$kunjungan['tagihan']['id']}/bayar", ['metode_bayar' => $metode, 'dibayar' => 1_000_000])->assertOk();
        }

        return ['kunjungan' => $id, 'tagihan' => $kunjungan['tagihan']['id'], 'resep' => $kunjungan['resep']['id']];
    }

    public function test_filter_pasien_jenis_kelamin_golongan_darah_bpjs(): void
    {
        $this->as('pendaftaran@eklinik.test');

        $laki = $this->getJson('/api/pasiens?jenis_kelamin=L&per_page=100')->assertOk();
        $this->assertSame(Pasien::where('jenis_kelamin', 'L')->count(), $laki->json('total'));
        $this->assertEqualsCanonicalizing(['L'], array_unique(array_column($laki->json('data'), 'jenis_kelamin')));

        $tanpaBpjs = $this->getJson('/api/pasiens?bpjs=tidak&per_page=100')->assertOk();
        $this->assertSame(Pasien::whereNull('no_bpjs')->count(), $tanpaBpjs->json('total'));
        $this->assertSame([null], array_values(array_unique(array_column($tanpaBpjs->json('data'), 'no_bpjs'))));

        $this->assertSame(
            Pasien::where('golongan_darah', 'AB')->whereNotNull('no_bpjs')->count(),
            $this->getJson('/api/pasiens?golongan_darah=AB&bpjs=ya')->json('total'),
        );
    }

    public function test_filter_kunjungan_tagihan_dan_resep(): void
    {
        [$p1, $p2] = Pasien::take(2)->get();
        $umumLunas = $this->kunjunganSelesai($p1, 'umum', 'tunai');
        $bpjsBelum = $this->kunjunganSelesai($p2, 'bpjs');
        $poliUmum = User::where('email', 'dokter@eklinik.test')->value('poli_id');
        $poliGigi = Poli::where('kode', 'GIGI')->value('id');

        // Kunjungan per penjamin; nilai penjamin tidak dikenal ditolak
        $this->as('pendaftaran@eklinik.test');
        $this->getJson('/api/kunjungans?penjamin=bpjs')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bpjsBelum['kunjungan']);
        $this->getJson('/api/kunjungans?penjamin=gratis')->assertUnprocessable()->assertJsonValidationErrors('penjamin');

        // Tagihan per metode bayar, penjamin, poli
        $this->as('kasir@eklinik.test');
        $this->getJson('/api/tagihans?metode_bayar=tunai')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $umumLunas['tagihan']);
        $this->getJson('/api/tagihans?penjamin=bpjs')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bpjsBelum['tagihan']);
        $this->getJson("/api/tagihans?poli_id={$poliUmum}")->assertJsonCount(2, 'data');
        $this->getJson("/api/tagihans?poli_id={$poliGigi}")->assertJsonCount(0, 'data');

        // Resep per status pembayaran & poli
        $this->as('apoteker@eklinik.test');
        $this->getJson('/api/reseps?pembayaran=lunas')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $umumLunas['resep']);
        $this->getJson('/api/reseps?pembayaran=belum')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $bpjsBelum['resep']);
        $this->getJson("/api/reseps?poli_id={$poliGigi}")->assertJsonCount(0, 'data');
    }

    public function test_filter_master_data(): void
    {
        $this->as('admin@eklinik.test');

        // Obat: status aktif/nonaktif & satuan
        $nonaktif = Obat::where('kode', 'OBT-020')->first();
        $nonaktif->update(['is_active' => false]);
        $this->getJson('/api/obats?status=nonaktif')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $nonaktif->id);
        $this->assertNotContains($nonaktif->id, array_column($this->getJson('/api/obats?status=aktif&per_page=100')->json('data'), 'id'));
        $this->assertEqualsCanonicalizing(['kapsul'], array_unique(array_column($this->getJson('/api/obats?satuan=kapsul')->json('data'), 'satuan')));

        // Tindakan & poli: status, poli: pencarian nama
        Tindakan::where('kode', 'TND-008')->update(['is_active' => false]);
        $this->getJson('/api/tindakans?status=nonaktif')->assertJsonCount(1, 'data')->assertJsonPath('data.0.kode', 'TND-008');
        $this->getJson('/api/polis?q=gigi')->assertJsonCount(1)->assertJsonPath('0.kode', 'GIGI');
        $this->getJson('/api/polis?status=nonaktif')->assertJsonCount(0);

        // ICD-10 per huruf bab (huruf kecil tetap diterima)
        $k = $this->getJson('/api/icd10s?huruf=k&per_page=100')->assertOk();
        $this->assertSame(Icd10::where('kode', 'like', 'K%')->count(), $k->json('total'));
        $this->assertTrue(collect($k->json('data'))->every(fn ($row) => str_starts_with($row['kode'], 'K')));

        // Pengguna per role, poli, status
        $this->getJson('/api/users?role=dokter&status=aktif')->assertJsonCount(3, 'data');
        $poliUmum = User::where('email', 'dokter@eklinik.test')->value('poli_id');
        $this->getJson("/api/users?poli_id={$poliUmum}")->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'dokter@eklinik.test');
        User::where('email', 'kasir@eklinik.test')->update(['is_active' => false]);
        $this->getJson('/api/users?status=nonaktif')->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'kasir@eklinik.test');
    }

    public function test_filter_kartu_stok_per_jenis(): void
    {
        $this->as('apoteker@eklinik.test');
        $obat = Obat::where('kode', 'OBT-001')->first();
        $this->postJson("/api/obats/{$obat->id}/mutasi", ['jenis' => 'keluar', 'jumlah' => 5, 'keterangan' => 'Kadaluarsa'])->assertCreated();

        $this->getJson("/api/obats/{$obat->id}/mutasi?jenis=keluar")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.jumlah', -5);
        $this->getJson("/api/obats/{$obat->id}/mutasi?jenis=masuk")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.keterangan', 'Stok awal');
    }
}
