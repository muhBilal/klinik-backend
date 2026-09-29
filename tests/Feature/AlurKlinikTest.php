<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Icd10;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\Tindakan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlurKlinikTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function as(Role $role, ?string $email = null): User
    {
        $user = $email ? User::where('email', $email)->first() : User::where('role', $role)->first();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_login_mengembalikan_token_dan_role(): void
    {
        $this->postJson('/api/login', ['email' => 'dokter@eklinik.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'role', 'role_label']])
            ->assertJsonPath('user.role', 'dokter');

        $this->postJson('/api/login', ['email' => 'dokter@eklinik.test', 'password' => 'salah'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_role_dibatasi_sesuai_hak_akses(): void
    {
        $this->as(Role::Kasir);
        $this->postJson('/api/pasiens', [])->assertForbidden();
        $this->getJson('/api/reseps')->assertForbidden();
        $this->getJson('/api/tagihans')->assertOk();

        $this->as(Role::Perawat);
        $this->getJson('/api/users')->assertForbidden();

        $this->as(Role::Admin);
        $this->getJson('/api/users')->assertOk();
    }

    public function test_pasien_baru_mendapat_no_rm_berurutan(): void
    {
        $this->as(Role::Pendaftaran);

        $response = $this->postJson('/api/pasiens', [
            'nama' => 'Ahmad Fauzi',
            'jenis_kelamin' => 'L',
            'tanggal_lahir' => '1990-05-12',
            'nik' => '3578011205900001',
        ])->assertCreated();

        $this->assertSame(str_pad((string) Pasien::count(), 6, '0', STR_PAD_LEFT), $response->json('no_rm'));
    }

    public function test_alur_lengkap_pendaftaran_sampai_penyerahan_obat(): void
    {
        $pasien = Pasien::first();
        $dokter = User::where('email', 'dokter@eklinik.test')->first();
        $paracetamol = Obat::where('kode', 'OBT-001')->first();
        $stokAwal = $paracetamol->stok;
        $gds = Tindakan::where('kode', 'TND-001')->first();

        // 1. Pendaftaran
        $this->as(Role::Pendaftaran);
        $kunjungan = $this->postJson('/api/kunjungans', [
            'pasien_id' => $pasien->id,
            'poli_id' => $dokter->poli_id,
            'dokter_id' => $dokter->id,
            'penjamin' => 'umum',
            'keluhan' => 'Demam 3 hari',
        ])->assertCreated()->assertJsonPath('no_antrian', 1)->json();

        $this->postJson('/api/kunjungans', [
            'pasien_id' => $pasien->id, 'poli_id' => $dokter->poli_id, 'penjamin' => 'umum',
        ])->assertUnprocessable()->assertJsonValidationErrors('pasien_id');

        $id = $kunjungan['id'];

        // 2. Perawat: tanda vital. Field SOAP dari perawat diabaikan.
        $this->as(Role::Perawat);
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'tekanan_darah' => '120/80', 'nadi' => 88, 'suhu' => 38.2, 'berat_badan' => 60,
            'subjektif' => 'Demam sejak 3 hari', 'asesmen' => 'tidak boleh tersimpan',
        ])->assertOk()
            ->assertJsonPath('pemeriksaan.tekanan_darah', '120/80')
            ->assertJsonPath('pemeriksaan.asesmen', null);

        // 3. Dokter: panggil, isi SOAP + diagnosa + tindakan + resep, lalu selesai
        $this->as(Role::Dokter, 'dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertUnprocessable();
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk()->assertJsonPath('status', 'diperiksa');
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertUnprocessable()->assertJsonValidationErrors('diagnosas');

        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'subjektif' => 'Demam sejak 3 hari',
            'objektif' => 'Faring hiperemis',
            'asesmen' => 'Faringitis akut',
            'plan' => 'Terapi simtomatik',
            'diagnosas' => [['icd10_id' => Icd10::where('kode', 'J02.9')->value('id')]],
            'tindakans' => [['tindakan_id' => $gds->id]],
            'resep' => [['obat_id' => $paracetamol->id, 'jumlah' => 10, 'aturan_pakai' => '3x1 tablet sesudah makan']],
        ])->assertOk()
            ->assertJsonPath('pemeriksaan.diagnosas.0.jenis', 'primer')
            ->assertJsonPath('resep.items.0.harga', $paracetamol->harga);

        $kunjungan = $this->postJson("/api/kunjungans/{$id}/selesai")
            ->assertOk()
            ->assertJsonPath('status', 'menunggu_pembayaran')
            ->json();

        $total = 50000 + $gds->tarif + 10 * $paracetamol->harga;
        $this->assertSame($total, $kunjungan['tagihan']['total']);
        $this->assertArrayNotHasKey('items', $kunjungan['tagihan'], 'Detail kunjungan tidak perlu rincian tagihan.');

        // Riwayat pasien: kunjungan ini muncul, kecuali bila dikecualikan
        $this->getJson("/api/pasiens/{$pasien->id}/riwayat")->assertOk()->assertJsonPath('0.id', $id)
            ->assertJsonPath('0.pemeriksaan.diagnosas.0.icd10.kode', 'J02.9');
        $this->getJson("/api/pasiens/{$pasien->id}/riwayat?kecuali={$id}")->assertOk()->assertJsonCount(0);

        // Pemeriksaan tidak bisa diubah setelah ditutup
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['objektif' => 'x'])->assertUnprocessable();

        // 4. Farmasi belum boleh menyerahkan obat sebelum lunas
        $this->as(Role::Apoteker);
        $resepId = $kunjungan['resep']['id'];
        $this->postJson("/api/reseps/{$resepId}/serahkan")->assertUnprocessable()->assertJsonValidationErrors('tagihan');

        // 5. Kasir
        $this->as(Role::Kasir);
        $tagihanId = $kunjungan['tagihan']['id'];
        $this->postJson("/api/tagihans/{$tagihanId}/bayar", ['metode_bayar' => 'tunai', 'dibayar' => 1000])
            ->assertUnprocessable()->assertJsonValidationErrors('dibayar');
        $this->postJson("/api/tagihans/{$tagihanId}/bayar", ['metode_bayar' => 'tunai', 'dibayar' => 100000, 'diskon' => 5000])
            ->assertOk()
            ->assertJsonPath('status', 'lunas')
            ->assertJsonPath('grand_total', $total - 5000)
            ->assertJsonPath('kembalian', 100000 - ($total - 5000))
            ->assertJsonPath('kunjungan.status', 'selesai')
            ->assertJsonPath('kunjungan.pasien.no_rm', $pasien->no_rm)
            ->assertJsonCount(3, 'items');

        // 6. Farmasi menyerahkan obat -> stok berkurang & tercatat di kartu stok
        $this->as(Role::Apoteker);
        $this->postJson("/api/reseps/{$resepId}/serahkan")->assertOk()
            ->assertJsonPath('status', 'diserahkan')
            ->assertJsonPath('kunjungan.tagihan.status', 'lunas')
            ->assertJsonPath('items.0.obat.stok', $stokAwal - 10);
        $this->postJson("/api/reseps/{$resepId}/serahkan")->assertUnprocessable();

        $this->assertSame($stokAwal - 10, $paracetamol->refresh()->stok);
        $this->getJson("/api/obats/{$paracetamol->id}/mutasi")
            ->assertOk()
            ->assertJsonPath('data.0.jumlah', -10)
            ->assertJsonPath('data.0.stok_akhir', $stokAwal - 10);
    }

    public function test_nomor_antrian_terpisah_per_poli(): void
    {
        $this->as(Role::Pendaftaran);
        [$p1, $p2, $p3] = Pasien::take(3)->get();
        $poliUmum = User::where('email', 'dokter@eklinik.test')->value('poli_id');
        $poliGigi = User::where('email', 'dokter.gigi@eklinik.test')->value('poli_id');

        $this->postJson('/api/kunjungans', ['pasien_id' => $p1->id, 'poli_id' => $poliUmum, 'penjamin' => 'umum'])->assertJsonPath('no_antrian', 1);
        $this->postJson('/api/kunjungans', ['pasien_id' => $p2->id, 'poli_id' => $poliUmum, 'penjamin' => 'bpjs', 'no_penjamin' => '0001234567890'])->assertJsonPath('no_antrian', 2);
        $this->postJson('/api/kunjungans', ['pasien_id' => $p3->id, 'poli_id' => $poliGigi, 'penjamin' => 'umum'])->assertJsonPath('no_antrian', 1);

        $this->getJson("/api/kunjungans?poli_id={$poliUmum}")->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_respons_list_hanya_memuat_data_seperlunya(): void
    {
        $this->as(Role::Admin);
        $pasien = Pasien::first();

        // simple=1 (autocomplete) tidak menghitung total
        $this->getJson('/api/icd10s?q=J&simple=1&per_page=5')
            ->assertOk()
            ->assertJsonMissingPath('total')
            ->assertJsonStructure(['data' => [['id', 'kode', 'nama']]])
            ->assertJsonMissingPath('data.0.created_at');
        $this->getJson('/api/icd10s')->assertOk()->assertJsonStructure(['total', 'last_page']);

        // Dropdown poli aktif ringkas, master poli lengkap
        $this->getJson('/api/polis?aktif=1')->assertOk()
            ->assertJsonStructure([['id', 'kode', 'nama']])
            ->assertJsonMissingPath('0.dokters_count')
            ->assertJsonMissingPath('0.tarif_konsultasi');
        $this->getJson('/api/polis')->assertOk()->assertJsonStructure([['id', 'tarif_konsultasi', 'dokters_count']]);

        // Detail pasien ringkas tanpa riwayat kunjungan
        $this->getJson("/api/pasiens/{$pasien->id}?ringkas=1")->assertOk()
            ->assertJsonPath('no_rm', $pasien->no_rm)
            ->assertJsonMissingPath('kunjungans');
        $this->getJson("/api/pasiens/{$pasien->id}")->assertOk()->assertJsonStructure(['kunjungans']);

        // Riwayat mengecualikan kunjungan yang sedang diperiksa
        $this->getJson("/api/pasiens/{$pasien->id}/riwayat?kecuali=999999")->assertOk();
    }

    public function test_stok_tidak_boleh_minus(): void
    {
        $this->as(Role::Apoteker);
        $obat = Obat::first();

        $this->postJson("/api/obats/{$obat->id}/mutasi", ['jenis' => 'keluar', 'jumlah' => $obat->stok + 1])
            ->assertUnprocessable()->assertJsonValidationErrors('jumlah');

        $this->postJson("/api/obats/{$obat->id}/mutasi", ['jenis' => 'penyesuaian', 'jumlah' => 42, 'keterangan' => 'Stok opname'])
            ->assertCreated()->assertJsonPath('obat.stok', 42);
    }
}
