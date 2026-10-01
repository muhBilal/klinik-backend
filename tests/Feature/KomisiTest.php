<?php

namespace Tests\Feature;

use App\Models\Icd10;
use App\Models\Komisi;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\Paket;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\Tagihan;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Komisi & jasa medis (PRD KM-01, KM-03, AN-03): aturan per peran & cakupan, split dokter–asisten, dasar setelah diskon,
 * sesi paket memakai nilai per sesi, rekap & slip, persetujuan mengunci periode, refund setelah dikunci masuk periode berikutnya.
 */
class KomisiTest extends TestCase
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

    private function tindakan(string $kode): Tindakan
    {
        return Tindakan::where('kode', $kode)->firstOrFail();
    }

    private function aturan(array $data): array
    {
        $this->as('manajer@eklinik.test');

        return $this->postJson('/api/aturan-komisis', $data)->assertCreated()->json();
    }

    /**
     * Kunjungan Poli Estetika oleh dokter demo dengan satu treatment, petugas tambahan opsional, ditutup & dibayar tunai.
     * Mengembalikan id tagihan.
     */
    private function kunjunganLunas(string $kode = 'TRT-001', array $tambahan = [], int $diskon = 0, ?int $paketItemId = null): int
    {
        $this->travel(1)->days();
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $this->pasien->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');

        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => [array_filter(['tindakan_id' => $this->tindakan($kode)->id, 'jumlah' => 1, 'paket_pasien_item_id' => $paketItemId])],
        ])->assertOk();

        if ($tambahan) {
            $kt = KunjunganTindakan::where('kunjungan_id', $id)->value('id');
            $this->putJson("/api/kunjungan-tindakans/{$kt}/catatan", ['petugas_tambahan' => $tambahan])->assertOk();
        }

        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();

        $this->as('kasir@eklinik.test');
        $tagihan = Kunjungan::withoutGlobalScopes()->findOrFail($id)->tagihans()->withoutGlobalScopes()->firstOrFail();
        $grand = $this->getJson("/api/tagihans/{$tagihan->id}")->json('grand_total');
        $this->postJson("/api/tagihans/{$tagihan->id}/bayar", [
            'pembayarans' => [['metode' => 'tunai', 'jumlah' => $grand - $diskon]], 'diskon' => $diskon,
        ])->assertOk();

        return $tagihan->id;
    }

    private function periode(): string
    {
        return now()->format('Y-m');
    }

    public function test_split_dokter_dan_asisten_sesuai_aturan_per_peran(): void
    {
        $this->aturan(['tindakan_id' => $this->tindakan('TRT-001')->id, 'peran' => 'dokter', 'jenis' => 'persen', 'nilai' => 10]);
        $this->aturan(['peran' => 'asisten', 'jenis' => 'nominal', 'nilai' => 50000]);

        $perawat = User::where('email', 'perawat@eklinik.test')->firstOrFail();
        $terapis = User::where('email', 'terapis@eklinik.test')->firstOrFail();
        $this->kunjunganLunas('TRT-001', [
            ['user_id' => $perawat->id, 'peran' => 'asisten'],
            ['user_id' => $terapis->id, 'peran' => 'asisten'],
        ]);

        $dokter = User::where('email', 'dokter@eklinik.test')->firstOrFail();
        $tarif = $this->tindakan('TRT-001')->tarif;

        // Dokter (pelaksana utama) 10% dari tarif; dua asisten berbagi rata nominal 50.000
        $this->assertSame((int) round($tarif * 0.10), (int) Komisi::withoutGlobalScopes()->where('user_id', $dokter->id)->sum('jumlah'));
        $this->assertSame(25000, (int) Komisi::withoutGlobalScopes()->where('user_id', $perawat->id)->sum('jumlah'));
        $this->assertSame(2, Komisi::withoutGlobalScopes()->where('user_id', $perawat->id)->value('dibagi'));

        // Rekap (manajer) & slip sendiri (dokter)
        $this->as('manajer@eklinik.test');
        $this->getJson('/api/komisi/rekap?periode='.$this->periode())->assertOk()
            ->assertJsonPath('status', 'draf')
            ->assertJsonPath('total', (int) round($tarif * 0.10) + 50000)
            ->assertJsonPath('petugas.0.user_id', $dokter->id);

        $this->as('dokter@eklinik.test');
        $this->getJson('/api/komisi/rincian?periode='.$this->periode())->assertOk()
            ->assertJsonPath('total', (int) round($tarif * 0.10))
            ->assertJsonPath('rincian.0.tindakan.kode', 'TRT-001');
        // Slip petugas lain hanya untuk pemegang komisi.kelola
        $this->getJson('/api/komisi/rincian?periode='.$this->periode().'&user_id='.$perawat->id)->assertForbidden();
        $this->getJson('/api/komisi/rekap?periode='.$this->periode())->assertForbidden();
    }

    public function test_aturan_paling_spesifik_dan_dasar_setelah_diskon(): void
    {
        $tindakan = $this->tindakan('TRT-001');
        $this->aturan(['peran' => 'dokter', 'jenis' => 'persen', 'nilai' => 5]);
        $this->aturan(['kategori_id' => $tindakan->kategori_id, 'peran' => 'dokter', 'jenis' => 'persen', 'nilai' => 8]);
        $this->aturan(['tindakan_id' => $tindakan->id, 'peran' => 'dokter', 'jenis' => 'persen', 'nilai' => 10]);
        // Aturan kembar ditolak
        $this->postJson('/api/aturan-komisis', ['tindakan_id' => $tindakan->id, 'peran' => 'dokter', 'jenis' => 'persen', 'nilai' => 12])
            ->assertStatus(422)->assertJsonValidationErrors('peran');

        $tagihanId = $this->kunjunganLunas('TRT-001', diskon: 100000);

        $tagihan = Tagihan::withoutGlobalScopes()->findOrFail($tagihanId);
        $dasar = (int) floor($tindakan->tarif * ($tagihan->total - 100000) / $tagihan->total);
        $baris = Komisi::withoutGlobalScopes()->where('tagihan_id', $tagihanId)->firstOrFail();

        $this->assertSame($dasar, $baris->dasar);
        $this->assertSame(10.0, $baris->nilai_aturan);
        $this->assertSame((int) round($dasar * 0.10), $baris->jumlah);
    }

    public function test_sesi_paket_memakai_nilai_per_sesi(): void
    {
        $this->aturan(['tindakan_id' => $this->tindakan('TRT-011')->id, 'peran' => 'dokter', 'jenis' => 'persen', 'nilai' => 10]);

        $this->as('kasir@eklinik.test');
        $paket = $this->postJson("/api/pasiens/{$this->pasien->id}/pakets", ['paket_id' => Paket::where('kode', 'PKT-LSR6')->value('id')])->assertCreated()->json();
        $grand = $this->getJson("/api/tagihans/{$paket['tagihan_id']}")->json('grand_total');
        $this->postJson("/api/tagihans/{$paket['tagihan_id']}/bayar", ['pembayarans' => [['metode' => 'tunai', 'jumlah' => $grand]]])->assertOk();
        $item = $this->getJson("/api/paket-pasiens/{$paket['id']}")->json('items.0');

        $tagihanId = $this->kunjunganLunas('TRT-011', paketItemId: $item['id']);

        // Penjualan paket (tagihan tanpa kunjungan) tidak menghasilkan komisi tindakan
        $this->assertSame(0, Komisi::withoutGlobalScopes()->where('tagihan_id', $paket['tagihan_id'])->count());
        $baris = Komisi::withoutGlobalScopes()->where('tagihan_id', $tagihanId)->firstOrFail();
        $this->assertSame($item['nilai_per_sesi'], $baris->dasar);
        $this->assertSame((int) round($item['nilai_per_sesi'] * 0.10), $baris->jumlah);
    }

    public function test_setujui_mengunci_periode_dan_refund_masuk_periode_berikutnya(): void
    {
        $this->aturan(['peran' => 'dokter', 'jenis' => 'nominal', 'nilai' => 100000]);
        $tagihanId = $this->kunjunganLunas('TRT-001');
        $periode = $this->periode();

        // Kasir tidak boleh menyetujui
        $this->as('kasir@eklinik.test');
        $this->postJson('/api/komisi/setujui', ['periode' => $periode])->assertForbidden();

        $manajer = $this->as('manajer@eklinik.test');
        $this->postJson('/api/komisi/setujui', ['periode' => $periode, 'catatan' => 'Dibayar bersama gaji'])->assertOk()
            ->assertJsonPath('status', 'disetujui')
            ->assertJsonPath('disetujui_oleh', $manajer->name)
            ->assertJsonPath('total', 100000);
        $this->postJson('/api/komisi/hitung-ulang', ['periode' => $periode])->assertStatus(422)->assertJsonValidationErrors('periode');
        $this->postJson('/api/komisi/setujui', ['periode' => now()->addMonths(2)->format('Y-m')])->assertStatus(422);

        // Refund setelah periode dikunci → baris negatif di periode terbuka berikutnya; periode terkunci tidak berubah
        $this->as('admin@eklinik.test');
        $this->postJson("/api/tagihans/{$tagihanId}/refund", ['alasan_refund' => 'Batal'])->assertOk();

        $this->as('manajer@eklinik.test');
        $this->getJson("/api/komisi/rekap?periode={$periode}")->assertJsonPath('total', 100000);
        $berikut = now()->startOfMonth()->addMonth()->format('Y-m');
        $this->getJson("/api/komisi/rekap?periode={$berikut}")->assertJsonPath('total', -100000)->assertJsonPath('status', 'draf');
    }

    public function test_hitung_ulang_memakai_aturan_terbaru_untuk_periode_terbuka(): void
    {
        $tagihanId = $this->kunjunganLunas('TRT-001');
        $this->assertSame(0, Komisi::withoutGlobalScopes()->count()); // belum ada aturan

        $this->aturan(['peran' => 'dokter', 'jenis' => 'nominal', 'nilai' => 75000]);
        $this->postJson('/api/komisi/hitung-ulang', ['periode' => $this->periode()])->assertOk()->assertJsonPath('total', 75000);

        // Idempoten
        $this->postJson('/api/komisi/hitung-ulang', ['periode' => $this->periode()])->assertOk()->assertJsonPath('total', 75000);
        $this->assertSame(1, Komisi::withoutGlobalScopes()->where('tagihan_id', $tagihanId)->count());
    }

    public function test_petugas_tambahan_harus_petugas_medis_dan_terkunci_setelah_tutup(): void
    {
        $this->travel(1)->days();
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $this->pasien->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');
        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::where('kode', 'Z41.1')->value('id')]],
            'tindakans' => [['tindakan_id' => $this->tindakan('TRT-001')->id, 'jumlah' => 1]],
        ])->assertOk();
        $kt = KunjunganTindakan::where('kunjungan_id', $id)->value('id');

        $kasir = User::where('email', 'kasir@eklinik.test')->firstOrFail();
        $this->putJson("/api/kunjungan-tindakans/{$kt}/catatan", ['petugas_tambahan' => [['user_id' => $kasir->id, 'peran' => 'asisten']]])
            ->assertStatus(422)->assertJsonValidationErrors('petugas_tambahan.0.user_id');

        $perawat = User::where('email', 'perawat@eklinik.test')->firstOrFail();
        $this->putJson("/api/kunjungan-tindakans/{$kt}/catatan", ['petugas_tambahan' => [['user_id' => $perawat->id, 'peran' => 'asisten']]])->assertOk();
        $this->getJson("/api/kunjungan-tindakans/{$kt}/catatan")->assertOk()
            ->assertJsonPath('kunjungan_tindakan.petugas_tambahan.0.user.name', $perawat->name)
            ->assertJsonPath('kunjungan_tindakan.petugas_tambahan.0.peran', 'asisten');

        $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk();
        $this->putJson("/api/kunjungan-tindakans/{$kt}/catatan", ['petugas_tambahan' => []])->assertStatus(422);
    }
}
