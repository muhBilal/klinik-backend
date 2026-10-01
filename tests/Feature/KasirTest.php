<?php

namespace Tests\Feature;

use App\Enums\Penjamin;
use App\Enums\Role;
use App\Enums\StatusKunjungan;
use App\Enums\StatusTagihan;
use App\Models\Kunjungan;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Tagihan;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Kasir: split payment, shift kas, batas diskon, void & refund, tagihan mandiri
 * (PRD BL-02, BL-03, BL-05, BL-06, FR-04; temuan teknis 8.3 #3 & #7).
 */
class KasirTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function kasir(): User
    {
        return User::where('role', Role::Kasir->value)->firstOrFail();
    }

    private function admin(): User
    {
        return User::where('role', Role::Admin->value)->firstOrFail();
    }

    /** Tagihan mandiri (tanpa kunjungan) senilai `$harga`. */
    private function buatTagihan(int $harga = 100000): array
    {
        return $this->postJson('/api/tagihans', [
            'pasien_id' => Pasien::value('id'),
            'keterangan' => 'Penjualan produk',
            'items' => [['kategori' => 'produk', 'deskripsi' => 'Serum vitamin C', 'jumlah' => 1, 'harga' => $harga]],
        ])->assertCreated()->json();
    }

    public function test_tagihan_bisa_berdiri_sendiri_tanpa_kunjungan(): void
    {
        Sanctum::actingAs($this->kasir());

        $tagihan = $this->buatTagihan();

        $this->assertNull($tagihan['kunjungan_id']);
        $this->assertSame(100000, $tagihan['grand_total']);
        $this->assertDatabaseHas('tagihans', ['id' => $tagihan['id'], 'kunjungan_id' => null]);
    }

    public function test_split_payment_beberapa_metode_dalam_satu_tagihan(): void
    {
        Sanctum::actingAs($this->kasir());

        $tagihan = $this->buatTagihan(100000);

        $res = $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", [
            'pembayarans' => [
                ['metode' => 'qris', 'jumlah' => 60000, 'referensi' => 'QR-123'],
                ['metode' => 'tunai', 'jumlah' => 50000],
            ],
        ])->assertOk();

        $res->assertJsonPath('status', StatusTagihan::Lunas->value);
        $res->assertJsonPath('dibayar', 110000);
        $res->assertJsonPath('kembalian', 10000);
        // Lebih dari satu metode -> kolom metode_bayar tunggal dikosongkan
        $res->assertJsonPath('metode_bayar', null);
        $this->assertCount(2, $res->json('pembayarans'));
    }

    public function test_non_tunai_tidak_boleh_melebihi_tagihan(): void
    {
        Sanctum::actingAs($this->kasir());

        $tagihan = $this->buatTagihan(100000);

        $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", [
            'pembayarans' => [['metode' => 'qris', 'jumlah' => 150000]],
        ])->assertStatus(422)->assertJsonValidationErrors('pembayarans');
    }

    public function test_pajak_ditambahkan_dan_disnapshot_per_tagihan(): void
    {
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/pengaturan', ['keuangan' => ['pajak_persen' => 10]])->assertOk();

        Sanctum::actingAs($this->kasir());
        $tagihan = $this->buatTagihan(100000);

        $this->assertSame(10, $tagihan['pajak_persen']);
        $this->assertSame(10000, $tagihan['pajak']);
        $this->assertSame(110000, $tagihan['grand_total']);

        // Mengubah tarif setelah tagihan dibuat tidak mengubah tagihan lama
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/pengaturan', ['keuangan' => ['pajak_persen' => 0]])->assertOk();

        Sanctum::actingAs($this->kasir());
        $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", [
            'pembayarans' => [['metode' => 'tunai', 'jumlah' => 110000]],
        ])->assertOk()->assertJsonPath('grand_total', 110000);
    }

    public function test_batas_diskon_per_peran(): void
    {
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/pengaturan', ['keuangan' => ['batas_diskon_persen' => ['kasir' => 10]]])->assertOk();

        Sanctum::actingAs($this->kasir());
        $tagihan = $this->buatTagihan(100000);

        // 20% > batas 10%
        $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", [
            'pembayarans' => [['metode' => 'tunai', 'jumlah' => 80000]], 'diskon' => 20000,
        ])->assertStatus(422)->assertJsonValidationErrors('diskon');

        // 10% tepat di batas -> boleh
        $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", [
            'pembayarans' => [['metode' => 'tunai', 'jumlah' => 90000]], 'diskon' => 10000,
        ])->assertOk()->assertJsonPath('grand_total', 90000);
    }

    public function test_diskon_di_atas_batas_boleh_dengan_persetujuan_atasan(): void
    {
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/pengaturan', ['keuangan' => ['batas_diskon_persen' => ['kasir' => 10, 'manajer' => 30]]])->assertOk();

        Sanctum::actingAs($this->kasir());
        $tagihan = $this->buatTagihan(100000);
        $url = "/api/tagihans/{$tagihan['id']}/bayar";
        $bayar = fn (int $diskon, ?array $persetujuan = null) => $this->postJson($url, array_filter([
            'pembayarans' => [['metode' => 'tunai', 'jumlah' => 100000 - $diskon]], 'diskon' => $diskon, 'persetujuan' => $persetujuan,
        ]));

        // Tanpa persetujuan: ditolak dengan penanda perlu_persetujuan (dipakai UI untuk menampilkan form atasan)
        $bayar(20000)->assertStatus(422)->assertJsonValidationErrors(['diskon', 'perlu_persetujuan']);

        // Password salah, kasir sendiri, dan pengguna tanpa izin kasir.diskon ditolak
        $bayar(20000, ['email' => 'manajer@eklinik.test', 'password' => 'salah'])->assertStatus(422)->assertJsonValidationErrors('persetujuan');
        $bayar(20000, ['email' => 'kasir@eklinik.test', 'password' => 'password'])->assertStatus(422)->assertJsonValidationErrors('persetujuan');
        $bayar(20000, ['email' => 'perawat@eklinik.test', 'password' => 'password'])->assertStatus(422)->assertJsonValidationErrors('persetujuan');

        // Melebihi batas manajer sendiri (30%)
        $bayar(40000, ['email' => 'manajer@eklinik.test', 'password' => 'password'])->assertStatus(422)->assertJsonValidationErrors('persetujuan');

        $manajer = User::where('email', 'manajer@eklinik.test')->firstOrFail();
        $bayar(20000, ['email' => 'manajer@eklinik.test', 'password' => 'password'])
            ->assertOk()
            ->assertJsonPath('grand_total', 80000)
            ->assertJsonPath('diskon_disetujui_oleh', $manajer->id)
            ->assertJsonPath('penyetuju_diskon.name', $manajer->name);

        $this->assertDatabaseHas('audit_logs', ['aksi' => 'setujui_diskon', 'tipe' => 'tagihan', 'subjek_id' => $tagihan['id']]);
    }

    public function test_wajib_shift_menolak_pembayaran_tanpa_shift_terbuka(): void
    {
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/pengaturan', ['keuangan' => ['wajib_shift' => true]])->assertOk();

        Sanctum::actingAs($this->kasir());
        $tagihan = $this->buatTagihan(50000);
        $body = ['pembayarans' => [['metode' => 'tunai', 'jumlah' => 50000]]];

        $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", $body)->assertStatus(422)->assertJsonValidationErrors('shift');

        $this->postJson('/api/shift-kas', ['modal_awal' => 0])->assertCreated();
        $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", $body)->assertOk();
    }

    public function test_admin_tidak_dibatasi_diskon(): void
    {
        Sanctum::actingAs($this->admin());
        $this->putJson('/api/pengaturan', ['keuangan' => ['batas_diskon_persen' => ['kasir' => 10, 'admin' => 5]]])->assertOk();

        $tagihan = $this->buatTagihan(100000);

        // Peran akses penuh lolos meski melebihi entri batasnya
        $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", [
            'pembayarans' => [['metode' => 'tunai', 'jumlah' => 50000]], 'diskon' => 50000,
        ])->assertOk()->assertJsonPath('grand_total', 50000);
    }

    public function test_shift_kas_merekap_per_metode_dan_menghitung_selisih(): void
    {
        Sanctum::actingAs($this->kasir());

        $this->assertSame('null', $this->getJson('/api/shift-kas/aktif')->assertOk()->getContent());

        $shift = $this->postJson('/api/shift-kas', ['modal_awal' => 200000])->assertCreated()->json();

        // Shift kedua ditolak selama yang pertama belum ditutup
        $this->postJson('/api/shift-kas', ['modal_awal' => 100000])->assertStatus(422);

        $tagihan = $this->buatTagihan(100000);
        $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", [
            'pembayarans' => [['metode' => 'tunai', 'jumlah' => 70000], ['metode' => 'qris', 'jumlah' => 30000]],
        ])->assertOk();

        $aktif = $this->getJson('/api/shift-kas/aktif')->assertOk();
        $aktif->assertJsonPath('rekap.total', 100000);
        // modal 200.000 + tunai 70.000
        $aktif->assertJsonPath('rekap.kas_seharusnya', 270000);

        // Kas fisik kurang 5.000 -> selisih negatif
        $this->postJson("/api/shift-kas/{$shift['id']}/tutup", ['kas_fisik' => 265000])
            ->assertOk()->assertJsonPath('selisih', -5000);

        $this->assertSame('null', $this->getJson('/api/shift-kas/aktif')->assertOk()->getContent());
    }

    public function test_kembalian_tunai_tidak_ikut_rekap_kas(): void
    {
        Sanctum::actingAs($this->kasir());
        $this->postJson('/api/shift-kas', ['modal_awal' => 100000])->assertCreated();

        // Tagihan 80.000: QRIS 30.000 + tunai diserahkan 100.000 → kembalian 50.000
        $tagihan = $this->buatTagihan(80000);
        $res = $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", [
            'pembayarans' => [['metode' => 'qris', 'jumlah' => 30000], ['metode' => 'tunai', 'jumlah' => 100000]],
        ])->assertOk()
            ->assertJsonPath('dibayar', 130000)
            ->assertJsonPath('kembalian', 50000);

        $tunai = collect($res->json('pembayarans'))->firstWhere('metode', 'tunai');
        $this->assertSame(50000, $tunai['jumlah']);
        $this->assertSame(100000, $tunai['diterima']);

        // Kas seharusnya = modal + tunai bersih (bukan uang yang diserahkan)
        $this->getJson('/api/shift-kas/aktif')->assertOk()
            ->assertJsonPath('rekap.total', 80000)
            ->assertJsonPath('rekap.kas_seharusnya', 150000);
    }

    public function test_void_tagihan_belum_bayar_butuh_izin_khusus(): void
    {
        Sanctum::actingAs($this->kasir());
        $tagihan = $this->buatTagihan();

        // Kasir tidak punya izin kasir.void
        $this->postJson("/api/tagihans/{$tagihan['id']}/batal", ['alasan_batal' => 'Salah input'])
            ->assertForbidden();

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/tagihans/{$tagihan['id']}/batal", ['alasan_batal' => 'Salah input'])
            ->assertOk()->assertJsonPath('status', StatusTagihan::Batal->value);

        // Tagihan batal tidak bisa dibayar
        Sanctum::actingAs($this->kasir());
        $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", [
            'pembayarans' => [['metode' => 'tunai', 'jumlah' => 100000]],
        ])->assertStatus(422);
    }

    public function test_refund_membatalkan_pembayaran_dan_tidak_dihitung_di_rekap(): void
    {
        Sanctum::actingAs($this->kasir());

        $shift = $this->postJson('/api/shift-kas', ['modal_awal' => 0])->assertCreated()->json();
        $tagihan = $this->buatTagihan(100000);
        $this->postJson("/api/tagihans/{$tagihan['id']}/bayar", [
            'pembayarans' => [['metode' => 'tunai', 'jumlah' => 100000]],
        ])->assertOk();

        $this->getJson('/api/shift-kas/aktif')->assertJsonPath('rekap.total', 100000);

        Sanctum::actingAs($this->admin());
        $this->postJson("/api/tagihans/{$tagihan['id']}/refund", ['alasan_refund' => 'Produk cacat'])
            ->assertOk()->assertJsonPath('status', StatusTagihan::Batal->value);

        // Pembayaran ditandai dikembalikan, bukan dihapus (jejak audit tetap ada)
        $this->assertDatabaseCount('pembayarans', 1);
        $this->assertSame(0, Tagihan::find($tagihan['id'])->totalDibayar());

        Sanctum::actingAs($this->kasir());
        $rekap = $this->getJson("/api/shift-kas/{$shift['id']}")->assertOk();
        $rekap->assertJsonPath('rekap.total', 0);
        $rekap->assertJsonPath('rekap.total_refund', 100000);
    }

    public function test_refund_hanya_untuk_tagihan_lunas(): void
    {
        Sanctum::actingAs($this->admin());
        $tagihan = $this->buatTagihan();

        $this->postJson("/api/tagihans/{$tagihan['id']}/refund", ['alasan_refund' => 'x'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
    }

    public function test_satu_kunjungan_boleh_punya_beberapa_tagihan(): void
    {
        // Temuan teknis 8.3 #3: unique kunjungan_id dilepas.
        Sanctum::actingAs($this->kasir());

        $pasienId = Pasien::value('id');
        $cabangId = $this->kasir()->cabang_id;

        $kunjungan = Kunjungan::create([
            'cabang_id' => $cabangId,
            'no_registrasi' => 'REG-TEST-1',
            'pasien_id' => $pasienId,
            'poli_id' => Poli::value('id'),
            'tanggal' => today(),
            'no_antrian' => 99,
            'penjamin' => Penjamin::Umum,
            'status' => StatusKunjungan::Menunggu,
        ]);

        foreach ([1, 2] as $ke) {
            Tagihan::create([
                'cabang_id' => $cabangId,
                'no_tagihan' => "INV-TEST-{$ke}",
                'kunjungan_id' => $kunjungan->id,
                'pasien_id' => $pasienId,
                'total' => 50000,
                'grand_total' => 50000,
                'status' => StatusTagihan::BelumBayar,
            ]);
        }

        $this->assertSame(2, $kunjungan->tagihans()->count());
    }
}
