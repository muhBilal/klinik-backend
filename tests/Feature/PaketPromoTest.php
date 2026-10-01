<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Icd10;
use App\Models\Icd9cm;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\Paket;
use App\Models\PaketPasien;
use App\Models\Pasien;
use App\Models\Pemeriksaan;
use App\Models\Peran;
use App\Models\Poli;
use App\Models\Promo;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Paket multi-sesi (PRD TR-02, BL-01) dan voucher & promo (TR-06): jual di kasir atau dipesan dokter/terapis dari pemeriksaan
 * (ditagihkan bersama tagihan kunjungan, sesi pertama di kunjungan yang sama) → aktif saat lunas, pemakaian sesi di pemeriksaan
 * (ditagih Rp 0, boleh dicatat terapis), sisa & kedaluwarsa, refund penuh/sisa, pengalihan, perpanjangan, serta kode promo dengan
 * periode, kuota, kuota per pasien, minimum transaksi, cabang, dan treatment/paket tertentu.
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

    public function test_dokter_memesan_paket_di_pemeriksaan_dan_ditagih_bersama_kunjungan(): void
    {
        $k = $this->kunjungan();
        $url = "/api/kunjungans/{$k}/pakets";
        $laser = $this->paket('PKT-LSR6');

        // Kasir & front office tidak memesankan dari pemeriksaan (kasir tetap bisa jual langsung)
        foreach (['kasir@eklinik.test', 'pendaftaran@eklinik.test'] as $email) {
            $this->as($email);
            $this->postJson($url, ['paket_id' => $laser->id])->assertForbidden();
        }

        $dokter = $this->as('dokter@eklinik.test');
        $pesanan = $this->postJson($url, ['paket_id' => $laser->id, 'catatan' => 'Saran dokter'])->assertCreated()
            ->assertJsonPath('status', 'menunggu_bayar')->assertJsonPath('kunjungan_id', $k)->assertJsonPath('tagihan_id', null)
            ->assertJsonPath('pembuat.name', $dokter->name)->assertJsonPath('kunjungan.id', $k)->json();
        $this->postJson($url, ['paket_id' => $laser->id])->assertUnprocessable()->assertJsonValidationErrors('paket_id');
        $itemId = $pesanan['items'][0]['id'];

        // Pesanan hanya jadi pilihan "pakai paket" di kunjungan pemesannya
        $daftar = "/api/pasiens/{$this->pasien->id}/pakets?aktif=1";
        $this->getJson($daftar)->assertOk()->assertJsonCount(0);
        $this->getJson("{$daftar}&kunjungan_id={$k}")->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $pesanan['id']);
        $lain = $this->kunjungan();
        $this->getJson("{$daftar}&kunjungan_id={$lain}")->assertOk()->assertJsonCount(0);
        $this->pakaiSesi($lain, $itemId)->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.paket_pasien_item_id');

        // Sesi pertama langsung dipakai di kunjungan yang sama; selama dipakai, pesanan tidak bisa dibatalkan
        $this->pakaiSesi($k, $itemId)->assertOk()->assertJsonPath('tindakans.0.paket_pasien_item_id', $itemId);
        $this->getJson("/api/paket-pasiens/{$pesanan['id']}")->assertJsonPath('items.0.dipesan', 1)->assertJsonPath('sisa_sesi', 5);
        $this->deleteJson("{$url}/{$pesanan['id']}")->assertUnprocessable()->assertJsonValidationErrors('status');

        $this->tutup($k)->assertOk();
        $tagihan = Kunjungan::find($k)->tagihan;
        $items = $tagihan->items()->orderBy('id')->get();
        $this->assertSame(['konsultasi', 'tindakan', 'paket'], $items->pluck('kategori')->all());
        $this->assertSame([100000, 0, 6000000], $items->pluck('subtotal')->all());
        $this->assertStringContainsString("paket {$pesanan['no_paket']} sesi 1/6", $items[1]->deskripsi);
        $this->assertSame($tagihan->id, PaketPasien::find($pesanan['id'])->tagihan_id);
        $this->getJson("{$daftar}&kunjungan_id={$k}")->assertJsonCount(0);

        // Pemeriksaan sudah ditutup: pesan / batal lewat pemeriksaan ditolak
        $this->postJson($url, ['paket_id' => $this->paket('PKT-GLOW')->id])->assertUnprocessable()->assertJsonValidationErrors('kunjungan');
        $this->deleteJson("{$url}/{$pesanan['id']}")->assertUnprocessable()->assertJsonValidationErrors('kunjungan');

        // Sekali bayar di kasir: paket aktif, sesi pertama tercatat terpakai, kunjungan selesai
        $this->as('kasir@eklinik.test');
        $this->bayarTunai($tagihan->id)->assertOk()->assertJsonPath('grand_total', 6100000);
        $aktif = $this->getJson("/api/paket-pasiens/{$pesanan['id']}")->assertOk()->json();
        $this->assertSame(['aktif', 6000000, today()->addDays(180)->toDateString(), 5, 1, 1000000],
            [$aktif['status_efektif'], $aktif['nilai'], $aktif['berlaku_sampai'], $aktif['sisa_sesi'], $aktif['items'][0]['terpakai'], $aktif['nilai_terpakai']]);
        $this->assertSame('selesai', Kunjungan::find($k)->status->value);
    }

    public function test_pesanan_paket_dibatalkan_dari_pemeriksaan_atau_ikut_batal_bersama_kunjungan(): void
    {
        // Sebelum pasien dipanggil pun terapis boleh memesankan
        $k = $this->kunjungan(panggil: false);
        $url = "/api/kunjungans/{$k}/pakets";
        $glow = $this->paket('PKT-GLOW')->id;

        $this->as('terapis@eklinik.test');
        $pertama = $this->postJson($url, ['paket_id' => $glow])->assertCreated()->json();
        $this->deleteJson("{$url}/{$pertama['id']}")->assertOk()->assertJsonPath('status', 'dibatalkan');
        $kedua = $this->postJson($url, ['paket_id' => $glow])->assertCreated()->json();

        // Pesanan kunjungan lain tidak bisa dibatalkan lewat kunjungan ini
        $lain = $this->kunjungan(panggil: false);
        $this->deleteJson("/api/kunjungans/{$lain}/pakets/{$kedua['id']}")->assertNotFound();

        $this->as('pendaftaran@eklinik.test');
        $this->postJson("/api/kunjungans/{$k}/batal")->assertOk();
        $this->assertSame(['dibatalkan', 'dibatalkan'], [PaketPasien::find($pertama['id'])->status->value, PaketPasien::find($kedua['id'])->status->value]);
    }

    public function test_terapis_mencatat_tindakan_dan_sesi_paket_tetapi_penutupan_tetap_dokter(): void
    {
        $paket = $this->beliPaket();
        $itemId = $paket['items'][0]['id'];
        $k = $this->kunjungan();
        $laser = Tindakan::where('kode', 'TRT-011')->firstOrFail();
        $icdLain = Icd9cm::whereKeyNot($laser->icd9cm_id)->value('id');

        $terapis = $this->as('terapis@eklinik.test');
        $res = $this->putJson("/api/kunjungans/{$k}/pemeriksaan", [
            'tekanan_darah' => '110/70',
            'diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => [['tindakan_id' => $laser->id, 'jumlah' => 1, 'paket_pasien_item_id' => $itemId, 'icd9cm_id' => $icdLain]],
        ])->assertOk();
        // Pelaksana default = terapis yang mencatat; kode ICD-9-CM tetap bawaan katalog; diagnosa tetap wewenang dokter
        $this->assertSame([$terapis->id, $itemId, $laser->icd9cm_id],
            [$res->json('tindakans.0.petugas_id'), $res->json('tindakans.0.paket_pasien_item_id'), $res->json('tindakans.0.icd9cm_id')]);
        $this->assertSame(0, Pemeriksaan::where('kunjungan_id', $k)->firstOrFail()->diagnosas()->count());
        $this->postJson("/api/kunjungans/{$k}/selesai")->assertForbidden();

        // Perawat menyimpan tanda vital tanpa daftar tindakan → tindakan terapis tetap
        $this->as('perawat@eklinik.test');
        $this->putJson("/api/kunjungans/{$k}/pemeriksaan", ['suhu' => 36.5])->assertOk()->assertJsonCount(1, 'tindakans');

        // Tanpa izin rme.tindakan, daftar tindakan dari perawat diabaikan
        DB::table('peran_izins')->where('izin', 'rme.tindakan')->where('peran_id', Peran::where('kode', 'perawat')->value('id'))->delete();
        $this->as('perawat@eklinik.test');
        $this->putJson("/api/kunjungans/{$k}/pemeriksaan", ['tindakans' => []])->assertOk()->assertJsonCount(1, 'tindakans');

        // Dokter menutup & menandatangani; sesi paket ditagih Rp 0
        $this->as('dokter@eklinik.test');
        $this->tutup($k)->assertOk();
        $this->assertSame(0, Kunjungan::find($k)->tagihan->items()->where('kategori', 'tindakan')->value('subtotal'));
        $this->getJson("/api/paket-pasiens/{$paket['id']}")->assertJsonPath('items.0.terpakai', 1);
    }

    public function test_tagihan_gabungan_promo_diskon_dan_refund_paket_pesanan(): void
    {
        $k = $this->kunjungan();
        $pesanan = $this->postJson("/api/kunjungans/{$k}/pakets", ['paket_id' => $this->paket('PKT-LSR6')->id])->assertCreated()->json();
        $this->pakaiSesi($k, $pesanan['items'][0]['id'])->assertOk();
        $this->tutup($k)->assertOk();
        $tagihan = Kunjungan::find($k)->tagihan;

        // LASER200 hanya untuk treatment & paket laser → seluruh potongan jatuh ke baris paket (5,8jt); diskon manual dibagi sebanding sisa
        // setelah promo: 61rb × 5,8jt / 5,9jt = 59.966 → neto paket 5.740.034, konsultasi 100rb − 1.034
        $this->as('kasir@eklinik.test');
        $this->postJson("/api/tagihans/{$tagihan->id}/promo", ['kode' => 'LASER200'])->assertOk()->assertJsonPath('diskon_promo', 200000);
        $this->bayarTunai($tagihan->id, 61000)->assertOk()->assertJsonPath('grand_total', 6100000 - 61000 - 200000);
        $aktif = $this->getJson("/api/paket-pasiens/{$pesanan['id']}")->json();
        $this->assertSame([5740034, 956672], [$aktif['nilai'], $aktif['items'][0]['nilai_per_sesi']]);
        $this->assertSame([98966, 0, 5740034], $tagihan->items()->orderBy('id')->pluck('neto')->all());

        // Refund tagihan kunjungan: sesi yang dipakai di kunjungan itu sendiri ikut direfund
        $this->as('admin@eklinik.test');
        $this->postJson("/api/tagihans/{$tagihan->id}/refund", ['alasan_refund' => 'Pasien keberatan'])->assertOk();
        $this->assertSame(['direfund', 5740034], [PaketPasien::find($pesanan['id'])->status->value, PaketPasien::find($pesanan['id'])->refund_nominal]);

        // Sudah dipakai di kunjungan lain → refund tagihan pemesanannya ditolak (gunakan refund sisa)
        $k2 = $this->kunjungan();
        $glow = $this->postJson("/api/kunjungans/{$k2}/pakets", ['paket_id' => $this->paket('PKT-GLOW')->id])->assertCreated()->json();
        $this->tutup($k2)->assertOk();
        $tagihan2 = Kunjungan::find($k2)->tagihan;
        $this->as('kasir@eklinik.test');
        $this->bayarTunai($tagihan2->id)->assertOk();
        $k3 = $this->kunjungan();
        $this->pakaiSesi($k3, $glow['items'][0]['id'], 1, 'TRT-021')->assertOk();
        $this->tutup($k3)->assertOk();
        $this->as('admin@eklinik.test');
        $this->postJson("/api/tagihans/{$tagihan2->id}/refund", ['alasan_refund' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_kasir_melepas_paket_pesanan_yang_ditolak_pasien(): void
    {
        $k = $this->kunjungan();
        $pesanan = $this->postJson("/api/kunjungans/{$k}/pakets", ['paket_id' => $this->paket('PKT-LSR6')->id])->assertCreated()->json();
        $this->pakaiSesi($k, $pesanan['items'][0]['id'])->assertOk();
        $this->tutup($k)->assertOk();
        $tagihan = Kunjungan::find($k)->tagihan;
        $url = "/api/tagihans/{$tagihan->id}/pakets/{$pesanan['id']}";
        $this->deleteJson($url)->assertForbidden(); // dokter tanpa kasir.tagihan

        // Promo laser tetap berlaku (treatment laser kini ditagih normal); paket batal, sesi hari ini ditagih tarif normal
        $this->as('kasir@eklinik.test');
        $this->postJson("/api/tagihans/{$tagihan->id}/promo", ['kode' => 'LASER200'])->assertOk();
        $this->deleteJson($url)->assertOk()->assertJsonPath('total', 1300000)->assertJsonPath('diskon_promo', 200000)
            ->assertJsonPath('grand_total', 1100000)->assertJsonPath('paket_pasiens.0.status', 'dibatalkan');
        $items = $tagihan->items()->orderBy('id')->get();
        $this->assertSame([['konsultasi', 100000], ['tindakan', 1200000]], $items->map(fn ($i) => [$i->kategori, $i->subtotal])->all());
        $this->assertStringNotContainsString('paket', $items[1]->deskripsi);
        $this->assertNull(KunjunganTindakan::where('kunjungan_id', $k)->value('paket_pasien_item_id'));
        $this->assertSame('dibatalkan', PaketPasien::find($pesanan['id'])->status->value);

        // Tanda tangan RME tetap sah (pemakaian sesi bukan bagian hash)
        $this->as('dokter@eklinik.test');
        $this->getJson("/api/kunjungans/{$k}/verifikasi")->assertOk()->assertJsonPath('valid', true);

        // Sudah dilepas / tagihan lunas / penjualan langsung → ditolak
        $this->as('kasir@eklinik.test');
        $this->deleteJson($url)->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->bayarTunai($tagihan->id)->assertOk()->assertJsonPath('grand_total', 1100000);
        $langsung = $this->beliPaket(bayar: false);
        $this->deleteJson("/api/tagihans/{$langsung['tagihan_id']}/pakets/{$langsung['id']}")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->deleteJson("/api/tagihans/{$tagihan->id}/pakets/{$langsung['id']}")->assertNotFound();
    }

    public function test_laporan_paket_hanya_mengakui_sesi_paket_berbayar(): void
    {
        $k = $this->kunjungan();
        $pesanan = $this->postJson("/api/kunjungans/{$k}/pakets", ['paket_id' => $this->paket('PKT-LSR6')->id])->assertCreated()->json();
        $this->pakaiSesi($k, $pesanan['items'][0]['id'])->assertOk();
        $this->tutup($k)->assertOk();
        $tagihan = Kunjungan::find($k)->tagihan;

        $this->as('admin@eklinik.test');
        $periode = '?mulai='.today()->subDays(7)->toDateString().'&selesai='.today()->toDateString();
        $laporan = fn () => $this->getJson("/api/laporan/paket{$periode}")->assertOk()->json('ringkasan');
        // Belum lunas: sesi hari ini belum diakui
        $this->assertSame([0, 0, 0], [$laporan()['terjual'], $laporan()['sesi_dipakai'], $laporan()['nilai_dipakai']]);

        $this->bayarTunai($tagihan->id)->assertOk();
        $this->assertSame([1, 1, 1000000], [$laporan()['terjual'], $laporan()['sesi_dipakai'], $laporan()['nilai_dipakai']]);

        // Refund penuh tagihan kunjungan → sesinya ikut tidak diakui
        $this->postJson("/api/tagihans/{$tagihan->id}/refund", ['alasan_refund' => 'Keberatan'])->assertOk();
        $this->assertSame([0, 0, 6000000], [$laporan()['sesi_dipakai'], $laporan()['nilai_dipakai'], $laporan()['refund']]);
    }
}
