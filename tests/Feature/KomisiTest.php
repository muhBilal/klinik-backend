<?php

namespace Tests\Feature;

use App\Models\Icd10;
use App\Models\KomisiBaris;
use App\Models\KomisiPeriode;
use App\Models\Kunjungan;
use App\Models\Paket;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Tindakan;
use App\Models\TindakanHarga;
use App\Models\TindakanKomisi;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * Komisi & jasa medis (PRD KM-01, KM-03): komisi per treatment per peran (dokter, terapis, asisten) diatur di master treatment,
 * jasa konsultasi = treatment poli, persen/nominal, dasar bruto/neto, sesi paket, rekap periode per cabang dari tagihan lunas,
 * persetujuan mengunci, penyesuaian, dan slip milik sendiri.
 */
class KomisiTest extends TestCase
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

    private function id(string $email): int
    {
        return User::where('email', $email)->value('id');
    }

    private function tindakan(string $kode): int
    {
        return Tindakan::where('kode', $kode)->value('id');
    }

    /**
     * Kunjungan Poli Estetika oleh dokter@, ditutup, dan (opsional) dibayar tunai oleh kasir.
     *
     * @param  list<array>  $tindakans
     */
    private function kunjungan(Pasien $pasien, array $tindakans, ?int $diskon = 0): Kunjungan
    {
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $pasien->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');

        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => $tindakans])->assertOk();
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        $kunjungan = Kunjungan::findOrFail($id);
        if ($diskon !== null) {
            $this->as('kasir@eklinik.test');
            $grand = $kunjungan->tagihan->total - $diskon;
            $this->postJson("/api/tagihans/{$kunjungan->tagihan->id}/bayar", ['metode_bayar' => 'tunai', 'dibayar' => $grand, 'diskon' => $diskon])
                ->assertOk()->assertJsonPath('status', 'lunas');
        }

        return $kunjungan;
    }

    private function periode(string $nama = 'Hari ini'): array
    {
        $this->as('manajer@eklinik.test');

        return $this->postJson('/api/komisi-periodes', ['nama' => $nama, 'mulai' => today()->toDateString(), 'selesai' => today()->toDateString()])
            ->assertCreated()->json();
    }

    /** PUT treatment dengan field wajib dari detailnya + perubahan. */
    private function ubahTreatment(string $kode, array $ubah): TestResponse
    {
        $t = $this->getJson('/api/tindakans/'.$this->tindakan($kode))->assertOk()->json();

        return $this->putJson("/api/tindakans/{$t['id']}",
            [...Arr::only($t, ['kode', 'nama', 'kategori_id', 'durasi_menit', 'buffer_menit', 'tarif', 'jenis_catatan']), ...$ubah]);
    }

    /** Total komisi per email dari ringkasan. */
    private function totalPer(array $detail): array
    {
        return collect($detail['ringkasan'])->mapWithKeys(fn ($r) => [User::find($r['user']['id'])->email => $r['total']])->sortKeys()->all();
    }

    public function test_komisi_diatur_di_master_treatment_dengan_validasi_dan_hak_akses(): void
    {
        $botox = $this->tindakan('TRT-001');

        // Manajer (komisi.kelola tanpa master.kelola) tidak membuka master treatment
        $this->as('manajer@eklinik.test');
        $this->getJson("/api/tindakans/{$botox}")->assertForbidden();

        $this->as('admin@eklinik.test');
        $komisis = $this->getJson("/api/tindakans/{$botox}")->assertOk()->json('komisis');
        $this->assertSame([['dokter', 'persen', 15], ['asisten', 'nominal', 25000]], array_map(fn ($k) => [$k['peran'], $k['jenis'], $k['nilai']], $komisis));

        // Validasi: persen > 100, peran dobel, peran penyesuaian bukan komisi treatment
        $this->ubahTreatment('TRT-001', ['komisis' => [['peran' => 'dokter', 'jenis' => 'persen', 'nilai' => 120]]])
            ->assertUnprocessable()->assertJsonValidationErrors('komisis.0.nilai');
        $this->ubahTreatment('TRT-001', ['komisis' => [['peran' => 'dokter', 'jenis' => 'persen', 'nilai' => 10], ['peran' => 'dokter', 'jenis' => 'nominal', 'nilai' => 5000]]])
            ->assertUnprocessable()->assertJsonValidationErrors('komisis.1.peran');
        $this->ubahTreatment('TRT-001', ['komisis' => [['peran' => 'penyesuaian', 'jenis' => 'nominal', 'nilai' => 5000]]])
            ->assertUnprocessable()->assertJsonValidationErrors('komisis.0.peran');

        // Replace-all (asisten dihapus, dokter diubah, terapis ditambah) & tercatat audit; tanpa key komisis = tidak berubah
        $audit = fn () => DB::table('audit_logs')->where('tipe', 'tindakan_komisi')->count();
        $auditAwal = $audit();
        $this->ubahTreatment('TRT-001', ['komisis' => [['peran' => 'dokter', 'jenis' => 'persen', 'nilai' => 12.5], ['peran' => 'terapis', 'jenis' => 'nominal', 'nilai' => 30000]]])
            ->assertOk()->assertJsonCount(2, 'komisis')->assertJsonPath('komisis.0.nilai', 12.5)->assertJsonPath('komisis.1.peran', 'terapis');
        $this->ubahTreatment('TRT-001', ['nama' => 'Botox dahi & glabella'])->assertOk()->assertJsonCount(2, 'komisis');
        $this->assertSame($auditAwal + 3, $audit());
        $this->getJson('/api/tindakans?komisi=1&q=TRT-001')->assertOk()->assertJsonCount(2, 'data.0.komisis');

        // Pemegang master.kelola tanpa komisi.kelola: komisi tidak terlihat & tidak bisa diubah, field lain tetap bisa
        $this->postJson('/api/perans', ['kode' => 'staf_master', 'nama' => 'Staf Master', 'izin' => ['master.kelola']])->assertCreated();
        Sanctum::actingAs(User::factory()->create(['role' => 'staf_master', 'cabang_id' => User::where('email', 'kasir@eklinik.test')->value('cabang_id')]));
        $this->getJson("/api/tindakans/{$botox}")->assertOk()->assertJsonMissingPath('komisis');
        $this->getJson('/api/tindakans?komisi=1&q=TRT-001')->assertOk()->assertJsonMissingPath('data.0.komisis');
        $this->ubahTreatment('TRT-001', ['komisis' => []])->assertForbidden();
        $this->ubahTreatment('TRT-001', ['nama' => 'Botox'])->assertOk();
        $this->assertSame(2, TindakanKomisi::where('tindakan_id', $botox)->count());
    }

    public function test_jasa_konsultasi_adalah_treatment_poli(): void
    {
        [$p1, $p2, $p3] = Pasien::orderBy('id')->take(3)->get()->all();
        $poli = Poli::where('kode', 'ESTETIKA')->firstOrFail();
        $kns = $this->tindakan('KNS-001');
        $this->assertSame($kns, $poli->tindakan_konsultasi_id);

        // Ditagihkan otomatis sebagai item konsultasi yang menunjuk treatment-nya; harga khusus cabang ikut katalog
        TindakanHarga::create(['tindakan_id' => $kns, 'cabang_id' => User::where('email', 'dokter@eklinik.test')->value('cabang_id'), 'tarif' => 120000]);
        $a = $this->kunjungan($p1, [['tindakan_id' => $this->tindakan('TRT-021')]], null);
        $item = $a->tagihan->items()->where('kategori', 'konsultasi')->firstOrFail();
        $this->assertSame([$kns, 120000, 'Konsultasi dokter estetika'], [$item->tindakan_id, $item->harga, $item->deskripsi]);
        $this->getJson("/api/kunjungans/{$a->id}")->assertOk()->assertJsonPath('konsultasi.id', $kns)->assertJsonPath('konsultasi.tarif_cabang', 120000);

        // Dicatat dokter sebagai tindakan (mis. ×2) → tidak ditagih dua kali
        $b = $this->kunjungan($p2, [['tindakan_id' => $kns, 'jumlah' => 2]], null);
        $this->assertSame([['tindakan', 240000]], $b->tagihan->items->map(fn ($i) => [$i->kategori, $i->subtotal])->all());

        // Treatment nonaktif → tanpa item konsultasi
        Tindakan::whereKey($kns)->update(['is_active' => false]);
        $c = $this->kunjungan($p3, [['tindakan_id' => $this->tindakan('TRT-021')]], null);
        $this->assertFalse($c->tagihan->items->contains('kategori', 'konsultasi'));

        // Master poli memilih treatment; treatment jasa konsultasi poli tidak bisa dihapus
        $this->as('admin@eklinik.test');
        $this->getJson('/api/polis')->assertOk()->assertJsonPath('0.tindakan_konsultasi.kode', 'KNS-001');
        $this->putJson("/api/polis/{$poli->id}", ['kode' => 'ESTETIKA', 'nama' => $poli->nama, 'tindakan_konsultasi_id' => 999999])
            ->assertUnprocessable()->assertJsonValidationErrors('tindakan_konsultasi_id');
        $this->putJson("/api/polis/{$poli->id}", ['kode' => 'ESTETIKA', 'nama' => $poli->nama, 'tindakan_konsultasi_id' => null])
            ->assertOk()->assertJsonPath('tindakan_konsultasi', null);
        $this->deleteJson('/api/tindakans/'.$this->tindakan('KNS-002'))->assertUnprocessable()
            ->assertJsonPath('message', 'Treatment ini jasa konsultasi Poli Kulit & Kelamin. Ganti jasa konsultasinya di Master Poli dulu.');
    }

    public function test_rekap_dari_tagihan_lunas_per_peran_dan_dasar_neto_atau_bruto(): void
    {
        [$p1, $p2, $p3] = Pasien::take(3)->get()->all();
        $dokter = $this->id('dokter@eklinik.test');
        $terapis = $this->id('terapis@eklinik.test');
        $perawat = $this->id('perawat@eklinik.test');

        // A: botox (dokter mengerjakan, perawat asisten) + facial (terapis), tanpa diskon
        $this->kunjungan($p1, [
            ['tindakan_id' => $this->tindakan('TRT-001'), 'petugas_id' => $dokter, 'asisten_id' => $perawat],
            ['tindakan_id' => $this->tindakan('TRT-021'), 'petugas_id' => $terapis],
        ]);
        // B: laser oleh terapis, diskon 100rb dari total 1,3 jt → neto = 12/13
        $this->kunjungan($p2, [['tindakan_id' => $this->tindakan('TRT-011'), 'petugas_id' => $terapis]], 100000);
        // C: belum dibayar → tidak dihitung
        $this->kunjungan($p3, [['tindakan_id' => $this->tindakan('TRT-012'), 'petugas_id' => $terapis]], null);

        $periode = $this->periode();
        $detail = $this->postJson("/api/komisi-periodes/{$periode['id']}/hitung")->assertOk()->json();

        // dokter: konsultasi A 40% × 100rb = 40.000; botox 15% × 3,5 jt = 525.000; konsultasi B 40% × 92.308 = 36.923; laser 5% × 1.107.692 = 55.385
        // terapis: facial 10% × 350rb = 35.000; laser nominal 50.000. perawat: asisten botox 25.000. Facial dokter 0% → tidak ada baris.
        $this->assertSame(['dokter@eklinik.test' => 657308, 'perawat@eklinik.test' => 25000, 'terapis@eklinik.test' => 85000], $this->totalPer($detail));
        $this->assertSame(767308, $detail['total']);
        $this->assertSame('neto', $detail['dasar']);
        $this->assertCount(7, $detail['barises']);
        $botox = collect($detail['barises'])->first(fn ($b) => $b['peran'] === 'dokter' && str_contains($b['deskripsi'], 'Botulinum'));
        $this->assertSame([3500000, 'persen', 15, 525000], [$botox['dasar'], $botox['jenis'], $botox['nilai'], $botox['komisi']]);

        // Dasar bruto: diskon tagihan B diabaikan
        app(PengaturanService::class)->simpan(['komisi' => ['dasar' => 'bruto']]);
        $bruto = $this->postJson("/api/komisi-periodes/{$periode['id']}/hitung")->assertOk()->json();
        $this->assertSame(40000 + 525000 + 40000 + 60000, $this->totalPer($bruto)['dokter@eklinik.test']);

        // Komisi diubah di master treatment: laser terapis jadi 8% (nominal → persen), dokter tetap 5%
        $this->as('admin@eklinik.test');
        $this->ubahTreatment('TRT-011', ['komisis' => [['peran' => 'dokter', 'jenis' => 'persen', 'nilai' => 5], ['peran' => 'terapis', 'jenis' => 'persen', 'nilai' => 8]]])
            ->assertOk();
        $khusus = $this->postJson("/api/komisi-periodes/{$periode['id']}/hitung")->assertOk()->json();
        $this->assertSame(35000 + 96000, $this->totalPer($khusus)['terapis@eklinik.test']);
    }

    public function test_sesi_paket_dan_tagihan_nol(): void
    {
        $pasien = Pasien::first();
        // Jual & lunasi paket laser 6x (nilai per sesi 1 jt)
        $this->as('kasir@eklinik.test');
        $paket = $this->postJson("/api/pasiens/{$pasien->id}/pakets", ['paket_id' => Paket::where('kode', 'PKT-LSR6')->value('id')])->json();
        $this->postJson("/api/tagihans/{$paket['tagihan_id']}/bayar", ['metode_bayar' => 'tunai', 'dibayar' => 6000000])->assertOk();
        $item = $this->getJson("/api/paket-pasiens/{$paket['id']}")->json('items.0.id');

        // Poli tanpa jasa konsultasi → tagihan kunjungan Rp 0 tetap bisa dilunasi
        Poli::where('kode', 'ESTETIKA')->update(['tindakan_konsultasi_id' => null]);
        $kunjungan = $this->kunjungan($pasien, [['tindakan_id' => $this->tindakan('TRT-011'), 'petugas_id' => $this->id('terapis@eklinik.test'), 'paket_pasien_item_id' => $item]]);
        $this->assertSame([0, 'lunas'], [$kunjungan->tagihan->fresh()->grand_total, $kunjungan->tagihan->fresh()->status->value]);

        $periode = $this->periode();
        $detail = $this->postJson("/api/komisi-periodes/{$periode['id']}/hitung")->assertOk()->json();
        // Dasar sesi paket = nilai per sesi 1 jt: dokter 5% = 50.000; terapis nominal 50.000
        $this->assertSame(['dokter@eklinik.test' => 50000, 'terapis@eklinik.test' => 50000], $this->totalPer($detail));
        $this->assertStringContainsString('sesi paket', $detail['barises'][0]['deskripsi']);
    }

    public function test_persetujuan_mengunci_dan_penyesuaian(): void
    {
        $this->kunjungan(Pasien::first(), [['tindakan_id' => $this->tindakan('TRT-021'), 'petugas_id' => $this->id('terapis@eklinik.test')]]);
        $periode = $this->periode('Oktober');
        $url = "/api/komisi-periodes/{$periode['id']}";

        // Belum dihitung → belum bisa disetujui; manajer tidak memegang komisi.setujui
        $this->as('admin@eklinik.test');
        $this->postJson("{$url}/setujui")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->as('manajer@eklinik.test');
        $this->postJson("{$url}/hitung")->assertOk()->assertJsonPath('total', 75000);
        $this->postJson("{$url}/setujui")->assertForbidden();

        // Penyesuaian manual (+/-) ikut total; bertahan saat dihitung ulang; bisa dihapus
        $terapis = $this->id('terapis@eklinik.test');
        $this->postJson("{$url}/penyesuaian", ['user_id' => $terapis, 'komisi' => 0, 'keterangan' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('komisi');
        $this->postJson("{$url}/penyesuaian", ['user_id' => $terapis, 'komisi' => 100000, 'keterangan' => 'Bonus target'])->assertCreated()->assertJsonPath('total', 175000);
        $potong = $this->postJson("{$url}/penyesuaian", ['user_id' => $terapis, 'komisi' => -20000, 'keterangan' => 'Koreksi'])->assertCreated()->json();
        $this->postJson("{$url}/hitung")->assertOk()->assertJsonPath('total', 155000);
        $idPotong = collect($potong['barises'])->firstWhere('komisi', -20000)['id'];
        $idOtomatis = collect($potong['barises'])->firstWhere('sumber', 'tindakan')['id'];
        $this->deleteJson("{$url}/penyesuaian/{$idOtomatis}")->assertNotFound();
        $this->deleteJson("{$url}/penyesuaian/{$idPotong}")->assertOk()->assertJsonPath('total', 175000);

        // Periode tumpang tindih di cabang yang sama ditolak
        $this->postJson('/api/komisi-periodes', ['nama' => 'Dobel', 'mulai' => today()->subDay()->toDateString(), 'selesai' => today()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors('mulai');

        // Disetujui → terkunci
        $this->as('admin@eklinik.test');
        $this->postJson("{$url}/setujui")->assertOk()->assertJsonPath('status', 'disetujui');
        $this->as('manajer@eklinik.test');
        $this->postJson("{$url}/hitung")->assertUnprocessable();
        $this->postJson("{$url}/penyesuaian", ['user_id' => $terapis, 'komisi' => 1, 'keterangan' => 'x'])->assertUnprocessable();
        $this->deleteJson($url)->assertUnprocessable();
        try {
            KomisiPeriode::withoutGlobalScope('cabang')->find($periode['id'])->update(['total' => 1]);
            $this->fail('Periode disetujui seharusnya terkunci.');
        } catch (LogicException) {
        }
        try {
            KomisiBaris::where('komisi_periode_id', $periode['id'])->first()->update(['komisi' => 1]);
            $this->fail('Baris periode disetujui seharusnya terkunci.');
        } catch (LogicException) {
        }

        // Refund setelah disetujui tidak mengubah slip
        $this->as('admin@eklinik.test');
        $tagihan = Kunjungan::latest('id')->first()->tagihan;
        $this->postJson("/api/tagihans/{$tagihan->id}/refund", ['alasan_refund' => 'Komplain'])->assertOk();
        $this->getJson($url)->assertOk()->assertJsonPath('total', 175000);
    }

    public function test_slip_komisi_saya_hanya_periode_disetujui_dan_milik_sendiri(): void
    {
        $this->kunjungan(Pasien::first(), [['tindakan_id' => $this->tindakan('TRT-021'), 'petugas_id' => $this->id('terapis@eklinik.test')]]);
        $periode = $this->periode();
        $this->postJson("/api/komisi-periodes/{$periode['id']}/hitung")->assertOk();

        $this->as('terapis@eklinik.test');
        $this->getJson('/api/komisi-saya')->assertOk()->assertJsonCount(0); // masih draf
        $this->getJson('/api/komisi-periodes')->assertForbidden();

        $this->as('admin@eklinik.test');
        $this->postJson("/api/komisi-periodes/{$periode['id']}/setujui")->assertOk();

        $this->as('terapis@eklinik.test');
        $this->getJson('/api/komisi-saya')->assertOk()->assertJsonCount(1)->assertJsonPath('0.total_saya', 35000);
        $slip = $this->getJson("/api/komisi-saya?periode_id={$periode['id']}")->assertOk()->json();
        $this->assertSame([35000, ['terapis']], [$slip['total'], array_values(array_unique(array_column($slip['barises'], 'peran')))]);

        // Dokter punya komisi konsultasi; kasir tidak punya baris → 404
        $this->as('dokter@eklinik.test');
        $this->getJson("/api/komisi-saya?periode_id={$periode['id']}")->assertOk()->assertJsonPath('total', 40000);
        $this->as('kasir@eklinik.test');
        $this->getJson("/api/komisi-saya?periode_id={$periode['id']}")->assertNotFound();
    }

    public function test_asisten_tindakan_divalidasi_dan_masuk_rekam_medis(): void
    {
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => Pasien::first()->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');
        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $url = "/api/kunjungans/{$id}/pemeriksaan";

        $this->putJson($url, ['tindakans' => [['tindakan_id' => $this->tindakan('TRT-001'), 'asisten_id' => $this->id('kasir@eklinik.test')]]])
            ->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.asisten_id');
        $res = $this->putJson($url, ['diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => [['tindakan_id' => $this->tindakan('TRT-001'), 'asisten_id' => $this->id('perawat@eklinik.test')]]])->assertOk();
        $this->assertSame('Ns. Rina Perawat', $res->json('tindakans.0.asisten.name'));

        // Simpan ulang tanpa asisten_id mempertahankan asisten; hash RME mencakupnya
        $this->putJson($url, ['tindakans' => [['id' => $res->json('tindakans.0.id'), 'tindakan_id' => $this->tindakan('TRT-001')]]])
            ->assertOk()->assertJsonPath('tindakans.0.asisten_id', $this->id('perawat@eklinik.test'));
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();
        $this->getJson("/api/kunjungans/{$id}/verifikasi")->assertJsonPath('valid', true);
        DB::table('kunjungan_tindakans')->where('kunjungan_id', $id)->update(['asisten_id' => $this->id('terapis@eklinik.test')]);
        $this->getJson("/api/kunjungans/{$id}/verifikasi")->assertJsonPath('valid', false);
    }

    public function test_dasar_neto_per_baris_pada_tagihan_kunjungan_berisi_paket(): void
    {
        $pasien = Pasien::first();
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $pasien->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');
        $dokter = $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        // Paket laser dipesan dokter + botox hari ini; LASER200 hanya untuk laser → potongan seluruhnya jatuh ke baris paket
        $this->postJson("/api/kunjungans/{$id}/pakets", ['paket_id' => Paket::where('kode', 'PKT-LSR6')->value('id')])->assertCreated();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", ['diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => [['tindakan_id' => $this->tindakan('TRT-001'), 'petugas_id' => $dokter->id]]])->assertOk();
        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();
        $tagihan = Kunjungan::findOrFail($id)->tagihan;

        $this->as('kasir@eklinik.test');
        $this->postJson("/api/tagihans/{$tagihan->id}/promo", ['kode' => 'LASER200'])->assertOk()->assertJsonPath('diskon_promo', 200000);
        $this->postJson("/api/tagihans/{$tagihan->id}/bayar", ['metode_bayar' => 'tunai', 'dibayar' => 9400000])->assertOk();
        $this->assertSame([100000, 3500000, 5800000], $tagihan->items()->orderBy('id')->pluck('neto')->all());

        $periode = $this->periode();
        $detail = $this->postJson("/api/komisi-periodes/{$periode['id']}/hitung")->assertOk()->json();
        $dasar = collect($detail['barises'])->where('peran', 'dokter')->mapWithKeys(fn ($b) => [$b['sumber'] => $b['dasar']])->all();
        // Botox & konsultasi tidak ikut menanggung potongan promo paket
        $this->assertSame(['konsultasi' => 100000, 'tindakan' => 3500000], $dasar);
    }
}
