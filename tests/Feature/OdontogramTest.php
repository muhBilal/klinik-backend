<?php

namespace Tests\Feature;

use App\Enums\KondisiGigi;
use App\Models\AuditLog;
use App\Models\Icd10;
use App\Models\Kunjungan;
use App\Models\OdontogramKondisi;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\RencanaPerawatanItem;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use LogicException;
use Tests\TestCase;

/**
 * Kedokteran gigi (PRD DG-01, DG-02, DG-07): odontogram FDI per gigi & permukaan, aturan penggantian kondisi, riwayat per
 * kunjungan, tindakan per gigi yang memperbarui odontogram & masuk tagihan, serta rencana perawatan berfase dengan estimasi.
 */
class OdontogramTest extends TestCase
{
    use RefreshDatabase;

    private Pasien $pasien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        // Fokus test ini odontogram; kewajiban consent tindakan diuji di RmeEstetikaTest.
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

    /** Kunjungan Poli Gigi yang sudah dipanggil drg. (login sebagai drg.). Tiap kunjungan di hari berikutnya. */
    private function kunjunganGigi(?Pasien $pasien = null): int
    {
        $this->travel(1)->days();
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => ($pasien ?? $this->pasien)->id,
            'poli_id' => Poli::where('kode', 'GIGI')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');

        $this->as('dokter.gigi@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();

        return $id;
    }

    private function catat(int $kunjunganId, int $gigi, string $kondisi, array $permukaan = []): TestResponse
    {
        return $this->postJson("/api/kunjungans/{$kunjunganId}/odontogram", ['gigi' => $gigi, 'kondisi' => $kondisi, 'permukaan' => $permukaan]);
    }

    /** Kondisi aktif pasien, terurut: ["16O:car", "26:mis", ...] */
    private function aktif(?Pasien $pasien = null): array
    {
        return OdontogramKondisi::where('pasien_id', ($pasien ?? $this->pasien)->id)->aktif()->get()
            ->map(fn ($k) => "{$k->gigi}{$k->permukaan}:{$k->kondisi->value}")->sort()->values()->all();
    }

    private function tutup(int $kunjunganId): TestResponse
    {
        $this->putJson("/api/kunjungans/{$kunjunganId}/pemeriksaan", ['diagnosas' => [['icd10_id' => Icd10::where('kode', 'K02.1')->value('id')]]])->assertOk();

        return $this->postJson("/api/kunjungans/{$kunjunganId}/selesai");
    }

    public function test_referensi_poli_spesialisasi_dan_katalog_gigi(): void
    {
        $this->as('dokter.gigi@eklinik.test');
        $referensi = $this->getJson('/api/odontogram/referensi')->assertOk()->json();
        $this->assertSame(count(KondisiGigi::cases()), count($referensi['kondisi']));
        $this->assertSame(['permukaan', 'permukaan'], [collect($referensi['kondisi'])->firstWhere('kode', 'car')['cakupan'], collect($referensi['kondisi'])->firstWhere('kode', 'cof')['cakupan']]);
        $this->assertSame('gigi', collect($referensi['kondisi'])->firstWhere('kode', 'mis')['cakupan']);

        $gigi = collect($this->getJson('/api/polis?aktif=1')->json())->firstWhere('kode', 'GIGI');
        $this->assertSame('gigi', $gigi['spesialisasi']);

        $tambal = collect($this->getJson('/api/tindakans?aktif=1&q=Tambal gigi komposit')->json('data'))->first();
        $this->assertTrue($tambal['per_gigi']);
        $this->assertSame('cof', $tambal['kondisi_gigi_hasil']);

        $this->as('admin@eklinik.test');
        $poli = Poli::where('kode', 'ESTETIKA')->firstOrFail();
        $this->putJson("/api/polis/{$poli->id}", ['kode' => 'ESTETIKA', 'nama' => $poli->nama, 'spesialisasi' => 'lain-lain'])
            ->assertUnprocessable()->assertJsonValidationErrors('spesialisasi');
        $this->putJson("/api/polis/{$poli->id}", ['kode' => 'ESTETIKA', 'nama' => $poli->nama, 'spesialisasi' => null])
            ->assertOk()->assertJsonPath('spesialisasi', 'umum');

        // Kondisi hasil → otomatis per gigi
        $t = Tindakan::where('kode', 'TND-001')->firstOrFail();
        $this->putJson("/api/tindakans/{$t->id}", ['kode' => $t->kode, 'nama' => $t->nama, 'durasi_menit' => 10, 'tarif' => 1000, 'kondisi_gigi_hasil' => 'amf'])
            ->assertOk()->assertJsonPath('per_gigi', true)->assertJsonPath('kondisi_gigi_hasil', 'amf');
        $this->putJson("/api/tindakans/{$t->id}", ['kode' => $t->kode, 'nama' => $t->nama, 'durasi_menit' => 10, 'tarif' => 1000, 'kondisi_gigi_hasil' => 'xyz'])
            ->assertUnprocessable()->assertJsonValidationErrors('kondisi_gigi_hasil');
    }

    public function test_validasi_dan_hak_akses_odontogram(): void
    {
        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $this->pasien->id,
            'poli_id' => Poli::where('kode', 'GIGI')->value('id'), 'penjamin' => 'umum'])->assertCreated()->json('id');

