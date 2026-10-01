<?php

namespace Tests\Feature;

use App\Models\Icd10;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\PersetujuanData;
use App\Models\Poli;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuatBerkasUji;
use Tests\TestCase;

/**
 * Data klinis pasien terstruktur (PRD PS-03) & persetujuan data pribadi UU PDP (PS-04): akses terpisah dari identitas,
 * alergi bertaut obat, hamil/menyusui, persetujuan pemrosesan & opt-in marketing terpisah, pencabutan, dan syarat pendaftaran.
 */
class DataKlinisPdpTest extends TestCase
{
    use BuatBerkasUji, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function as(string $email): User
    {
        $user = User::where('email', $email)->firstOrFail();
        Sanctum::actingAs($user);

        return $user;
    }

    private function pasien(string $jk): Pasien
    {
        return Pasien::where('jenis_kelamin', $jk)->doesntHave('klinis')->doesntHave('alergis')->orderBy('id')->firstOrFail();
    }

    public function test_data_klinis_terpisah_dari_identitas_dan_hanya_untuk_tenaga_klinis(): void
    {
        $pasien = $this->pasien('P');
        $amox = Obat::where('kode', 'OBT-002')->firstOrFail();
        $url = "/api/pasiens/{$pasien->id}/klinis";
        $payload = [
            'fitzpatrick' => 'IV', 'status_kehamilan' => 'hamil', 'riwayat_obat' => 'Isotretinoin, berhenti Agustus 2026',
            'riwayat_penyakit' => 'Riwayat keloid',
            'alergis' => [
                ['kategori' => 'obat', 'zat' => 'Amoxicillin', 'obat_id' => $amox->id, 'reaksi' => 'Ruam', 'keparahan' => 'berat'],
                ['kategori' => 'makanan', 'zat' => 'Udang', 'obat_id' => $amox->id],
            ],
        ];

        // Kasir & pendaftaran (pasien.lihat / pasien.kelola) tidak membaca maupun mengubah data klinis
        $this->as('kasir@eklinik.test');
        $this->getJson($url)->assertForbidden();
        $this->as('pendaftaran@eklinik.test');
        $this->putJson($url, $payload)->assertForbidden();

        // Perawat (anamnesis) menyimpan; tautan obat hanya untuk alergi obat; tanggal status kehamilan tercatat
        $this->as('perawat@eklinik.test');
        $res = $this->putJson($url, $payload)->assertOk()
            ->assertJsonPath('klinis.fitzpatrick', 'IV')
            ->assertJsonPath('klinis.status_kehamilan', 'hamil')
            ->assertJsonPath('klinis.status_kehamilan_at', today()->toDateString())
            ->assertJsonPath('klinis.pembaru.name', 'Ns. Rina Perawat')
            ->assertJsonPath('alergis.0.obat.kode', 'OBT-002')
            ->assertJsonPath('alergis.1.obat_id', null);
        $udang = $res->json('alergis.1.id');

        // Validasi: hamil untuk laki-laki, alergi dobel, id alergi milik pasien lain, kategori tak dikenal
        $lelaki = $this->pasien('L');
        $this->putJson("/api/pasiens/{$lelaki->id}/klinis", ['status_kehamilan' => 'hamil'])
            ->assertUnprocessable()->assertJsonValidationErrors('status_kehamilan');
        $this->putJson($url, ['alergis' => [['kategori' => 'obat', 'zat' => 'Sulfa'], ['kategori' => 'obat', 'zat' => ' sulfa ']]])
            ->assertUnprocessable()->assertJsonValidationErrors('alergis.1.zat');
        $this->putJson("/api/pasiens/{$lelaki->id}/klinis", ['alergis' => [['id' => $udang, 'kategori' => 'makanan', 'zat' => 'Udang']]])
            ->assertUnprocessable()->assertJsonValidationErrors('alergis.0.id');
        $this->putJson($url, ['alergis' => [['kategori' => 'kosmetik', 'zat' => 'X']]])
            ->assertUnprocessable()->assertJsonValidationErrors('alergis.0.kategori');

        // Replace-all: amoxicillin dihapus, udang diubah (id sama); profil tanpa kunci tidak berubah
        $this->putJson($url, ['alergis' => [['id' => $udang, 'kategori' => 'makanan', 'zat' => 'Udang', 'keparahan' => 'sedang']]])
            ->assertOk()->assertJsonCount(1, 'alergis')->assertJsonPath('alergis.0.id', $udang)->assertJsonPath('alergis.0.keparahan', 'sedang')
            ->assertJsonPath('klinis.fitzpatrick', 'IV');

        // Status kehamilan berubah → tanggal diperbarui; konfirmasi ulang juga memperbarui tanggal
        DB::table('pasien_klinis')->where('pasien_id', $pasien->id)->update(['status_kehamilan_at' => today()->subMonths(5)]);
        $this->putJson($url, ['konfirmasi_kehamilan' => true])->assertOk()->assertJsonPath('klinis.status_kehamilan_at', today()->toDateString());
        $this->putJson($url, ['status_kehamilan' => 'tidak'])->assertOk()->assertJsonPath('klinis.status_kehamilan', 'tidak');

        // Perubahan & pembacaan tercatat di jejak akses pasien
        $this->assertTrue(DB::table('audit_logs')->where('pasien_id', $pasien->id)->where('tipe', 'pasien_alergi')->where('aksi', 'hapus')->exists());
        $this->assertTrue(DB::table('audit_logs')->where('pasien_id', $pasien->id)->where('tipe', 'pasien_klinis')->where('aksi', 'ubah')->exists());
        $this->as('dokter@eklinik.test');
        $this->getJson($url)->assertOk()->assertJsonPath('klinis.riwayat_penyakit', 'Riwayat keloid');
        $this->assertTrue(DB::table('audit_logs')->where('pasien_id', $pasien->id)->where('tipe', 'pasien_klinis')->where('aksi', 'lihat')->exists());

        // Identitas pasien (kasir) tanpa data klinis
        $this->as('kasir@eklinik.test');
        $this->getJson("/api/pasiens/{$pasien->id}")->assertOk()->assertJsonMissingPath('alergi')->assertJsonMissingPath('klinis')->assertJsonMissingPath('alergis');
    }

