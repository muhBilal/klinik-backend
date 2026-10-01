<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Cabang;
use App\Models\Icd10;
use App\Models\KategoriTindakan;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Katalog treatment (PRD TR-01): kategori, durasi, harga per cabang, BHP standar.
 */
class KatalogTreatmentTest extends TestCase
{
    use RefreshDatabase;

    private Cabang $utama;

    private Cabang $selatan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);

        $this->utama = Cabang::where('kode', 'UTAMA')->first();
        $this->selatan = Cabang::create(['kode' => 'SEL', 'nama' => 'Cabang Selatan']);
    }

    private function as(string $email, ?int $cabangHeader = null): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);
        $this->withHeaders(['X-Cabang-Id' => $cabangHeader ? (string) $cabangHeader : '']);

        return $user;
    }

    private function payload(array $extra = []): array
    {
        return [
            'kode' => 'TRT-900',
            'nama' => 'Skin booster',
            'kategori_id' => KategoriTindakan::where('nama', 'Injeksi Estetika')->value('id'),
            'durasi_menit' => 40,
            'buffer_menit' => 10,
            'tarif' => 2000000,
            'is_active' => true,
            ...$extra,
        ];
    }

    public function test_kategori_crud_dan_tidak_bisa_dihapus_bila_dipakai(): void
    {
        $this->as('admin@eklinik.test');

        $id = $this->postJson('/api/kategori-tindakans', ['nama' => 'Body Contouring', 'deskripsi' => 'HIFU, cryolipolysis'])
            ->assertCreated()->json('id');
        $this->postJson('/api/kategori-tindakans', ['nama' => 'Body Contouring'])->assertUnprocessable()->assertJsonValidationErrors('nama');
        $this->putJson("/api/kategori-tindakans/{$id}", ['nama' => 'Body Contouring', 'is_active' => false])->assertOk()
            ->assertJsonPath('is_active', false);

        // Dropdown hanya kategori aktif; halaman master memuat jumlah treatment
        $this->assertNotContains($id, array_column($this->getJson('/api/kategori-tindakans?aktif=1')->json(), 'id'));
        $this->getJson('/api/kategori-tindakans?q=injeksi')->assertJsonCount(1)->assertJsonPath('0.tindakans_count', 2);

        $injeksi = KategoriTindakan::where('nama', 'Injeksi Estetika')->value('id');
        $this->deleteJson("/api/kategori-tindakans/{$injeksi}")->assertUnprocessable();
        $this->deleteJson("/api/kategori-tindakans/{$id}")->assertOk();
        $this->assertSoftDeleted('kategori_tindakans', ['id' => $id]);

        // Semua pengguna login boleh membaca, hanya master.kelola yang boleh mengubah
        $this->as('kasir@eklinik.test');
        $this->getJson('/api/kategori-tindakans?aktif=1')->assertOk();
        $this->postJson('/api/kategori-tindakans', ['nama' => 'X'])->assertForbidden();
        $this->postJson('/api/tindakans', $this->payload())->assertForbidden();
    }

    public function test_simpan_treatment_dengan_harga_cabang_dan_bhp(): void
    {
        $this->as('admin@eklinik.test');
        $toksin = Obat::where('kode', 'OBT-021')->first();
        $spuit = Obat::where('kode', 'OBT-024')->first();

        $id = $this->postJson('/api/tindakans', $this->payload([
            'hargas' => [
                ['cabang_id' => $this->selatan->id, 'tarif' => 2250000],
                ['cabang_id' => $this->utama->id, 'tarif' => 0, 'tersedia' => false],
            ],
            'bhps' => [['obat_id' => $toksin->id, 'jumlah' => 0.25], ['obat_id' => $spuit->id, 'jumlah' => 2]],
        ]))->assertCreated()
            ->assertJsonPath('kategori.nama', 'Injeksi Estetika')
            ->assertJsonPath('durasi_menit', 40)
            ->assertJsonCount(2, 'hargas')
            ->assertJsonPath('bhps.0.obat.satuan', 'vial')
            ->json('id');

        $bhp = collect($this->getJson("/api/tindakans/{$id}")->assertOk()->json('bhps'))->keyBy('obat_id');
        $this->assertEquals(0.25, $bhp[$toksin->id]['jumlah']);

        // Replace-all: harga Selatan diubah, harga Utama dihapus, satu BHP dihapus
        $this->putJson("/api/tindakans/{$id}", $this->payload([
            'hargas' => [['cabang_id' => $this->selatan->id, 'tarif' => 2300000]],
            'bhps' => [['obat_id' => $toksin->id, 'jumlah' => 0.25]],
        ]))->assertOk()
            ->assertJsonCount(1, 'hargas')->assertJsonPath('hargas.0.tarif', 2300000)
            ->assertJsonCount(1, 'bhps');

        // Tanpa key hargas/bhps = tidak diubah; buffer kosong = 0
        $this->putJson("/api/tindakans/{$id}", $this->payload(['nama' => 'Skin booster HA', 'buffer_menit' => null]))->assertOk()
            ->assertJsonPath('buffer_menit', 0)->assertJsonCount(1, 'hargas')->assertJsonCount(1, 'bhps');

        // Audit: harga per cabang & BHP tercatat per baris; BHP yang tidak berubah tidak menambah baris "ubah"
        $harga = AuditLog::where('tipe', 'tindakan_harga')->orderBy('id')->get();
        $this->assertSame(['buat', 'buat', 'hapus', 'ubah'], $harga->pluck('aksi')->all());
        $ubah = $harga->firstWhere('aksi', 'ubah');
        $this->assertSame($this->selatan->id, $ubah->cabang_id);
        $this->assertSame(['lama' => 2250000, 'baru' => 2300000], $ubah->perubahan['tarif']);
        $this->assertSame('TRT-900 @ SEL', $ubah->label);
        $this->assertSame($this->utama->id, $harga->firstWhere('aksi', 'hapus')->cabang_id);
        $this->assertSame(0, AuditLog::where(['tipe' => 'tindakan_bhp', 'aksi' => 'ubah'])->count());
        $this->assertSame(1, AuditLog::where(['tipe' => 'tindakan_bhp', 'aksi' => 'hapus'])->count());
    }

    public function test_validasi_treatment(): void
    {
        $this->as('admin@eklinik.test');
        $obat = Obat::first();

        $this->postJson('/api/tindakans', $this->payload([
            'durasi_menit' => 0,
            'hargas' => [['cabang_id' => $this->utama->id, 'tarif' => 1], ['cabang_id' => $this->utama->id, 'tarif' => 2]],
            'bhps' => [['obat_id' => $obat->id, 'jumlah' => 0], ['obat_id' => 99999, 'jumlah' => 1.2345]],
        ]))->assertUnprocessable()->assertJsonValidationErrors([
            'durasi_menit', 'hargas.0.cabang_id', 'bhps.0.jumlah', 'bhps.1.obat_id', 'bhps.1.jumlah',
        ]);

        // Cabang yang sudah dihapus tidak bisa diberi harga
        $this->selatan->delete();
        $this->postJson('/api/tindakans', $this->payload(['hargas' => [['cabang_id' => $this->selatan->id, 'tarif' => 1]]]))
            ->assertUnprocessable()->assertJsonValidationErrors('hargas.0.cabang_id');
    }

    public function test_daftar_treatment_memakai_harga_cabang(): void
    {
        $laser = Tindakan::where('kode', 'TRT-011')->first();
        $ipl = Tindakan::where('kode', 'TRT-012')->first();
        $laser->hargas()->create(['cabang_id' => $this->selatan->id, 'tarif' => 1000000]);
        $ipl->hargas()->create(['cabang_id' => $this->selatan->id, 'tarif' => $ipl->tarif, 'tersedia' => false]);

        $cari = fn (string $url) => collect($this->getJson($url)->assertOk()->json('data'))->keyBy('kode');

        // Staf cabang Selatan: harga khusus & treatment yang tidak dilayani disembunyikan dari pilihan
        User::factory()->create(['email' => 'dokter.sel@eklinik.test', 'role' => 'dokter',
            'poli_id' => User::where('email', 'dokter@eklinik.test')->value('poli_id'), 'cabang_id' => $this->selatan->id]);
        $this->as('dokter.sel@eklinik.test');
        $list = $cari('/api/tindakans?aktif=1&q=TRT-01');
        $this->assertSame(1000000, $list['TRT-011']['tarif_cabang']);
        $this->assertSame(1200000, $list['TRT-011']['tarif']);
        $this->assertTrue($list['TRT-011']['tersedia']);
        $this->assertArrayNotHasKey('TRT-012', $list->all());

        // Cabang Utama (tanpa harga khusus) = harga dasar; master tetap menampilkan semua treatment
        $this->as('admin@eklinik.test', $this->utama->id);
        $this->assertSame(1200000, $cari('/api/tindakans?q=TRT-011')['TRT-011']['tarif_cabang']);
        $this->as('admin@eklinik.test');
        $list = $cari("/api/tindakans?q=TRT-01&cabang_id={$this->selatan->id}");
        $this->assertFalse($list['TRT-012']['tersedia']);
        $this->assertSame(1, $list['TRT-012']['hargas_count']);
        $this->assertSame(1, $list['TRT-011']['bhps_count']);

        // Filter kategori
        $kategori = KategoriTindakan::where('nama', 'Laser & Energy Device')->value('id');
        $this->assertEqualsCanonicalizing(['TRT-011', 'TRT-012'], $cari("/api/tindakans?kategori_id={$kategori}")->keys()->all());
    }

    public function test_pemeriksaan_snapshot_tarif_cabang_kunjungan(): void
    {
        $dokterUtama = User::where('email', 'dokter@eklinik.test')->first();
        $dokterSel = User::factory()->create(['email' => 'dokter.sel@eklinik.test', 'role' => 'dokter',
            'poli_id' => $dokterUtama->poli_id, 'cabang_id' => $this->selatan->id, 'sip' => '503/SIP-DU/009/2026']);
        // Fokus test ini harga cabang; kewajiban informed consent laser diuji di RmeEstetikaTest.
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);
        $laser = Tindakan::where('kode', 'TRT-011')->first();
        $ipl = Tindakan::where('kode', 'TRT-012')->first();
        $laser->hargas()->create(['cabang_id' => $this->selatan->id, 'tarif' => 1000000]);
        $ipl->hargas()->create(['cabang_id' => $this->selatan->id, 'tarif' => 0, 'tersedia' => false]);

        $periksa = function (User $dokter, int $cabangId) {
            $this->as('admin@eklinik.test', $cabangId);
            $id = $this->postJson('/api/kunjungans', ['pasien_id' => Pasien::value('id'),
                'poli_id' => $dokter->poli_id, 'penjamin' => 'umum'])->assertCreated()->json('id');
            $this->as($dokter->email);
            $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();

            return $id;
        };
        $diagnosa = [['icd10_id' => Icd10::value('id')]];

        // Cabang Selatan: harga khusus di-snapshot; treatment yang tidak dilayani ditolak
        $id = $periksa($dokterSel, $this->selatan->id);
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['diagnosas' => $diagnosa,
            'tindakans' => [['tindakan_id' => $laser->id], ['tindakan_id' => $ipl->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('tindakans.1.tindakan_id');
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['diagnosas' => $diagnosa, 'tindakans' => [['tindakan_id' => $laser->id]]])
            ->assertOk()->assertJsonPath('tindakans.0.tarif', 1000000);
        $selesai = $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();
        $this->assertSame($selesai->json('konsultasi.tarif_cabang') + 1000000, $selesai->json('tagihan.total'));

        // Cabang Utama: harga dasar, IPL tetap dilayani
        $id = $periksa($dokterUtama, $this->utama->id);
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['diagnosas' => $diagnosa,
            'tindakans' => [['tindakan_id' => $laser->id], ['tindakan_id' => $ipl->id]]])
            ->assertOk()->assertJsonPath('tindakans.0.tarif', 1200000)->assertJsonPath('tindakans.1.tarif', 900000);
    }

    public function test_hapus_treatment_dan_obat_bhp(): void
    {
        $this->as('admin@eklinik.test');
        $toksin = Obat::where('kode', 'OBT-021')->first();
        $botox = Tindakan::where('kode', 'TRT-001')->first();

        // Obat yang menjadi BHP standar treatment aktif tidak bisa dihapus
        $this->deleteJson("/api/obats/{$toksin->id}")->assertUnprocessable();

        $this->deleteJson("/api/tindakans/{$botox->id}")->assertOk();
        $this->assertSoftDeleted('tindakans', ['id' => $botox->id]);
        $this->assertDatabaseHas('tindakan_bhps', ['tindakan_id' => $botox->id, 'obat_id' => $toksin->id]);

        // Treatment terhapus tidak bisa dipakai di pemeriksaan
        $this->assertNotContains($botox->id, array_column($this->getJson('/api/tindakans?aktif=1&per_page=100')->json('data'), 'id'));

        $this->deleteJson("/api/obats/{$toksin->id}")->assertOk();
    }
}