        // Belum dipanggil → belum bisa dicatat
        $this->as('dokter.gigi@eklinik.test');
        $this->catat($id, 16, 'car', ['O'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();

        foreach ([19, 59, 10, 90, 100, 9] as $salah) {
            $this->catat($id, $salah, 'mis')->assertUnprocessable()->assertJsonValidationErrors('gigi');
        }
        $this->catat($id, 16, 'car')->assertUnprocessable()->assertJsonValidationErrors('permukaan');
        $this->catat($id, 16, 'mis', ['O'])->assertUnprocessable()->assertJsonValidationErrors('permukaan');
        $this->catat($id, 16, 'car', ['X'])->assertUnprocessable()->assertJsonValidationErrors('permukaan.0');
        $this->catat($id, 16, 'zzz')->assertUnprocessable()->assertJsonValidationErrors('kondisi');

        // Gigi sulung & permukaan dinormalkan (satu baris per permukaan)
        $data = $this->catat($id, 55, 'car', ['D', 'O', 'O'])->assertCreated()->json();
        $this->assertSame(['55D:car', '55O:car'], $this->aktif());
        $this->assertSame(2, count($data['perubahan']['dicatat']));
        $this->assertTrue($data['bisa_diubah']);

        // Perawat (rme.tindakan) boleh mencatat; kasir tidak; pendaftaran tanpa rme.lihat tidak bisa membaca
        $this->as('perawat@eklinik.test');
        $this->catat($id, 11, 'cfr')->assertCreated();
        $this->as('kasir@eklinik.test');
        $this->catat($id, 12, 'cfr')->assertForbidden();
        $this->getJson("/api/pasiens/{$this->pasien->id}/odontogram")->assertForbidden();

        $this->as('dokter@eklinik.test');
        $this->getJson("/api/pasiens/{$this->pasien->id}/odontogram")->assertOk()->assertJsonCount(3, 'kondisis');
        $this->assertTrue(AuditLog::where('aksi', 'lihat')->where('tipe', 'odontogram')->where('pasien_id', $this->pasien->id)->exists());
    }

    public function test_kondisi_saling_menggantikan_dan_koreksi_di_kunjungan_yang_sama(): void
    {
        $k1 = $this->kunjunganGigi();
        $this->catat($k1, 16, 'car', ['O', 'M'])->assertCreated();
        $this->catat($k1, 36, 'rct')->assertCreated();
        $this->catat($k1, 36, 'fmc')->assertCreated();
        $this->catat($k1, 21, 'att')->assertCreated();
        $this->catat($k1, 21, 'dia')->assertCreated(); // kelompok "lain" tidak eksklusif
        $this->catat($k1, 16, 'car', ['O'])->assertCreated(); // sama persis → tidak dicatat ulang
        $this->assertSame(2, OdontogramKondisi::where('gigi', 16)->count());

        // Koreksi di kunjungan yang sama: komposit menggantikan karies 16-O yang baru dicatat → karies dihapus (bukan diakhiri)
        $this->catat($k1, 16, 'cof', ['O'])->assertCreated();
        $this->assertSame(['16M:car', '16O:cof', '21:att', '21:dia', '36:fmc', '36:rct'], $this->aktif());
        $this->assertSame(0, OdontogramKondisi::where('gigi', 16)->where('permukaan', 'O')->where('kondisi', 'car')->count());

        $this->assertSame(200, $this->tutup($k1)->status());

        // Kunjungan berikutnya: kondisi lama diakhiri (tidak dihapus), tercatat penggantinya
        $k2 = $this->kunjunganGigi();
        $data = $this->catat($k2, 16, 'amf', ['M'])->assertCreated()->json();
        $karies = OdontogramKondisi::where('gigi', 16)->where('permukaan', 'M')->where('kondisi', 'car')->firstOrFail();
        $amalgam = OdontogramKondisi::where('gigi', 16)->where('permukaan', 'M')->where('kondisi', 'amf')->firstOrFail();
        $this->assertSame([$k2, $amalgam->id], [$karies->berakhir_kunjungan_id, $karies->berakhir_karena_id]);
        $this->assertSame([$karies->id], array_column($data['perubahan']['diakhiri'], 'id'));

        // Mahkota porselen menggantikan mahkota logam (kelompok sama) — perawatan saluran akar tetap
        $this->catat($k2, 36, 'poc')->assertCreated();
        $this->assertSame(['poc', 'rct'], OdontogramKondisi::where('gigi', 36)->aktif()->orderBy('kondisi')->pluck('kondisi')->map->value->all());

        // Gigi hilang mengakhiri tambalan, mahkota, pulpa & kondisi lain; hanya pengganti gigi yang boleh dicatat sesudahnya
        $this->catat($k2, 16, 'mis')->assertCreated();
        $this->assertSame(['mis'], OdontogramKondisi::where('gigi', 16)->aktif()->pluck('kondisi')->map->value->all());
        $this->catat($k2, 16, 'car', ['D'])->assertUnprocessable()->assertJsonValidationErrors('gigi');
        $this->catat($k2, 16, 'pon')->assertCreated();

        // Hapus "hilang" → tambalan 16 berlaku lagi: komposit O (kunjungan 1) dan amalgam M (tergeser di kunjungan ini)
        $hilang = OdontogramKondisi::where('gigi', 16)->where('kondisi', 'mis')->firstOrFail();
        $this->deleteJson("/api/kunjungans/{$k2}/odontogram/{$hilang->id}")->assertOk();
        $this->assertSame(['amf', 'cof', 'pon'], OdontogramKondisi::where('gigi', 16)->aktif()->orderBy('kondisi')->pluck('kondisi')->map->value->all());

        // Kondisi dari kunjungan sebelumnya tidak dihapus — diakhiri, lalu bisa dipulihkan
        $dia = OdontogramKondisi::where('gigi', 21)->where('kondisi', 'dia')->firstOrFail();
        $this->deleteJson("/api/kunjungans/{$k2}/odontogram/{$dia->id}")->assertUnprocessable();
        $this->postJson("/api/kunjungans/{$k2}/odontogram/{$dia->id}/akhiri")->assertOk();
        $this->assertNotNull($dia->refresh()->berakhir_kunjungan_id);
        $this->postJson("/api/kunjungans/{$k2}/odontogram/{$dia->id}/pulihkan")->assertOk();
        $this->assertNull($dia->refresh()->berakhir_kunjungan_id);

        // Pengakhiran karena diganti tidak dipulihkan langsung; pemulihan yang bentrok ditolak
        $this->postJson("/api/kunjungans/{$k2}/odontogram/{$karies->id}/pulihkan")->assertUnprocessable();
        $cof = OdontogramKondisi::where('gigi', 16)->where('kondisi', 'cof')->firstOrFail();
        $this->postJson("/api/kunjungans/{$k2}/odontogram/{$cof->id}/akhiri")->assertOk();
        $this->catat($k2, 16, 'car', ['O'])->assertCreated();
        $this->postJson("/api/kunjungans/{$k2}/odontogram/{$cof->id}/pulihkan")->assertUnprocessable()->assertJsonValidationErrors('kondisi');

        // Status pada kunjungan 1 tetap bisa disusun ulang
        $pada1 = $this->getJson("/api/pasiens/{$this->pasien->id}/odontogram?kunjungan_id={$k1}")->assertOk()->json();
        $this->assertSame(['16M:car', '16O:cof', '21:att', '21:dia', '36:fmc', '36:rct'],
            collect($pada1['kondisis'])->map(fn ($k) => "{$k['gigi']}{$k['permukaan']}:{$k['kondisi']}")->sort()->values()->all());
        $this->assertFalse($pada1['bisa_diubah']);
        $this->assertSame([$k2, $k1], array_column($pada1['kunjungans'], 'id'));
    }

    public function test_odontogram_kunjungan_tertutup_terkunci_dan_masuk_hash(): void
    {
        $k1 = $this->kunjunganGigi();
        $this->catat($k1, 46, 'car', ['O'])->assertCreated();
        $this->tutup($k1)->assertOk();

        $this->catat($k1, 46, 'amf', ['O'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $kondisi = OdontogramKondisi::firstOrFail();
        $this->deleteJson("/api/kunjungans/{$k1}/odontogram/{$kondisi->id}")->assertUnprocessable();

        // Jaring pengaman model
        try {
            $kondisi->update(['keterangan' => 'diubah']);
            $this->fail('Kondisi kunjungan tertutup seharusnya terkunci.');
        } catch (LogicException) {
        }
        try {
            $kondisi->delete();
            $this->fail('Kondisi kunjungan tertutup seharusnya tidak bisa dihapus.');
        } catch (LogicException) {
        }

        $this->getJson("/api/kunjungans/{$k1}/verifikasi")->assertOk()->assertJsonPath('valid', true);

        // Kunjungan berikutnya mengakhiri kondisi itu: isi RME kunjungan 1 tetap utuh
        $k2 = $this->kunjunganGigi();
        $this->catat($k2, 46, 'cof', ['O'])->assertCreated();
        $this->getJson("/api/kunjungans/{$k1}/verifikasi")->assertOk()->assertJsonPath('valid', true);

        // Perubahan langsung di database terdeteksi
        OdontogramKondisi::whereKey($kondisi->id)->toBase()->update(['kondisi' => 'amf']);
        $this->getJson("/api/kunjungans/{$k1}/verifikasi")->assertOk()->assertJsonPath('valid', false);
    }

    public function test_tindakan_per_gigi_memperbarui_odontogram_dan_tagihan(): void
    {
        $k1 = $this->kunjunganGigi();
        $this->catat($k1, 16, 'car', ['M', 'O'])->assertCreated();
        $url = "/api/kunjungans/{$k1}/pemeriksaan";
        $tambal = $this->tindakan('TND-101');

        // Wajib nomor gigi & permukaan untuk tindakan per gigi dengan kondisi per permukaan
        $this->putJson($url, ['tindakans' => [['tindakan_id' => $tambal]]])->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.gigi');
        $this->putJson($url, ['tindakans' => [['tindakan_id' => $tambal, 'gigi' => 16]]])->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.permukaan');
        $this->putJson($url, ['tindakans' => [['tindakan_id' => $tambal, 'gigi' => 16, 'permukaan' => 'MM']]])->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.permukaan');

        // Tambal komposit 16 OM (dinormalkan jadi MO) & 26 O, cabut 38, scaling (tidak per gigi)
        $res = $this->putJson($url, ['tindakans' => [
            ['tindakan_id' => $tambal, 'gigi' => 16, 'permukaan' => 'om'],
            ['tindakan_id' => $tambal, 'gigi' => 26, 'permukaan' => 'O'],
            ['tindakan_id' => $this->tindakan('TND-102'), 'gigi' => 38],
            ['tindakan_id' => $this->tindakan('TND-103')],
        ]])->assertOk();
        $this->assertSame([16, 'MO'], [$res->json('tindakans.0.gigi'), $res->json('tindakans.0.permukaan')]);
        $this->assertSame(['16M:cof', '16O:cof', '26O:cof', '38:mis'], $this->aktif());
        // Karies yang dicatat manual di kunjungan ini diganti (dihapus) oleh hasil tindakan
        $this->assertSame(0, OdontogramKondisi::where('kondisi', 'car')->count());
        $this->assertCount(4, $res->json('odontogram_dicatat'));

        // Simpan ulang tanpa perubahan: turunan tidak dibuat ulang
        $ids = OdontogramKondisi::orderBy('id')->pluck('id')->all();
        $tindakans = collect($res->json('tindakans'))->map(fn ($t) => ['id' => $t['id'], 'tindakan_id' => $t['tindakan_id'], 'gigi' => $t['gigi'], 'permukaan' => $t['permukaan']])->all();
        $this->putJson($url, ['tindakans' => $tindakans])->assertOk();
        $this->assertSame($ids, OdontogramKondisi::orderBy('id')->pluck('id')->all());

        // Hasil tindakan tidak bisa ditimpa/dihapus manual
        $this->catat($k1, 16, 'amf', ['O'])->assertUnprocessable()->assertJsonValidationErrors('kondisi');
        $turunan = OdontogramKondisi::where('gigi', 38)->firstOrFail();
        $this->deleteJson("/api/kunjungans/{$k1}/odontogram/{$turunan->id}")->assertUnprocessable();

        // Ubah permukaan 16 → MOD; hapus tambal 26 → kondisinya ikut terhapus
        $tindakans[0]['permukaan'] = 'MOD';
        unset($tindakans[1]);
        $this->putJson($url, ['tindakans' => array_values($tindakans)])->assertOk();
        $this->assertSame(['16D:cof', '16M:cof', '16O:cof', '38:mis'], $this->aktif());

        // Tagihan per gigi
        $this->tutup($k1)->assertOk();
        $deskripsi = Kunjungan::find($k1)->tagihan->items()->where('kategori', 'tindakan')->pluck('deskripsi')->all();
        $this->assertContains('Tambal gigi komposit — gigi 16 (MOD)', $deskripsi);
        $this->assertContains('Cabut gigi permanen — gigi 38', $deskripsi);
        $this->assertContains('Scaling', $deskripsi);
    }

    public function test_tindakan_pada_gigi_hilang_ditolak(): void
    {
        $k1 = $this->kunjunganGigi();
        $this->catat($k1, 24, 'mis')->assertCreated();

        $this->putJson("/api/kunjungans/{$k1}/pemeriksaan", ['tindakans' => [['tindakan_id' => $this->tindakan('TND-101'), 'gigi' => 24, 'permukaan' => 'O']]])
            ->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.gigi');
        $this->assertSame(0, Kunjungan::find($k1)->tindakans()->count());
    }

    public function test_rencana_perawatan_berfase_dikerjakan_sampai_selesai(): void
    {
        $k1 = $this->kunjunganGigi();
        $url = "/api/pasiens/{$this->pasien->id}/rencana-perawatans";
        $items = [
            ['fase' => 1, 'tindakan_id' => $this->tindakan('TND-103')],
            ['fase' => 2, 'tindakan_id' => $this->tindakan('TND-101'), 'gigi' => 16, 'permukaan' => 'MO'],
            ['fase' => 2, 'tindakan_id' => $this->tindakan('TND-105'), 'gigi' => 36],
            ['fase' => 3, 'tindakan_id' => $this->tindakan('TND-106'), 'gigi' => 36, 'keterangan' => 'Setelah PSA selesai'],
        ];

        // Hanya dokter; tindakan per gigi wajib nomor gigi
        $this->as('perawat@eklinik.test');
        $this->postJson($url, ['judul' => 'Rencana', 'items' => $items])->assertForbidden();
        $this->as('dokter.gigi@eklinik.test');
        $this->postJson($url, ['judul' => 'Rencana', 'items' => [['fase' => 1, 'tindakan_id' => $this->tindakan('TND-101')]]])
            ->assertUnprocessable()->assertJsonValidationErrors('items.0.gigi');

        $rencana = $this->postJson($url, ['judul' => 'Perawatan menyeluruh', 'kunjungan_id' => $k1, 'items' => $items])
            ->assertCreated()->assertJsonPath('status', 'draf')->assertJsonCount(4, 'items')->json();
        $this->assertSame(250000 + 200000 + 600000 + 2500000, $rencana['estimasi_total']);
        $this->assertSame([[1, 250000], [2, 800000], [3, 2500000]], array_map(fn ($f) => [$f['fase'], $f['total']], $rencana['estimasi_per_fase']));
        $this->assertSame(User::where('email', 'dokter.gigi@eklinik.test')->value('id'), $rencana['dokter_id']);

        // Disetujui → tidak bisa diubah tanpa revisi
        $rid = $rencana['id'];
        $this->postJson("/api/rencana-perawatans/{$rid}/setujui", ['penyetuju_nama' => 'Ibu pasien'])->assertOk()
            ->assertJsonPath('status', 'disetujui')->assertJsonPath('penyetuju_nama', 'Ibu pasien');
        $this->putJson("/api/rencana-perawatans/{$rid}", ['judul' => 'X', 'items' => $items])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->postJson("/api/rencana-perawatans/{$rid}/revisi")->assertOk()->assertJsonPath('status', 'draf')->assertJsonPath('disetujui_at', null);

        // Revisi: hapus item crown, ubah permukaan tambal → upsert per id
        $baru = collect($rencana['items'])->take(3)->map(fn ($i) => ['id' => $i['id'], 'fase' => $i['fase'], 'tindakan_id' => $i['tindakan_id'], 'gigi' => $i['gigi'], 'permukaan' => $i['permukaan']])->all();
        $baru[1]['permukaan'] = 'MOD';
        $revisi = $this->putJson("/api/rencana-perawatans/{$rid}", ['judul' => 'Perawatan menyeluruh (rev)', 'items' => $baru])->assertOk()->json();
        $this->assertSame(1050000, $revisi['estimasi_total']);
        $this->assertSame($rencana['items'][1]['id'], $revisi['items'][1]['id']);
        $this->postJson("/api/rencana-perawatans/{$rid}/setujui")->assertOk();

        // Kerjakan item tambal di kunjungan 1: gigi & permukaan mengikuti rencana
        $itemTambal = $revisi['items'][1]['id'];
        $pem = "/api/kunjungans/{$k1}/pemeriksaan";
        $res = $this->putJson($pem, ['tindakans' => [['tindakan_id' => $this->tindakan('TND-101'), 'rencana_item_id' => $itemTambal]]])->assertOk();
        $this->assertSame([16, 'MOD', $itemTambal], [$res->json('tindakans.0.gigi'), $res->json('tindakans.0.permukaan'), $res->json('tindakans.0.rencana_item_id')]);

        // Item sedang dikerjakan tidak bisa dipakai kunjungan lain / dihapus dari rencana
        $pasienLain = Pasien::whereKeyNot($this->pasien->id)->firstOrFail();
        $kLain = $this->kunjunganGigi($pasienLain);
        $this->putJson("/api/kunjungans/{$kLain}/pemeriksaan", ['tindakans' => [['tindakan_id' => $this->tindakan('TND-101'), 'rencana_item_id' => $itemTambal]]])
            ->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.rencana_item_id');
        $this->postJson("/api/rencana-perawatans/{$rid}/batal", ['alasan' => 'x'])->assertUnprocessable();

        $this->tutup($k1)->assertOk();
        $this->assertSame('selesai', RencanaPerawatanItem::find($itemTambal)->status->value);
        $this->getJson($url)->assertOk()->assertJsonPath('0.status', 'disetujui')->assertJsonPath('0.estimasi_selesai', 200000);

        // Item selesai tidak bisa dihapus lewat revisi
        $this->postJson("/api/rencana-perawatans/{$rid}/revisi")->assertOk();
        $this->putJson("/api/rencana-perawatans/{$rid}", ['judul' => 'R', 'items' => [$baru[0]]])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->postJson("/api/rencana-perawatans/{$rid}/setujui")->assertOk();

        // Sisa item dikerjakan di kunjungan 2 → rencana selesai otomatis
        $k2 = $this->kunjunganGigi();
        $sisa = collect($revisi['items'])->reject(fn ($i) => $i['id'] === $itemTambal)->values();
        $this->putJson("/api/kunjungans/{$k2}/pemeriksaan", ['tindakans' => $sisa->map(fn ($i) => ['tindakan_id' => $i['tindakan_id'], 'rencana_item_id' => $i['id']])->all()])->assertOk();
        $this->tutup($k2)->assertOk();
        $this->getJson("/api/rencana-perawatans/{$rid}")->assertOk()->assertJsonPath('status', 'selesai')
            ->assertJsonPath('items.0.pelaksanaan.kunjungan.id', $k2);
        $this->assertSame('rct', OdontogramKondisi::where('gigi', 36)->aktif()->value('kondisi')->value);

        // Rencana selesai tidak bisa dikerjakan / dibatalkan
        $this->postJson("/api/rencana-perawatans/{$rid}/batal", ['alasan' => 'x'])->assertUnprocessable();
    }

    public function test_rencana_dibatalkan_dan_milik_pasien(): void
    {
        $this->as('dokter.gigi@eklinik.test');
        $rencana = $this->postJson("/api/pasiens/{$this->pasien->id}/rencana-perawatans", ['judul' => 'Ortho', 'items' => [
            ['fase' => 1, 'tindakan_id' => $this->tindakan('TND-103')],
        ]])->assertCreated()->json();

        // Item rencana pasien lain tidak bisa dikerjakan
        $pasienLain = Pasien::whereKeyNot($this->pasien->id)->firstOrFail();
        $k = $this->kunjunganGigi($pasienLain);
        $this->putJson("/api/kunjungans/{$k}/pemeriksaan", ['tindakans' => [['tindakan_id' => $this->tindakan('TND-103'), 'rencana_item_id' => $rencana['items'][0]['id']]]])
            ->assertUnprocessable()->assertJsonValidationErrors('tindakans.0.rencana_item_id');

        $this->postJson("/api/rencana-perawatans/{$rencana['id']}/batal", [])->assertUnprocessable()->assertJsonValidationErrors('alasan');
        $this->postJson("/api/rencana-perawatans/{$rencana['id']}/batal", ['alasan' => 'Pasien pindah kota'])->assertOk()
            ->assertJsonPath('status', 'dibatalkan')->assertJsonPath('items.0.status', 'batal');
        $this->getJson("/api/pasiens/{$this->pasien->id}/rencana-perawatans?aktif=1")->assertOk()->assertJsonCount(0);

        // Detail pasien menandai adanya data gigi
        $this->getJson("/api/pasiens/{$this->pasien->id}")->assertOk()->assertJsonPath('data_gigi', true);
        $this->getJson("/api/pasiens/{$pasienLain->id}")->assertOk()->assertJsonPath('data_gigi', false);
    }
}
