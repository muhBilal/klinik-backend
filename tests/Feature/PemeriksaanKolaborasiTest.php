<?php

namespace Tests\Feature;

use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\Paket;
use App\Models\Pasien;
use App\Models\Pemeriksaan;
use App\Models\Poli;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Dokter & perawat/terapis mengisi pemeriksaan yang sama dari perangkat berbeda (TR-02, RM): sinkron tindakan sadar snapshot klien
 * (`tindakan_ids_awal`), baris yang dicatat petugas lain terlindungi dari perawat/terapis, hanya kolom yang dikirim yang berubah,
 * `rme.tindakan` tanpa tanda vital, isi RME hanya diubah yang boleh membacanya, dan kunjungan berisi dokumentasi tindakan tidak bisa
 * dibatalkan front office.
 */
class PemeriksaanKolaborasiTest extends TestCase
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

    private function tindakan(string $kode): int
    {
        return Tindakan::where('kode', $kode)->value('id');
    }

    private function kunjungan(bool $panggil = true, ?Pasien $pasien = null): int
    {
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => ($pasien ?? $this->pasien)->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');
        if ($panggil) {
            $this->as('dokter@eklinik.test');
            $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        }

        return $id;
    }

    private function simpan(int $kunjungan, array $data): TestResponse
    {
        return $this->putJson("/api/kunjungans/{$kunjungan}/pemeriksaan", $data);
    }

    /** User peran kustom dengan izin tertentu (dibuat lewat API peran). */
    private function peranKustom(string $kode, array $izin): User
    {
        $this->as('admin@eklinik.test');
        $this->postJson('/api/perans', ['kode' => $kode, 'nama' => ucfirst($kode), 'izin' => $izin])->assertCreated();
        $user = User::factory()->create(['role' => $kode]);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_simpan_dari_form_lama_tidak_menghapus_tindakan_petugas_lain(): void
    {
        $k = $this->kunjungan();
        [$botox, $laser, $facial] = [$this->tindakan('TRT-001'), $this->tindakan('TRT-011'), $this->tindakan('TRT-021')];

        // Dokter membuka form kosong lalu mencatat botox
        $this->as('dokter@eklinik.test');
        $b = $this->simpan($k, ['tindakans' => [['tindakan_id' => $botox]], 'tindakan_ids_awal' => []])->assertOk()->json('tindakans.0.id');

        // Terapis di perangkat lain (form dimuat setelah botox) menambah laser
        $terapis = $this->as('terapis@eklinik.test');
        $l = collect($this->simpan($k, ['tindakans' => [['id' => $b, 'tindakan_id' => $botox], ['tindakan_id' => $laser]], 'tindakan_ids_awal' => [$b]])
            ->assertOk()->json('tindakans'))->firstWhere('tindakan_id', $laser)['id'];

        // Dokter menyimpan dari form lama (hanya melihat botox) sambil menambah facial → laser terapis tetap
        $this->as('dokter@eklinik.test');
        $res = $this->simpan($k, ['tindakans' => [['id' => $b, 'tindakan_id' => $botox], ['tindakan_id' => $facial]], 'tindakan_ids_awal' => [$b]])
            ->assertOk()->assertJsonCount(3, 'tindakans');
        $this->assertTrue(KunjunganTindakan::whereKey($l)->where('petugas_id', $terapis->id)->exists());
        $f = collect($res->json('tindakans'))->firstWhere('tindakan_id', $facial)['id'];

        // Baris yang terlihat lalu dibuang dokter → dihapus
        $this->simpan($k, ['tindakans' => [['id' => $b, 'tindakan_id' => $botox], ['id' => $l, 'tindakan_id' => $laser]], 'tindakan_ids_awal' => [$b, $l, $f]])
            ->assertOk()->assertJsonCount(2, 'tindakans');
        // Form lama yang masih memuat facial (sudah dihapus) tidak membuatnya ulang
        $this->simpan($k, ['tindakans' => [['id' => $b, 'tindakan_id' => $botox], ['id' => $f, 'tindakan_id' => $facial], ['id' => $l, 'tindakan_id' => $laser]],
            'tindakan_ids_awal' => [$b, $f, $l]])->assertOk()->assertJsonCount(2, 'tindakans');
        // Tanpa snapshot (klien lama / seeder) daftar dianggap lengkap
        $this->simpan($k, ['tindakans' => [['id' => $b, 'tindakan_id' => $botox]]])->assertOk()->assertJsonCount(1, 'tindakans');
    }

    public function test_perawat_terapis_tidak_menghapus_atau_mengubah_tindakan_petugas_lain_kecuali_sesi_paket(): void
    {
        // Paket laser aktif milik pasien
        $this->as('kasir@eklinik.test');
        $jual = $this->postJson("/api/pasiens/{$this->pasien->id}/pakets", ['paket_id' => Paket::where('kode', 'PKT-LSR6')->value('id')])->json();
        $this->postJson("/api/tagihans/{$jual['tagihan_id']}/bayar", ['metode_bayar' => 'tunai', 'dibayar' => 6000000])->assertOk();
        $item = $this->getJson("/api/paket-pasiens/{$jual['id']}")->json('items.0.id');

        $k = $this->kunjungan();
        [$laser, $facial] = [$this->tindakan('TRT-011'), $this->tindakan('TRT-021')];
        $dokter = $this->as('dokter@eklinik.test');
        $row = $this->simpan($k, ['tindakans' => [['tindakan_id' => $laser, 'petugas_id' => $dokter->id]], 'tindakan_ids_awal' => []])->json('tindakans.0.id');

        $terapis = $this->as('terapis@eklinik.test');
        // Menghapus baris dokter ditolak
        $this->simpan($k, ['tindakans' => [], 'tindakan_ids_awal' => [$row]])->assertUnprocessable()->assertJsonValidationErrors('tindakans');
        // Jumlah & pelaksana baris dokter tidak berubah; memakai sesi paket boleh
        $this->simpan($k, ['tindakans' => [['id' => $row, 'tindakan_id' => $laser, 'jumlah' => 3, 'petugas_id' => $terapis->id, 'paket_pasien_item_id' => $item]],
            'tindakan_ids_awal' => [$row]])->assertOk();
        $baris = KunjunganTindakan::find($row);
        $this->assertSame([1, $dokter->id, $item], [$baris->jumlah, $baris->petugas_id, $baris->paket_pasien_item_id]);

        // Barisnya sendiri bebas diubah & dihapus
        $res = $this->simpan($k, ['tindakans' => [['id' => $row, 'tindakan_id' => $laser, 'paket_pasien_item_id' => $item], ['tindakan_id' => $facial, 'jumlah' => 2]],
            'tindakan_ids_awal' => [$row]])->assertOk();
        $milik = collect($res->json('tindakans'))->firstWhere('tindakan_id', $facial);
        $this->assertSame([$terapis->id, 2], [$milik['petugas_id'], $milik['jumlah']]);
        $this->simpan($k, ['tindakans' => [['id' => $row, 'tindakan_id' => $laser, 'paket_pasien_item_id' => $item]], 'tindakan_ids_awal' => [$row, $milik['id']]])
            ->assertOk()->assertJsonCount(1, 'tindakans');
    }

    public function test_hanya_kolom_yang_dikirim_berubah_dan_perawat_id_dari_perubahan_nyata(): void
    {
        $k = $this->kunjungan();
        $perawat = $this->as('perawat@eklinik.test');
        $this->simpan($k, ['nadi' => 80, 'suhu' => 36.5, 'tekanan_darah' => '120/80'])->assertOk();

        // Dokter menyimpan anamnesis saja → tanda vital perawat tetap
        $this->as('dokter@eklinik.test');
        $this->simpan($k, ['subjektif' => 'Flek di pipi'])->assertOk();
        // Terapis menyimpan tindakan saja → tanda vital, anamnesis & perawat_id tetap
        $terapis = $this->as('terapis@eklinik.test');
        $this->simpan($k, ['tindakans' => [['tindakan_id' => $this->tindakan('TRT-021')]], 'tindakan_ids_awal' => []])->assertOk();
        $p = Pemeriksaan::where('kunjungan_id', $k)->firstOrFail();
        $this->assertSame([80, '120/80', 'Flek di pipi', $perawat->id], [$p->nadi, $p->tekanan_darah, $p->subjektif, $p->perawat_id]);

        // Terapis mengubah tanda vital → tercatat sebagai pengisi
        $this->simpan($k, ['nadi' => 84])->assertOk();
        $this->assertSame([84, $terapis->id], [$p->fresh()->nadi, $p->fresh()->perawat_id]);
    }

    public function test_peran_rme_tindakan_tanpa_tanda_vital_mencatat_tindakan_saja(): void
    {
        $k = $this->kunjungan();
        $perawat = $this->as('perawat@eklinik.test');
        $this->simpan($k, ['nadi' => 80])->assertOk();

        $beautician = $this->peranKustom('beautician', ['pasien.lihat', 'rme.lihat', 'rme.tindakan']);
        $res = $this->simpan($k, ['nadi' => 120, 'subjektif' => 'x', 'tindakans' => [['tindakan_id' => $this->tindakan('TRT-021')]], 'tindakan_ids_awal' => []])
            ->assertOk();
        $this->assertSame($beautician->id, $res->json('tindakans.0.petugas_id'));
        $p = Pemeriksaan::where('kunjungan_id', $k)->firstOrFail();
        $this->assertSame([80, null, $perawat->id], [$p->nadi, $p->subjektif, $p->perawat_id]);
        $this->postJson("/api/kunjungans/{$k}/selesai")->assertForbidden();
    }

    public function test_tanpa_akses_baca_rme_tindakan_diabaikan_dan_respons_tanpa_isi_rme(): void
    {
        $k = $this->kunjungan();
        $this->as('dokter@eklinik.test');
        $this->simpan($k, ['tindakans' => [['tindakan_id' => $this->tindakan('TRT-001')]], 'tindakan_ids_awal' => []])->assertOk();

        $this->peranKustom('vital_saja', ['pemeriksaan.vital', 'rme.tindakan']);
        $this->getJson("/api/kunjungans/{$k}")->assertOk()->assertJsonMissingPath('tindakans');
        $this->simpan($k, ['nadi' => 90, 'tindakans' => [['tindakan_id' => $this->tindakan('TRT-021')]]])->assertOk()
            ->assertJsonMissingPath('tindakans')->assertJsonMissingPath('pemeriksaan');

        $this->assertSame([$this->tindakan('TRT-001')], KunjunganTindakan::where('kunjungan_id', $k)->pluck('tindakan_id')->all());
        $this->assertSame(90, Pemeriksaan::where('kunjungan_id', $k)->value('nadi'));
    }

    public function test_kunjungan_berisi_catatan_tindakan_tidak_bisa_dibatalkan_front_office(): void
    {
        $k = $this->kunjungan(panggil: false);
        $this->as('dokter@eklinik.test');
        $row = $this->simpan($k, ['tindakans' => [['tindakan_id' => $this->tindakan('TRT-001')]], 'tindakan_ids_awal' => []])->json('tindakans.0.id');

        // Tindakan rencana saja → boleh dibatalkan; setelah ada catatan tindakan → ditolak
        $k2 = $this->kunjungan(false, Pasien::whereKeyNot($this->pasien->id)->firstOrFail());
        $this->as('dokter@eklinik.test');
        $this->simpan($k2, ['tindakans' => [['tindakan_id' => $this->tindakan('TRT-001')]], 'tindakan_ids_awal' => []])->assertOk();
        $this->putJson("/api/kunjungan-tindakans/{$row}/catatan", ['catatan' => 'Dosis 20U glabella'])->assertOk();

        $this->as('pendaftaran@eklinik.test');
        $this->postJson("/api/kunjungans/{$k}/batal")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/kunjungans/{$k2}/batal")->assertOk();
        $this->assertSame('menunggu', Kunjungan::find($k)->status->value);
    }
}