    public function test_peringatan_klinis_di_pemeriksaan_dan_farmasi(): void
    {
        $pasien = Pasien::whereHas('alergis', fn ($q) => $q->whereNotNull('obat_id'))->firstOrFail();

        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $pasien->id, 'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'),
            'penjamin' => 'umum'])->assertCreated()->json('id');
        // Kasir melihat kunjungan tanpa data klinis
        $this->as('kasir@eklinik.test');
        $this->getJson("/api/kunjungans/{$id}")->assertOk()->assertJsonMissingPath('pasien.alergis');

        $this->as('dokter@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk()
            ->assertJsonPath('pasien.alergis.0.zat', 'Amoxicillin')
            ->assertJsonPath('pasien.klinis.fitzpatrick', 'III');
        $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::value('id')]],
            'resep' => [['obat_id' => Obat::where('kode', 'OBT-001')->value('id'), 'jumlah' => 10, 'aturan_pakai' => '3x1']],
        ])->assertOk();
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);
        $resepId = $this->postJson("/api/kunjungans/{$id}/selesai")->assertOk()->json('resep.id');

        // Apoteker (tanpa rme.lihat) tetap melihat alergi & status hamil/menyusui pada resep
        $this->as('apoteker@eklinik.test');
        $this->getJson("/api/reseps/{$resepId}")->assertOk()
            ->assertJsonPath('kunjungan.pasien.alergis.0.obat_id', Obat::where('kode', 'OBT-002')->value('id'))
            ->assertJsonStructure(['kunjungan' => ['pasien' => ['klinis' => ['status_kehamilan']]]]);
    }

    public function test_persetujuan_pemrosesan_dan_marketing_terpisah(): void
    {
        $pasien = Pasien::orderBy('id')->firstOrFail();
        $url = "/api/pasiens/{$pasien->id}/persetujuan-data";
        $form = ['setuju_pemrosesan' => true, 'marketing' => true, 'kanal' => ['whatsapp', 'email'],
            'penandatangan_nama' => $pasien->nama, 'hubungan' => 'pasien', 'ttd' => $this->ttd()];

        // Kasir boleh melihat status (pasien.lihat), tidak boleh mengambil tanda tangan
        $this->as('kasir@eklinik.test');
        $this->getJson($url)->assertOk()->assertJsonPath('pemrosesan', null)->assertJsonCount(4, 'kanal');
        $this->postJson($url, $form)->assertForbidden();

        $this->as('pendaftaran@eklinik.test');
        $this->getJson("{$url}/pratinjau?kanal[]=whatsapp")->assertOk()
            ->assertJsonPath('marketing', fn ($isi) => str_contains($isi, 'melalui: WhatsApp') && str_contains($isi, $pasien->nama));
        $this->postJson($url, [...$form, 'setuju_pemrosesan' => false])->assertUnprocessable()->assertJsonValidationErrors('setuju_pemrosesan');
        $this->postJson($url, [...$form, 'kanal' => []])->assertUnprocessable()->assertJsonValidationErrors('kanal');
        $this->postJson($url, [...$form, 'ttd' => 'data:image/png;base64,'.base64_encode('bukan png')])->assertUnprocessable()->assertJsonValidationErrors('ttd');

        // Satu formulir → dua persetujuan terpisah; tanda tangan terenkripsi, naskah di-snapshot
        $this->postJson($url, $form)->assertCreated()->assertJsonCount(2)
            ->assertJsonPath('0.jenis', 'pemrosesan')->assertJsonPath('1.jenis', 'marketing')->assertJsonPath('1.kanal', ['whatsapp', 'email'])
            ->assertJsonMissingPath('0.ttd');
        $baris = DB::table('persetujuan_datas')->where('pasien_id', $pasien->id)->where('jenis', 'pemrosesan')->first();
        $this->assertStringNotContainsString('data:image/png', $baris->ttd);
        $this->assertStringContainsString('Pelindungan Data Pribadi', $baris->isi);
        $this->getJson('/api/pasiens?persetujuan=marketing')->assertOk()->assertJsonPath('data.0.id', $pasien->id)
            ->assertJsonPath('data.0.pdp_pemrosesan', true)->assertJsonPath('data.0.pdp_marketing', true);
        $this->getJson('/api/pasiens?persetujuan=belum&per_page=100')->assertOk()
            ->assertJsonMissing(['id' => $pasien->id, 'no_rm' => $pasien->no_rm]);

        // Formulir baru tanpa marketing: pemrosesan diganti, opt-in lama dicabut
        $this->postJson($url, [...$form, 'marketing' => false, 'kanal' => null])->assertCreated()->assertJsonCount(1);
        $status = $this->getJson($url)->assertOk()->assertJsonPath('marketing', null)->json();
        $this->assertSame(['dicabut', 'diganti'], collect($status['riwayat'])->whereIn('jenis', ['marketing', 'pemrosesan'])
            ->where('status', '!=', 'berlaku')->pluck('status')->sort()->values()->all());

        // Lihat dokumen (tercatat audit, checksum valid)
        $uuid = $status['pemrosesan']['uuid'];
        $this->getJson("/api/persetujuan-datas/{$uuid}")->assertOk()->assertJsonPath('checksum_valid', true)
            ->assertJsonPath('ttd', fn ($ttd) => str_starts_with($ttd, 'data:image/png;base64,'));
        $this->assertTrue(DB::table('audit_logs')->where('pasien_id', $pasien->id)->where('tipe', 'persetujuan_data')->where('aksi', 'lihat')->exists());

        // Opt-in lagi lalu cabut pemrosesan → marketing ikut dicabut
        $this->postJson($url, $form)->assertCreated();
        $this->postJson("/api/persetujuan-datas/{$uuid}/cabut", ['alasan' => 'x'])->assertUnprocessable(); // sudah diganti
        $aktif = $this->getJson($url)->json('pemrosesan.uuid');
        $this->postJson("/api/persetujuan-datas/{$aktif}/cabut", ['alasan' => 'Pasien menarik persetujuan'])->assertOk()->assertJsonPath('status', 'dicabut');
        $this->getJson($url)->assertJsonPath('pemrosesan', null)->assertJsonPath('marketing', null);
        $this->assertSame(0, PersetujuanData::where('pasien_id', $pasien->id)->where('status', 'berlaku')->count());
    }

    public function test_pendaftaran_kunjungan_bisa_mewajibkan_persetujuan_pemrosesan(): void
    {
        $pasien = Pasien::orderBy('id')->firstOrFail();
        $daftar = fn () => $this->postJson('/api/kunjungans', ['pasien_id' => $pasien->id,
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'), 'penjamin' => 'umum']);

        app(PengaturanService::class)->simpan(['pdp' => ['wajib_persetujuan' => true]]);
        $this->as('pendaftaran@eklinik.test');
        $daftar()->assertUnprocessable()->assertJsonValidationErrors('persetujuan_data');

        $this->postJson("/api/pasiens/{$pasien->id}/persetujuan-data", ['setuju_pemrosesan' => true, 'marketing' => false,
            'penandatangan_nama' => $pasien->nama, 'hubungan' => 'pasien', 'ttd' => $this->ttd()])->assertCreated();
        $daftar()->assertCreated();
        $this->getJson("/api/pasiens/{$pasien->id}?ringkas=1")->assertOk()
            ->assertJsonPath('pdp_pemrosesan', true)->assertJsonPath('pdp_marketing', false);
    }
}
