<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Icd10;
use App\Models\Obat;
use App\Models\Pasien;
use App\Models\PasienAlergi;
use App\Models\Poli;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\PengaturanService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuatBerkasUji;
use Tests\TestCase;

/**
 * Data klinis pasien terstruktur (PRD PS-03), peringatan alergi saat meresepkan (FR-02 sebagian), consent UU PDP terpisah
 * untuk pemrosesan data & marketing (PS-04), dan deteksi pasien ganda (PS-02).
 */
class ProfilKlinisPdpTest extends TestCase
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

    private function pasien(string $jk = 'P'): Pasien
    {
        return Pasien::where('jenis_kelamin', $jk)->orderBy('id')->firstOrFail();
    }

    public function test_profil_klinis_terstruktur_dan_peringatan(): void
    {
        $pasien = $this->pasien('P');
        $amox = Obat::where('kode', 'OBT-002')->firstOrFail();

        $this->as('perawat@eklinik.test');
        $res = $this->putJson("/api/pasiens/{$pasien->id}/profil-klinis", [
            'fitzpatrick' => 5,
            'status_kehamilan' => 'hamil',
            'hpht' => now()->subWeeks(10)->toDateString(),
            'riwayat_obat' => 'Isotretinoin dihentikan 2025',
            'alergis' => [
                ['jenis' => 'obat', 'zat' => 'Amoxicillin', 'obat_id' => $amox->id, 'reaksi' => 'Urtikaria', 'keparahan' => 'berat'],
                ['jenis' => 'makanan', 'zat' => 'Udang', 'keparahan' => 'ringan'],
            ],
        ])->assertOk()
            ->assertJsonPath('profil.fitzpatrick', 5)
            ->assertJsonPath('alergis.0.zat', 'Amoxicillin')
            ->assertJsonPath('peringatan.0.tingkat', 'bahaya')
            ->json();

        $teks = collect($res['peringatan'])->pluck('teks')->implode(' | ');
        $this->assertStringContainsString('Hamil · 10 minggu', $teks);
        $this->assertStringContainsString('Fitzpatrick tipe 5', $teks);

        // Replace-all: alergi yang tidak dikirim dihapus (soft delete, tetap ada untuk audit)
        $idAmox = $res['alergis'][0]['id'];
        $this->putJson("/api/pasiens/{$pasien->id}/profil-klinis", ['alergis' => [
            ['id' => $idAmox, 'jenis' => 'obat', 'zat' => 'Amoxicillin', 'obat_id' => $amox->id, 'keparahan' => 'berat'],
        ]])->assertOk()->assertJsonCount(1, 'alergis');
        $this->assertSame(1, PasienAlergi::onlyTrashed()->where('pasien_id', $pasien->id)->count());

        // Data klinis tidak untuk peran non-klinis; identitas pasien tidak memuat profil klinis
        $this->as('kasir@eklinik.test');
        $this->getJson("/api/pasiens/{$pasien->id}/profil-klinis")->assertForbidden();
        $this->getJson("/api/pasiens/{$pasien->id}")->assertOk()->assertJsonMissingPath('profil_klinis')->assertJsonMissingPath('alergis');

        // Status hamil hanya untuk pasien perempuan
        $this->as('perawat@eklinik.test');
        $this->putJson('/api/pasiens/'.$this->pasien('L')->id.'/profil-klinis', ['status_kehamilan' => 'hamil'])
            ->assertStatus(422)->assertJsonValidationErrors('status_kehamilan');
    }

    public function test_resep_obat_alergi_butuh_konfirmasi_dokter(): void
    {
        app(PengaturanService::class)->simpan(['rme' => ['wajib_informed_consent' => false]]);
        $pasien = $this->pasien('P');
        $amox = Obat::where('kode', 'OBT-002')->firstOrFail();
        $parasetamol = Obat::where('kode', 'OBT-001')->firstOrFail();
        PasienAlergi::create(['pasien_id' => $pasien->id, 'jenis' => 'obat', 'zat' => 'Amoxicillin', 'obat_id' => $amox->id, 'keparahan' => 'berat']);

        $this->as('pendaftaran@eklinik.test');
        $id = $this->postJson('/api/kunjungans', ['pasien_id' => $pasien->id, 'poli_id' => Poli::where('kode', 'KULIT')->value('id'), 'penjamin' => 'umum'])
            ->assertCreated()->json('id');
        $this->as('dokter.kulit@eklinik.test');
        $this->postJson("/api/kunjungans/{$id}/panggil")->assertOk();

        $resep = fn (array $obats, array $extra = []) => $this->putJson("/api/kunjungans/{$id}/pemeriksaan", [
            'diagnosas' => [['icd10_id' => Icd10::value('id')]],
            'resep' => array_map(fn ($o) => ['obat_id' => $o->id, 'jumlah' => 10, 'aturan_pakai' => '3x1'], $obats),
            ...$extra,
        ]);

        $resep([$parasetamol])->assertOk();
        $resep([$parasetamol, $amox])->assertStatus(422)->assertJsonValidationErrors(['resep.1.obat_id', 'konfirmasi_alergi']);
        $resep([$parasetamol, $amox], ['abaikan_alergi' => true])->assertOk();
        // Obat yang sudah dikonfirmasi tidak ditanyakan ulang saat pemeriksaan disimpan lagi
        $resep([$parasetamol, $amox])->assertOk();
    }

    public function test_consent_pdp_pemrosesan_dan_marketing_terpisah(): void
    {
        $pasien = $this->pasien();
        $this->as('pendaftaran@eklinik.test');

        $this->getJson("/api/pasiens/{$pasien->id}/persetujuan-data/pratinjau?jenis=marketing&setuju=0")->assertOk()
            ->assertJsonPath('isi', fn ($isi) => str_contains($isi, 'TIDAK MENYETUJUI') && str_contains($isi, $pasien->nama));

        $tanda = ['penandatangan_nama' => $pasien->nama, 'hubungan' => 'pasien', 'ttd' => $this->ttd()];

        // Pemrosesan data tidak bisa "ditolak" lewat sistem
        $this->postJson("/api/pasiens/{$pasien->id}/persetujuan-data", ['jenis' => 'pemrosesan', 'setuju' => false, ...$tanda])
            ->assertStatus(422)->assertJsonValidationErrors('setuju');

        $proses = $this->postJson("/api/pasiens/{$pasien->id}/persetujuan-data", ['jenis' => 'pemrosesan', 'setuju' => true, ...$tanda])
            ->assertCreated()->assertJsonMissingPath('ttd')->json();
        // Menolak marketing = tercatat eksplisit, pelayanan tetap jalan
        $this->postJson("/api/pasiens/{$pasien->id}/persetujuan-data", ['jenis' => 'marketing', 'setuju' => false, ...$tanda])->assertCreated();

        $this->getJson("/api/pasiens/{$pasien->id}")->assertOk()
            ->assertJsonPath('persetujuan_data.pemrosesan', true)
            ->assertJsonPath('persetujuan_data.marketing', false);
        $this->assertFalse(Pasien::optInMarketing()->whereKey($pasien->id)->exists());

        // Opt-in kemudian: yang lama menjadi "diganti"
        $this->postJson("/api/pasiens/{$pasien->id}/persetujuan-data", ['jenis' => 'marketing', 'setuju' => true, ...$tanda])->assertCreated();
        $this->assertTrue(Pasien::optInMarketing()->whereKey($pasien->id)->exists());
        $this->getJson("/api/pasiens/{$pasien->id}/persetujuan-data")->assertOk()
            ->assertJsonPath('marketing.setuju', true)
            ->assertJsonCount(3, 'riwayat')
            ->assertJsonPath('riwayat.1.status', 'diganti');

        // Lihat naskah + tanda tangan tercatat audit; checksum valid; pencabutan
        $this->getJson("/api/persetujuan-datas/{$proses['uuid']}")->assertOk()->assertJsonPath('checksum_valid', true)
            ->assertJsonPath('ttd', fn ($ttd) => str_starts_with($ttd, 'data:image/png'));
        $this->assertTrue(AuditLog::where('aksi', 'lihat')->where('tipe', 'persetujuan_data')->exists());
        $this->postJson("/api/persetujuan-datas/{$proses['uuid']}/cabut", ['alasan' => 'Permintaan pasien'])->assertOk()->assertJsonPath('status', 'dicabut');
        $this->getJson("/api/pasiens/{$pasien->id}")->assertJsonPath('persetujuan_data.pemrosesan', null);

        // Kasir hanya boleh melihat status, bukan menandatangani
        $this->as('kasir@eklinik.test');
        $this->getJson("/api/pasiens/{$pasien->id}/persetujuan-data")->assertOk();
        $this->postJson("/api/pasiens/{$pasien->id}/persetujuan-data", ['jenis' => 'marketing', 'setuju' => true, ...$tanda])->assertForbidden();
    }

    public function test_wajib_consent_menahan_pendaftaran_dan_booking(): void
    {
        app(PengaturanService::class)->simpan(['pdp' => ['wajib_consent' => true]]);
        $pasien = $this->pasien();
        $this->as('pendaftaran@eklinik.test');
        $daftar = fn () => $this->postJson('/api/kunjungans', ['pasien_id' => $pasien->id, 'poli_id' => Poli::where('kode', 'KULIT')->value('id'), 'penjamin' => 'umum']);

        $daftar()->assertStatus(422)->assertJsonValidationErrors(['pasien_id', 'consent_data']);
        $this->postJson('/api/appointments', [
            'pasien_id' => $pasien->id, 'mulai_at' => today()->addWeek()->setTime(10, 0)->toDateTimeString(),
            'tindakan_ids' => [Tindakan::value('id')],
        ])->assertStatus(422)->assertJsonValidationErrors('consent_data');

        $this->postJson("/api/pasiens/{$pasien->id}/persetujuan-data", [
            'jenis' => 'pemrosesan', 'setuju' => true, 'penandatangan_nama' => $pasien->nama, 'hubungan' => 'pasien', 'ttd' => $this->ttd(),
        ])->assertCreated();
        $daftar()->assertCreated();
    }

    public function test_deteksi_pasien_ganda(): void
    {
        $this->as('pendaftaran@eklinik.test');
        $asli = $this->postJson('/api/pasiens', [
            'nik' => '3174012345670001', 'nama' => 'Siti Aminah', 'jenis_kelamin' => 'P', 'tanggal_lahir' => '1990-05-12', 'no_hp' => '0812-3456-7890',
        ])->assertCreated()->json();

        // NIK sama
        $this->getJson('/api/pasiens-duplikat?nik=3174012345670001')->assertOk()
            ->assertJsonPath('0.id', $asli['id'])->assertJsonPath('0.alasan.0', 'NIK sama');
        // Nomor HP sama meski format berbeda (+62)
        $this->getJson('/api/pasiens-duplikat?'.http_build_query(['no_hp' => '+62 812 3456 7890']))->assertOk()
            ->assertJsonPath('0.id', $asli['id']);
        // Nama mirip & tanggal lahir sama
        $this->getJson('/api/pasiens-duplikat?'.http_build_query(['nama' => 'Siti Aminah, S.Pd', 'tanggal_lahir' => '1990-05-12']))->assertOk()
            ->assertJsonPath('0.alasan', ['Nama mirip & tanggal lahir sama']);
        // Pasien itu sendiri (saat ubah data) dan data tidak mirip tidak dikembalikan
        $this->getJson('/api/pasiens-duplikat?nik=3174012345670001&kecuali_id='.$asli['id'])->assertOk()->assertExactJson([]);
        $this->getJson('/api/pasiens-duplikat?'.http_build_query(['nama' => 'Budi', 'tanggal_lahir' => '1990-05-12']))->assertOk()->assertJsonCount(0);
    }
}
