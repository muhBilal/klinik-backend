<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Icd10;
use App\Models\Kunjungan;
use App\Models\Paket;
use App\Models\PaketPasien;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Promo;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Paket multi-sesi (PRD TR-02, BL-01) dan voucher & promo (TR-06): jual → aktif saat lunas, pemakaian sesi di pemeriksaan
 * (ditagih Rp 0), sisa & kedaluwarsa, refund penuh/sisa, pengalihan, perpanjangan, serta kode promo dengan periode, kuota,
 * kuota per pasien, minimum transaksi, cabang, dan treatment/paket tertentu.
 */
class PaketPromoTest extends TestCase
{
    use RefreshDatabase;

    private Pasien $pasien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Fokus test ini paket & promo; kewajiban consent tindakan diuji di RmeEstetikaTest.
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);
        $this->pasien = Pasien::first();
    }

    private function as(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function paket(string $kode): Paket
    {
        return Paket::where('kode', $kode)->firstOrFail();
    }

    private function tindakan(string $kode): int
    {
        return Tindakan::where('kode', $kode)->value('id');
    }

    /** Jual paket ke pasien & (opsional) lunasi tunai. Login sebagai kasir. */
    private function beliPaket(string $kode = 'PKT-LSR6', ?Pasien $pasien = null, bool $bayar = true): array
    {
        $this->as('kasir@eklinik.test');
        $paket = $this->postJson('/api/pasiens/'.($pasien ?? $this->pasien)->id.'/pakets', ['paket_id' => $this->paket($kode)->id])
            ->assertCreated()->assertJsonPath('status', 'menunggu_bayar')->json();

        if ($bayar) {
            $this->bayarTunai($paket['tagihan_id']);
        }

        return $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertOk()->json();
    }

    private function bayarTunai(int $tagihanId, int $diskon = 0): TestResponse
    {
        $grand = $this->getJson("/api/tagihans/{$tagihanId}")->json('grand_total');

        return $this->postJson("/api/tagihans/{$tagihanId}/bayar", ['pembayarans' => [['metode' => 'tunai', 'jumlah' => $grand + 1000000]], 'diskon' => $diskon]);
    }

    /** Kunjungan Poli Estetika yang sudah dipanggil dokter (login sebagai dokter). Tiap kunjungan di hari berikutnya. */
    private function kunjungan(?Pasien $pasien = null, bool $panggil = true): int
    {
        $this->travel(1)->days();
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => ($pasien ?? $this->pasien)->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');

        $this->as('dokter@eklinik.test');
        if ($panggil) {
            $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        }

        return $id;
    }

    private function pakaiSesi(int $kunjunganId, int $itemId, int $jumlah = 1, string $tindakan = 'TRT-011'): TestResponse
    {
        return $this->putJson("/api/kunjungans/{$kunjunganId}/pemeriksaan", ['tindakans' => [
            ['tindakan_id' => $this->tindakan($tindakan), 'jumlah' => $jumlah, 'paket_pasien_item_id' => $itemId],
        ]]);
    }

    private function tutup(int $kunjunganId): TestResponse
    {
        $this->putJson("/api/kunjungans/{$kunjunganId}/pemeriksaan", ['diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]]])->assertOk();

        return $this->postJson("/api/kunjungans/{$kunjunganId}/selesai");
    }

    public function test_master_paket_dan_nilai_normal(): void
    {
        $this->as('kasir@eklinik.test');
        $this->getJson('/api/pakets?aktif=1')->assertOk()->assertJsonFragment(['kode' => 'PKT-LSR6', 'nilai_normal' => 7200000]);
        $this->postJson('/api/pakets', ['kode' => 'X', 'nama' => 'X', 'harga' => 1, 'items' => [['tindakan_id' => 1, 'jumlah_sesi' => 1]]])->assertForbidden();

        $this->as('admin@eklinik.test');
        $data = ['kode' => 'PKT-IPL3', 'nama' => 'IPL 3x', 'harga' => 2400000, 'masa_berlaku_hari' => 90,
            'items' => [['tindakan_id' => $this->tindakan('TRT-012'), 'jumlah_sesi' => 3]]];
        $this->postJson('/api/pakets', [...$data, 'items' => [...$data['items'], ['tindakan_id' => $this->tindakan('TRT-012'), 'jumlah_sesi' => 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.1.tindakan_id');
        $paket = $this->postJson('/api/pakets', $data)->assertCreated()->assertJsonPath('nilai_normal', 2700000)->json();

        // Ubah isi: tambah treatment, ubah sesi
        $this->putJson("/api/pakets/{$paket['id']}", [...$data, 'items' => [
            ['tindakan_id' => $this->tindakan('TRT-012'), 'jumlah_sesi' => 4], ['tindakan_id' => $this->tindakan('TRT-021'), 'jumlah_sesi' => 1],
        ]])->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.jumlah_sesi', 4);

        // Paket yang sudah terjual tidak bisa dihapus
        $this->beliPaket('PKT-LSR6', bayar: false);
        $this->as('admin@eklinik.test');
        $this->deleteJson('/api/pakets/'.$this->paket('PKT-LSR6')->id)->assertUnprocessable();
        $this->deleteJson("/api/pakets/{$paket['id']}")->assertOk();
    }

    public function test_paket_aktif_setelah_lunas_dan_nilai_dialokasikan_per_sesi(): void
    {
        $paket = $this->beliPaket('PKT-GLOW', bayar: false);
        $this->assertSame('menunggu_bayar', $paket['status_efektif']);
        $tagihan = $this->getJson("/api/tagihans/{$paket['tagihan_id']}")->assertOk()
            ->assertJsonPath('items.0.kategori', 'paket')->assertJsonPath('items.0.paket_id', $this->paket('PKT-GLOW')->id)
            ->assertJsonPath('paket_pasiens.0.no_paket', $paket['no_paket'])->json();
        $this->assertNull($tagihan['kunjungan_id']);

        $this->bayarTunai($paket['tagihan_id'])->assertOk()->assertJsonPath('status', 'lunas');
        $aktif = $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertOk()->json();
        $this->assertSame(['aktif', 2000000, today()->addDays(120)->toDateString(), 6], [$aktif['status_efektif'], $aktif['nilai'], $aktif['berlaku_sampai'], $aktif['sisa_sesi']]);
        // Alokasi sebanding tarif × sesi: facial 350rb×4 = 1,4jt; peeling 500rb×2 = 1jt → 2jt × 1,4/2,4 = 1.166.666 → /4
        $this->assertSame([291666, 416666], array_column($aktif['items'], 'nilai_per_sesi'));

        // Pasien lain tanpa paket
        $this->getJson('/api/pasiens/'.Pasien::whereKeyNot($this->pasien->id)->value('id').'/pakets?aktif=1')->assertOk()->assertJsonCount(0);
        $this->getJson("/api/pasiens/{$this->pasien->id}/pakets?aktif=1")->assertOk()->assertJsonCount(1);
    }

    public function test_sesi_paket_dipakai_di_pemeriksaan_dan_ditagih_nol(): void
    {
        $paket = $this->beliPaket();
        $itemId = $paket['items'][0]['id'];

        $k1 = $this->kunjungan();
        // Treatment lain / jumlah melebihi sisa ditolak
        $this->pakaiSesi($k1, $itemId, 1, 'TRT-012')->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.paket_pasien_item_id');
        $this->pakaiSesi($k1, $itemId, 7)->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.paket_pasien_item_id');

        $res = $this->pakaiSesi($k1, $itemId)->assertOk();
        $this->assertSame($itemId, $res->json('tindakans.0.paket_pasien_item_id'));
        $this->assertSame($paket['no_paket'], $res->json('tindakans.0.paket_item.paket_pasien.no_paket'));
        $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertJsonPath('items.0.dipesan', 1)->assertJsonPath('sisa_sesi', 5);

        // Simpan ulang baris yang sama (dengan id) tidak menghitung dirinya sendiri
        $this->putJson("/api/kunjungans/{$k1}/pemeriksaan", ['tindakans' => [
            ['id' => $res->json('tindakans.0.id'), 'tindakan_id' => $this->tindakan('TRT-011'), 'jumlah' => 6, 'paket_pasien_item_id' => $itemId],
        ]])->assertOk();
        $this->pakaiSesi($k1, $itemId)->assertOk();

        $this->tutup($k1)->assertOk();
        $tagihan = Kunjungan::find($k1)->tagihan;
        $baris = $tagihan->items()->where('kategori', 'tindakan')->firstOrFail();
        $this->assertSame([0, 0], [$baris->harga, $baris->subtotal]);
        $this->assertStringContainsString("paket {$paket['no_paket']} sesi 1/6", $baris->deskripsi);
        $this->assertSame(100000, $tagihan->total); // konsultasi saja

        $detail = $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertOk()->json();
        $this->assertSame([1, 0, 5, 1000000], [$detail['items'][0]['terpakai'], $detail['items'][0]['dipesan'], $detail['sisa_sesi'], $detail['nilai_terpakai']]);
        $this->assertSame($k1, $detail['pemakaian'][0]['kunjungan']['id']);

        // Sesi kedua: urutan sesi berlanjut; kunjungan batal melepas sesi yang dipesan
        $k2 = $this->kunjungan(panggil: false);
        $this->pakaiSesi($k2, $itemId, 2)->assertOk();
        $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertJsonPath('sisa_sesi', 3);
        $this->as('pendaftaran@eklinik.test');
        $this->postJson("/api/kunjungans/{$k2}/batal")->assertOk();
        $this->as('dokter@eklinik.test');
        $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertJsonPath('sisa_sesi', 5);

        $k3 = $this->kunjungan();
        $this->pakaiSesi($k3, $itemId, 2)->assertOk();
        $this->tutup($k3)->assertOk();
        $this->assertStringContainsString('sesi 2–3/6', Kunjungan::find($k3)->tagihan->items()->where('kategori', 'tindakan')->value('deskripsi'));

        // Kedaluwarsa pada tanggal kunjungan → ditolak; perpanjang (manajer) → bisa lagi
        PaketPasien::whereKey($paket['id'])->update(['berlaku_sampai' => today()->toDateString()]);
        $k4 = $this->kunjungan();
        $this->pakaiSesi($k4, $itemId)->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.paket_pasien_item_id');
        $this->getJson("/api/pasiens/{$this->pasien->id}/pakets")->assertJsonPath('0.status_efektif', 'kedaluwarsa');

        $this->postJson("/api/paket-pasiens/{$paket['id']}/perpanjang", ['berlaku_sampai' => today()->addMonth()->toDateString(), 'alasan' => 'x'])->assertForbidden();
        $this->as('manajer@eklinik.test');
        $this->postJson("/api/paket-pasiens/{$paket['id']}/perpanjang", ['berlaku_sampai' => today()->addMonth()->toDateString(), 'alasan' => 'Pasien dirawat inap'])
            ->assertOk()->assertJsonPath('status_efektif', 'aktif');
        $this->assertTrue(AuditLog::where('aksi', 'perpanjang_paket')->where('pasien_id', $this->pasien->id)->exists());
        $this->as('dokter@eklinik.test');
        $this->pakaiSesi($k4, $itemId)->assertOk();
    }

    public function test_paket_belum_lunas_atau_milik_pasien_lain_tidak_bisa_dipakai(): void
    {
        $belumLunas = $this->beliPaket(bayar: false);
        $lain = Pasien::whereKeyNot($this->pasien->id)->firstOrFail();
        $punyaLain = $this->beliPaket('PKT-LSR6', $lain);

        $k = $this->kunjungan();
        $this->pakaiSesi($k, $belumLunas['items'][0]['id'])->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.paket_pasien_item_id');
        $this->pakaiSesi($k, $punyaLain['items'][0]['id'])->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.paket_pasien_item_id');

        // Tagihan penjualan dibatalkan → paket batal
        // Batal & refund tagihan: kasir.tagihan + kasir.void (administrator di data demo)
        $this->as('admin@eklinik.test');
        $this->postJson("/api/tagihans/{$belumLunas['tagihan_id']}/batal", ['alasan_batal' => 'Batal beli'])->assertOk();
        $this->assertSame('dibatalkan', PaketPasien::find($belumLunas['id'])->status->value);
    }

    public function test_refund_penuh_dan_refund_sisa_sesuai_kebijakan(): void
    {
        // Belum dipakai → refund tagihan penuh (BL-06) membuat paket direfund
        $utuh = $this->beliPaket('PKT-SCL2');
        $this->as('admin@eklinik.test');
        $this->postJson("/api/tagihans/{$utuh['tagihan_id']}/refund", ['alasan_refund' => 'Berubah pikiran'])->assertOk();
        $this->assertSame(['direfund', 450000], [PaketPasien::find($utuh['id'])->status->value, PaketPasien::find($utuh['id'])->refund_nominal]);

        // Sudah dipakai → refund tagihan ditolak; refund sisa mengikuti kebijakan
        $paket = $this->beliPaket();
        $k = $this->kunjungan();
        $this->pakaiSesi($k, $paket['items'][0]['id'])->assertOk();
        $this->tutup($k)->assertOk();

        $this->as('admin@eklinik.test');
        $this->postJson("/api/tagihans/{$paket['tagihan_id']}/refund", ['alasan_refund' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/paket-pasiens/{$paket['id']}/refund", ['metode' => 'tunai', 'alasan' => 'Pindah kota'])
            ->assertUnprocessable()->assertJsonValidationErrors('status');

        app(PengaturanService::class)->simpan(['paket' => ['refund_sisa' => true, 'potongan_refund_persen' => 10]]);
        $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertJsonPath('refund_sisa.nominal', 4500000);
        $this->postJson("/api/paket-pasiens/{$paket['id']}/refund", ['metode' => 'qris', 'alasan' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('metode');

        $shift = $this->postJson('/api/shift-kas', ['modal_awal' => 5000000])->assertCreated()->json();
        $this->postJson("/api/paket-pasiens/{$paket['id']}/refund", ['metode' => 'tunai', 'alasan' => 'Pindah kota'])->assertOk()
            ->assertJsonPath('status_efektif', 'direfund')->assertJsonPath('refund_nominal', 4500000)->assertJsonPath('sisa_sesi', 0);
        $this->getJson("/api/shift-kas/{$shift['id']}")->assertOk()
            ->assertJsonPath('rekap.refund_paket', 4500000)->assertJsonPath('rekap.kas_seharusnya', 500000);

        // Paket yang sudah direfund tidak bisa dipakai / direfund lagi
        $this->postJson("/api/paket-pasiens/{$paket['id']}/refund", ['metode' => 'tunai', 'alasan' => 'x'])->assertUnprocessable();
        $k2 = $this->kunjungan();
        $this->pakaiSesi($k2, $paket['items'][0]['id'])->assertUnprocessable();
    }

    public function test_alihkan_sisa_paket_ke_pasien_lain(): void
    {
        $paket = $this->beliPaket('PKT-GLOW');
        $k = $this->kunjungan();
        $this->pakaiSesi($k, $paket['items'][0]['id'], 1, 'TRT-021')->assertOk();
        $penerima = Pasien::whereKeyNot($this->pasien->id)->firstOrFail();

        $this->as('manajer@eklinik.test');
        $url = "/api/paket-pasiens/{$paket['id']}/alihkan";
        $this->postJson($url, ['pasien_id' => $penerima->id, 'alasan' => 'Hadiah'])->assertUnprocessable()->assertJsonValidationErrors('pasien_id');

        app(PengaturanService::class)->simpan(['paket' => ['boleh_transfer' => true]]);
        // Masih dipakai kunjungan terbuka → ditolak
        $this->postJson($url, ['pasien_id' => $penerima->id, 'alasan' => 'Hadiah'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->as('dokter@eklinik.test');
        $this->tutup($k)->assertOk();

        $this->as('manajer@eklinik.test');
        $this->postJson($url, ['pasien_id' => $this->pasien->id, 'alasan' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('pasien_id');
        $baru = $this->postJson($url, ['pasien_id' => $penerima->id, 'alasan' => 'Hadiah untuk adik'])->assertCreated()->json();
        $this->assertSame([$penerima->id, 'aktif', 5, $paket['berlaku_sampai'], $paket['id']],
            [$baru['pasien_id'], $baru['status_efektif'], $baru['sisa_sesi'], $baru['berlaku_sampai'], $baru['dialihkan_dari_id']]);
        $this->assertSame([3, 2], array_column($baru['items'], 'jumlah_sesi'));
        $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertJsonPath('status_efektif', 'dialihkan')->assertJsonPath('dialihkan_ke.id', $baru['id']);
    }

    public function test_kode_promo_periode_kuota_minimum_dan_pajak(): void
    {
        $this->as('kasir@eklinik.test');
        $this->getJson('/api/promos')->assertForbidden();

        $this->as('manajer@eklinik.test');
        $this->postJson('/api/promos', ['kode' => 'x', 'nama' => 'X', 'jenis' => 'persen', 'nilai' => 150, 'mulai' => today()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['kode', 'nilai']);
        $this->postJson('/api/promos', ['kode' => 'hemat 5', 'nama' => 'X', 'jenis' => 'nominal', 'nilai' => 5000, 'mulai' => today()->toDateString(), 'berakhir' => today()->subDay()->toDateString()])
            ->assertUnprocessable()->assertJsonValidationErrors(['kode', 'berakhir']);
        $this->postJson('/api/promos', ['kode' => 'hemat-50', 'nama' => 'Hemat 50rb', 'jenis' => 'nominal', 'nilai' => 50000, 'maks_potongan' => 1,
            'mulai' => today()->toDateString(), 'kuota' => 1, 'cabang_ids' => []])
            ->assertCreated()->assertJsonPath('kode', 'HEMAT-50')->assertJsonPath('maks_potongan', null)->assertJsonPath('cabang_ids', null);

        $this->as('kasir@eklinik.test');
        $tagihan = fn (int $harga, ?Pasien $p = null) => $this->postJson('/api/tagihans', ['pasien_id' => ($p ?? $this->pasien)->id,
            'items' => [['kategori' => 'produk', 'deskripsi' => 'Serum', 'jumlah' => 1, 'harga' => $harga]]])->assertCreated()->json('id');

        // Minimum transaksi & persen dengan batas
        $kecil = $tagihan(150000);
        $this->postJson("/api/tagihans/{$kecil}/promo", ['kode' => 'welcome10'])->assertUnprocessable()->assertJsonValidationErrors('kode');
        $this->postJson("/api/tagihans/{$kecil}/promo", ['kode' => 'TIDAKADA'])->assertUnprocessable()->assertJsonValidationErrors('kode');
        $t1 = $tagihan(300000);
        $this->postJson("/api/tagihans/{$t1}/promo", ['kode' => ' welcome10 '])->assertOk()
            ->assertJsonPath('diskon_promo', 30000)->assertJsonPath('grand_total', 270000)->assertJsonPath('promo.kode', 'WELCOME10');
        $this->deleteJson("/api/tagihans/{$t1}/promo")->assertOk()->assertJsonPath('diskon_promo', 0)->assertJsonPath('grand_total', 300000);
        $t2 = $tagihan(2000000);
        $this->postJson("/api/tagihans/{$t2}/promo", ['kode' => 'WELCOME10'])->assertOk()->assertJsonPath('diskon_promo', 100000);

        // Diskon manual + promo tidak boleh melebihi total; bayar mencatat pemakaian
        $this->bayarTunai($t2, 1950000)->assertUnprocessable()->assertJsonValidationErrors('diskon');
        $this->bayarTunai($t2, 0)->assertOk()->assertJsonPath('diskon_promo', 100000)->assertJsonPath('grand_total', 1900000);
        $this->assertDatabaseHas('promo_pemakaians', ['tagihan_id' => $t2, 'potongan' => 100000, 'pasien_id' => $this->pasien->id]);

        // Kuota per pasien 1: pasien sama ditolak, pasien lain boleh; refund mengembalikan kuota
        $t3 = $tagihan(300000);
        $this->postJson("/api/tagihans/{$t3}/promo", ['kode' => 'WELCOME10'])->assertUnprocessable()->assertJsonValidationErrors('kode');
        $lain = $tagihan(300000, Pasien::whereKeyNot($this->pasien->id)->first());
        $this->postJson("/api/tagihans/{$lain}/promo", ['kode' => 'WELCOME10'])->assertOk();
        $this->as('admin@eklinik.test');
        $this->postJson("/api/tagihans/{$t2}/refund", ['alasan_refund' => 'Retur'])->assertOk();
        $this->as('kasir@eklinik.test');
        $this->postJson("/api/tagihans/{$t3}/promo", ['kode' => 'WELCOME10'])->assertOk();

        // Kuota total habis di antara pasang & bayar → ditolak saat bayar
        $a = $tagihan(100000);
        $b = $tagihan(100000, Pasien::whereKeyNot($this->pasien->id)->first());
        $this->postJson("/api/tagihans/{$a}/promo", ['kode' => 'HEMAT-50'])->assertOk();
        $this->postJson("/api/tagihans/{$b}/promo", ['kode' => 'HEMAT-50'])->assertOk();
        $this->bayarTunai($a)->assertOk();
        $this->bayarTunai($b)->assertUnprocessable()->assertJsonValidationErrors('kode');

        // Periode & cabang
        Promo::where('kode', 'HEMAT-50')->update(['kuota' => null, 'berakhir' => today()->subDay()]);
        $c = $tagihan(100000);
        $this->postJson("/api/tagihans/{$c}/promo", ['kode' => 'HEMAT-50'])->assertUnprocessable();
        Promo::where('kode', 'HEMAT-50')->update(['berakhir' => null, 'mulai' => today()->addDay()]);
        $this->postJson("/api/tagihans/{$c}/promo", ['kode' => 'HEMAT-50'])->assertUnprocessable();
        Promo::where('kode', 'HEMAT-50')->update(['mulai' => today(), 'cabang_ids' => json_encode([999])]);
        $this->postJson("/api/tagihans/{$c}/promo", ['kode' => 'HEMAT-50'])->assertUnprocessable();

        // Pajak dihitung dari nilai setelah potongan promo
        app(PengaturanService::class)->simpan(['keuangan' => ['pajak_persen' => 10]]);
        $p = $tagihan(500000, Pasien::whereKeyNot($this->pasien->id)->skip(1)->first());
        $this->postJson("/api/tagihans/{$p}/promo", ['kode' => 'WELCOME10'])->assertOk()->assertJsonPath('grand_total', 495000);
        $this->bayarTunai($p)->assertOk()->assertJsonPath('pajak', 45000)->assertJsonPath('grand_total', 495000);
    }

    public function test_promo_per_treatment_dan_paket_mengurangi_nilai_paket(): void
    {
        $this->as('kasir@eklinik.test');
        $produk = $this->postJson('/api/tagihans', ['pasien_id' => $this->pasien->id,
            'items' => [['kategori' => 'produk', 'deskripsi' => 'Serum', 'jumlah' => 1, 'harga' => 900000]]])->assertCreated()->json('id');
        $this->postJson("/api/tagihans/{$produk}/promo", ['kode' => 'LASER200'])->assertUnprocessable()->assertJsonValidationErrors('kode');

        // Promo paket laser: nilai bersih paket = 6jt − 200rb, dialokasikan per sesi
        $paket = $this->beliPaket(bayar: false);
        $this->postJson("/api/tagihans/{$paket['tagihan_id']}/promo", ['kode' => 'LASER200'])->assertOk()->assertJsonPath('diskon_promo', 200000);
        $this->bayarTunai($paket['tagihan_id'])->assertOk()->assertJsonPath('grand_total', 5800000);
        $aktif = $this->getJson("/api/paket-pasiens/{$paket['id']}")->json();
        $this->assertSame([5800000, 966666], [$aktif['nilai'], $aktif['items'][0]['nilai_per_sesi']]);

        // Promo treatment laser di tagihan kunjungan (tanpa paket)
        $k = $this->kunjungan(Pasien::whereKeyNot($this->pasien->id)->first());
        $this->putJson("/api/kunjungans/{$k}/pemeriksaan", ['tindakans' => [['tindakan_id' => $this->tindakan('TRT-011')]]])->assertOk();
        $this->tutup($k)->assertOk();
        $tagihanKunjungan = Kunjungan::find($k)->tagihan;
        $this->assertSame($this->tindakan('TRT-011'), $tagihanKunjungan->items()->where('kategori', 'tindakan')->value('tindakan_id'));
        $this->as('kasir@eklinik.test');
        $this->postJson("/api/tagihans/{$tagihanKunjungan->id}/promo", ['kode' => 'LASER200'])->assertOk()
            ->assertJsonPath('diskon_promo', 200000)->assertJsonPath('grand_total', 100000 + 1200000 - 200000);
    }
}
