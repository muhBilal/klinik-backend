<?php

namespace Tests\Feature;

use App\Models\Icd10;
use App\Models\Icd9cm;
use App\Models\Kunjungan;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\PasienAlergi;
use App\Models\Poli;
use App\Models\Resep;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Carbon\CarbonInterface;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PRD v2 #7: resep racikan (FR-01), STR/SIP & peringatan (AD-05), nomor BPOM produk (AD-06), impor master resmi (AD-10),
 * kop dokumen (AD-04).
 */
class RegulasiRacikanTest extends TestCase
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

    private function obat(string $kode): Obat
    {
        return Obat::where('kode', $kode)->firstOrFail();
    }

    /** Kunjungan Poli Kulit yang sudah dipanggil dokter kulit. */
    private function kunjungan(): int
    {
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $this->pasien->id, 'poli_id' => Poli::where('kode', 'KULIT')->value('id'), 'penjamin' => 'umum'])
            ->assertCreated()->json('id');
        $this->as('dokter.kulit@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();

        return $id;
    }

    private function racikan(array $komponen, int $jumlah = 2): array
    {
        return [
            'racikan' => true, 'nama_racikan' => 'Krim malam', 'bentuk' => 'krim', 'jumlah_racikan' => 30, 'satuan_racikan' => 'g',
            'jumlah' => $jumlah, 'aturan_pakai' => 'Oles tipis malam hari', 'komponen' => $komponen,
        ];
    }

    public function test_resep_racikan_ditagih_dan_memotong_stok_komponen(): void
    {
        app(PengaturanService::class)->simpan(['farmasi' => ['biaya_racik' => 5000]]);
        $krim = $this->obat('OBT-023'); // fraksional
        $tablet = $this->obat('OBT-001');
        $id = $this->kunjungan();
        $simpan = fn (array $resep) => $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::value('id')]], 'resep' => $resep,
        ]);

        // Validasi: racikan tanpa komponen; komponen non-fraksional desimal; obat jadi tanpa obat_id
        $simpan([$this->racikan([])])->assertStatus(422)->assertJsonValidationErrors('resep.0.komponen');
        $simpan([$this->racikan([['obat_id' => $tablet->id, 'jumlah' => 1.5]])])->assertStatus(422)->assertJsonValidationErrors('resep.0.komponen.0.jumlah');
        $simpan([['jumlah' => 1, 'aturan_pakai' => '3x1']])->assertStatus(422)->assertJsonValidationErrors('resep.0.obat_id');

        $simpan([
            $this->racikan([['obat_id' => $krim->id, 'jumlah' => 0.5], ['obat_id' => $tablet->id, 'jumlah' => 2]]),
            ['obat_id' => $tablet->id, 'jumlah' => 10, 'aturan_pakai' => '3x1'],
        ])->assertOk()
            ->assertJsonPath('resep.items.0.racikan', true)
            ->assertJsonPath('resep.items.0.komponens.1.obat.nama', $tablet->nama);

        $hargaRacikan = (int) ceil(0.5 * $krim->harga) + 2 * $tablet->harga + 5000;
        $resep = Resep::withoutGlobalScopes()->where('kunjungan_id', $id)->firstOrFail();
        $this->assertSame($hargaRacikan, $resep->items()->where('racikan', true)->value('harga'));

        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        // Tagihan: "Racikan Krim malam (krim 30 g)" × 2
        $this->as('kasir@eklinik.test');
        $tagihan = Kunjungan::withoutGlobalScopes()->findOrFail($id)->tagihans()->withoutGlobalScopes()->firstOrFail();
        $item = collect($this->getJson("/api/tagihans/{$tagihan->id}")->json('items'))->firstWhere('deskripsi', 'Racikan Krim malam (krim 30 g)');
        $this->assertNotNull($item);
        $this->assertSame(2, $item['jumlah']);
        $this->assertSame($hargaRacikan, $item['harga']);
        $grand = $this->getJson("/api/tagihans/{$tagihan->id}")->json('grand_total');
        $this->postJson("/api/tagihans/{$tagihan->id}/bayar", ['pembayarans' => [['metode' => 'tunai', 'jumlah' => $grand]]])->assertOk();

        // Penyerahan: stok komponen berkurang komponen × jumlah racikan (+ obat jadi)
        $apoteker = $this->as('apoteker@eklinik.test');
        $stokKrim = $krim->stokDi($apoteker->cabang_id);
        $stokTablet = $tablet->stokDi($apoteker->cabang_id);
        $this->postJson("/api/reseps/{$resep->id}/serahkan")->assertOk()->assertJsonPath('items.0.komponens.0.jumlah', 0.5);

        $this->assertEqualsWithDelta($stokKrim - 1.0, $krim->refresh()->stokDi($apoteker->cabang_id), 0.0001);
        $this->assertEqualsWithDelta($stokTablet - 14, $tablet->refresh()->stokDi($apoteker->cabang_id), 0.0001);
    }

    public function test_komponen_racikan_ikut_dicek_alergi(): void
    {
        $tablet = $this->obat('OBT-001');
        PasienAlergi::create(['pasien_id' => $this->pasien->id, 'jenis' => 'obat', 'zat' => 'Parasetamol', 'obat_id' => $tablet->id, 'keparahan' => 'sedang']);
        $id = $this->kunjungan();

        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['resep' => [
            $this->racikan([['obat_id' => $this->obat('OBT-023')->id, 'jumlah' => 0.5], ['obat_id' => $tablet->id, 'jumlah' => 2]]),
        ]])->assertStatus(422)->assertJsonValidationErrors(['resep.0.komponen.1.obat_id', 'konfirmasi_alergi']);
    }

    public function test_sip_kedaluwarsa_diperingatkan_dan_menahan_booking(): void
    {
        $dokter = User::where('email', 'dokter@eklinik.test')->firstOrFail();
        $dokter->update(['sip_berlaku_sampai' => today()->subDay(), 'str' => 'STR-123', 'str_berlaku_sampai' => null]);
        $kulit = User::where('email', 'dokter.kulit@eklinik.test')->firstOrFail();
        $kulit->update(['sip_berlaku_sampai' => today()->addDays(20)]);

        $this->as('admin@eklinik.test');
        $izin = collect($this->getJson('/api/dashboard')->assertOk()->json('izin_praktik'));
        $this->assertSame(-1, $izin->firstWhere('user_id', $dokter->id)['sisa_hari']);
        $this->assertSame(20, $izin->firstWhere('user_id', $kulit->id)['sisa_hari']);
        // STR seumur hidup (tanpa tanggal) tidak diperingatkan
        $this->assertNull($izin->first(fn ($r) => $r['user_id'] === $dokter->id && $r['dokumen'] === 'STR'));

        // Dokter melihat peringatan miliknya sendiri saja
        $this->as('dokter.kulit@eklinik.test');
        $this->getJson('/api/dashboard')->assertOk()->assertJsonCount(1, 'izin_praktik')->assertJsonPath('izin_praktik.0.user_id', $kulit->id);

        // Booking dokter ber-SIP kedaluwarsa ditolak
        $this->as('pendaftaran@eklinik.test');
        $this->postJson('/api/appointments', [
            'pasien_id' => $this->pasien->id, 'petugas_id' => $dokter->id,
            'mulai_at' => today()->next(CarbonInterface::MONDAY)->setTime(10, 0)->toDateTimeString(),
            'tindakan_ids' => [Tindakan::where('is_active', true)->value('id')],
        ])->assertStatus(422)->assertJsonValidationErrors('petugas_id');
    }

    public function test_produk_skincare_wajib_nomor_bpom(): void
    {
        $this->as('apoteker@eklinik.test');
        $data = ['kode' => 'SKN-001', 'nama' => 'Sunscreen SPF 50', 'satuan' => 'tube', 'jenis' => 'skincare', 'harga' => 150000, 'stok_minimum' => 5];

        $this->postJson('/api/obats', $data)->assertStatus(422)->assertJsonValidationErrors('no_bpom');
        $this->postJson('/api/obats', [...$data, 'no_bpom' => 'DKL123'])->assertStatus(422)->assertJsonValidationErrors('no_bpom');
        $this->postJson('/api/obats', [...$data, 'no_bpom' => 'na 18210100123'])->assertCreated()
            ->assertJsonPath('no_bpom', 'NA18210100123')
            ->assertJsonPath('jenis', 'skincare');

        // Obat biasa tidak wajib, dan fraksional kini bisa diatur dari API
        $this->postJson('/api/obats', ['kode' => 'OBT-X', 'nama' => 'Krim racik dasar', 'satuan' => 'gram', 'harga' => 1000, 'stok_minimum' => 0,
            'fraksional' => true, 'jam_pakai_setelah_buka' => 720])->assertCreated()->assertJsonPath('jenis', 'obat')->assertJsonPath('fraksional', true);
    }

    public function test_impor_master_idempoten_dan_tidak_menghapus(): void
    {
        $this->as('admin@eklinik.test');
        $lama = Icd10::firstOrFail();
        $jumlahAwal = Icd10::count();
        $csv = "\xEF\xBB\xBFkode;nama;sensitif\n{$lama->kode};{$lama->nama} (rev);\nL99.8;Gangguan kulit lain;\nB99.8;Infeksi lain (uji);ya\nSALAH;Nama;\n";
        $berkas = fn () => UploadedFile::fake()->createWithContent('icd10.csv', $csv);

        $this->post('/api/impor-master/icd10', ['berkas' => $berkas()], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('diperbarui', 1)
            ->assertJsonPath('baru', 2)
            ->assertJsonPath('galat.0.baris', 5);
        $this->assertSame("{$lama->nama} (rev)", $lama->refresh()->nama);
        $this->assertTrue(Icd10::where('kode', 'B99.8')->value('sensitif'));
        $this->assertSame($jumlahAwal + 2, Icd10::count());

        // Impor ulang: tidak ada perubahan
        $this->post('/api/impor-master/icd10', ['berkas' => $berkas()], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('baru', 0)->assertJsonPath('diperbarui', 0);

        // Obat: skincare tanpa BPOM ditolak per baris; stok tidak tersentuh
        $obat = $this->obat('OBT-001');
        $stok = $obat->stok;
        $csvObat = "kode,nama,satuan,harga,jenis,no_bpom\nOBT-001,{$obat->nama},tablet,999,obat,\nSKN-9,Serum,botol,120000,skincare,\nSKN-10,Toner,botol,90000,skincare,NA18210100999\n";
        $this->post('/api/impor-master/obat', ['berkas' => UploadedFile::fake()->createWithContent('obat.csv', $csvObat)], ['Accept' => 'application/json'])->assertOk()
            ->assertJsonPath('diperbarui', 1)
            ->assertJsonPath('baru', 1)
            ->assertJsonCount(1, 'galat');
        $this->assertSame(999, $obat->refresh()->harga);
        $this->assertEquals($stok, $obat->stok);

        // Perintah artisan memakai service yang sama
        $path = tempnam(sys_get_temp_dir(), 'icd9');
        file_put_contents($path, "99.99;Prosedur uji\n");
        $this->artisan('eklinik:impor', ['jenis' => 'icd9cm', 'berkas' => $path])->assertExitCode(0);
        $this->assertTrue(Icd9cm::where('kode', '99.99')->exists());

        // Hanya master.kelola
        $this->as('kasir@eklinik.test');
        $this->post('/api/impor-master/icd10', ['berkas' => $berkas()], ['Accept' => 'application/json'])->assertForbidden();
    }

    public function test_kop_dokumen_publik(): void
    {
        $this->as('admin@eklinik.test');
        $this->putJson('/api/pengaturan', ['dokumen' => ['kop_tambahan' => 'Izin Klinik No. 503/123/2026', 'penanggung_jawab' => 'dr. Andi Wijaya', 'kaki' => 'Dokumen sah']])->assertOk();

        $this->getJson('/api/info')->assertOk()
            ->assertJsonPath('dokumen.kop_tambahan', 'Izin Klinik No. 503/123/2026')
            ->assertJsonPath('dokumen.penanggung_jawab', 'dr. Andi Wijaya');
    }
}
