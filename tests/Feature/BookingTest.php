<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Enums\StatusAppointment;
use App\Models\Appointment;
use App\Models\Pasien;
use App\Models\Poli;
use App\Models\SumberDaya;
use App\Models\Tindakan;
use App\Models\User;
use Carbon\CarbonInterface;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Booking & jadwal (PRD BK-01, BK-02, BK-03, AN-01).
 */
class BookingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    private function dokter(): User
    {
        return User::where('email', 'dokter@eklinik.test')->firstOrFail();
    }

    private function pendaftaran(): User
    {
        return User::where('role', Role::Pendaftaran->value)->firstOrFail();
    }

    /** Senin depan jam 10:00 — selalu di dalam jadwal praktik seeder (Senin 09:00-17:00) dan di masa depan. */
    private function seninDepan(string $jam = '10:00'): string
    {
        return today()->next(CarbonInterface::MONDAY)->setTimeFromTimeString($jam)->toDateTimeString();
    }

    private function tindakan(): Tindakan
    {
        return Tindakan::where('is_active', true)->orderBy('id')->firstOrFail();
    }

    public function test_slot_mengikuti_jadwal_praktik_dan_durasi_treatment(): void
    {
        Sanctum::actingAs($this->pendaftaran());

        $tindakan = $this->tindakan();
        $senin = today()->next(CarbonInterface::MONDAY);

        $res = $this->getJson('/api/appointments-slot?'.http_build_query([
            'petugas_id' => $this->dokter()->id,
            'tanggal' => $senin->toDateString(),
            'tindakan_ids' => [$tindakan->id],
        ]))->assertOk();

        // Panjang slot = durasi + buffer treatment (BK-02)
        $res->assertJsonPath('durasi_menit', $tindakan->durasi_menit + $tindakan->buffer_menit);
        $res->assertJsonPath('jam_kerja.0.mulai', $senin->copy()->setTime(9, 0)->toDateTimeString());
        $res->assertJsonPath('jam_kerja.0.selesai', $senin->copy()->setTime(17, 0)->toDateTimeString());
        $this->assertNotEmpty($res->json('slot'));
    }

    public function test_cuti_sehari_penuh_mengosongkan_slot(): void
    {
        Sanctum::actingAs(User::where('role', Role::Admin->value)->firstOrFail());

        $senin = today()->next(CarbonInterface::MONDAY);
        $dokter = $this->dokter();

        $this->postJson('/api/jadwal-pengecualians', [
            'user_id' => $dokter->id,
            'tanggal' => $senin->toDateString(),
            'tipe' => 'cuti',
            'keterangan' => 'Cuti tahunan',
        ])->assertCreated();

        $res = $this->getJson('/api/appointments-slot?'.http_build_query([
            'petugas_id' => $dokter->id,
            'tanggal' => $senin->toDateString(),
            'tindakan_ids' => [$this->tindakan()->id],
        ]))->assertOk();

        $this->assertSame([], $res->json('jam_kerja'));
        $this->assertSame([], $res->json('slot'));
    }

    public function test_booking_menolak_bentrok_petugas_dan_sumber_daya(): void
    {
        Sanctum::actingAs($this->pendaftaran());

        $dokter = $this->dokter();
        $ruang = SumberDaya::where('kode', 'RG-01')->firstOrFail();
        $pasiens = Pasien::orderBy('id')->take(3)->pluck('id');
        $mulai = $this->seninDepan();

        $payload = [
            'pasien_id' => $pasiens[0],
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'),
            'petugas_id' => $dokter->id,
            'mulai_at' => $mulai,
            'tindakan_ids' => [$this->tindakan()->id],
            'sumber_daya_ids' => [$ruang->id],
        ];

        $this->postJson('/api/appointments', $payload)->assertCreated()
            ->assertJsonPath('status', StatusAppointment::Dijadwalkan->value);

        // Petugas sama, jam sama -> ditolak
        $this->postJson('/api/appointments', [...$payload, 'pasien_id' => $pasiens[1]])
            ->assertStatus(422)->assertJsonValidationErrors('mulai_at');

        // Petugas lain tapi ruang sama -> tetap ditolak
        $dokterLain = User::where('email', 'dokter.gigi@eklinik.test')->firstOrFail();
        $this->postJson('/api/appointments', [...$payload, 'pasien_id' => $pasiens[2], 'petugas_id' => $dokterLain->id])
            ->assertStatus(422)->assertJsonValidationErrors('mulai_at');

        $this->assertSame(1, Appointment::count());
    }

    public function test_booking_di_luar_jadwal_praktik_ditolak(): void
    {
        Sanctum::actingAs($this->pendaftaran());

        // Minggu (hari 0) tidak ada di jadwal seeder
        $minggu = today()->next(CarbonInterface::SUNDAY)->setTime(10, 0);

        $this->postJson('/api/appointments', [
            'pasien_id' => Pasien::value('id'),
            'petugas_id' => $this->dokter()->id,
            'mulai_at' => $minggu->toDateTimeString(),
            'tindakan_ids' => [$this->tindakan()->id],
        ])->assertStatus(422)->assertJsonValidationErrors('mulai_at');
    }

    public function test_checkin_membuat_kunjungan_dengan_nomor_antrian_dan_tindakan(): void
    {
        // Jam tetap: booking "1 jam lagi + 3 jam" tidak boleh melewati tengah malam saat test dijalankan malam hari.
        $this->travelTo(today()->setTime(10, 0));

        Sanctum::actingAs($this->pendaftaran());

        $tindakan = $this->tindakan();

        // Booking hari ini: jadwal tambahan agar tidak bergantung pada hari apa test dijalankan.
        $mulai = now()->addHour()->startOfHour();

        Sanctum::actingAs(User::where('role', Role::Admin->value)->firstOrFail());
        $this->postJson('/api/jadwal-pengecualians', [
            'user_id' => $this->dokter()->id,
            'tanggal' => today()->toDateString(),
            'tipe' => 'tambahan',
            'jam_mulai' => $mulai->format('H:i'),
            'jam_selesai' => $mulai->copy()->addHours(3)->format('H:i'),
        ])->assertCreated();

        Sanctum::actingAs($this->pendaftaran());

        $id = $this->postJson('/api/appointments', [
            'pasien_id' => Pasien::value('id'),
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'),
            'petugas_id' => $this->dokter()->id,
            'mulai_at' => $mulai->toDateTimeString(),
            'tindakan_ids' => [$tindakan->id],
        ])->assertCreated()->json('id');

        $res = $this->postJson("/api/appointments/{$id}/checkin")->assertCreated();

        $res->assertJsonPath('appointment.status', StatusAppointment::Hadir->value);
        $this->assertNotNull($res->json('kunjungan.no_registrasi'));
        $this->assertSame(1, $res->json('kunjungan.no_antrian'));

        // Treatment yang dibooking ikut tersalin ke kunjungan dengan tarif cabang; petugas booking = petugas tindakan
        $kunjunganId = $res->json('kunjungan.id');
        $this->assertDatabaseHas('kunjungan_tindakans', [
            'kunjungan_id' => $kunjunganId,
            'tindakan_id' => $tindakan->id,
            'tarif' => $tindakan->tarif,
            'petugas_id' => $this->dokter()->id,
            'icd9cm_id' => $tindakan->icd9cm_id,
        ]);

        // Check-in kedua ditolak
        $this->postJson("/api/appointments/{$id}/checkin")->assertStatus(422);
    }

    public function test_batal_membebaskan_slot_dan_booking_batal_tidak_bisa_diubah(): void
    {
        Sanctum::actingAs($this->pendaftaran());

        $payload = [
            'pasien_id' => Pasien::value('id'),
            'poli_id' => Poli::where('kode', 'ESTETIKA')->value('id'),
            'petugas_id' => $this->dokter()->id,
            'mulai_at' => $this->seninDepan('11:00'),
            'tindakan_ids' => [$this->tindakan()->id],
        ];

        $id = $this->postJson('/api/appointments', $payload)->assertCreated()->json('id');

        $this->postJson("/api/appointments/{$id}/batal", ['alasan_batal' => 'Pasien reschedule'])
            ->assertOk()->assertJsonPath('status', StatusAppointment::Batal->value);

        // Slot bebas lagi setelah batal
        $this->postJson('/api/appointments', $payload)->assertCreated();

        // Booking yang sudah batal tidak bisa dikonfirmasi lagi
        $this->postJson("/api/appointments/{$id}/konfirmasi")->assertStatus(422);
    }

    public function test_izin_booking_dipisah_dari_izin_jadwal(): void
    {
        // Dokter hanya punya booking.lihat: boleh membaca, tidak boleh membuat.
        Sanctum::actingAs($this->dokter());

        $this->getJson('/api/appointments')->assertOk();
        $this->postJson('/api/appointments', [
            'pasien_id' => Pasien::value('id'),
            'mulai_at' => $this->seninDepan(),
            'tindakan_ids' => [$this->tindakan()->id],
        ])->assertForbidden();

        // Pendaftaran boleh membuat booking tapi tidak mengelola jadwal praktik.
        Sanctum::actingAs($this->pendaftaran());
        $this->postJson('/api/jadwals', [
            'user_id' => $this->dokter()->id, 'hari' => 1, 'jam_mulai' => '08:00', 'jam_selesai' => '12:00',
        ])->assertForbidden();
    }

    public function test_jadwal_praktik_tidak_boleh_beririsan(): void
    {
        Sanctum::actingAs(User::where('role', Role::Admin->value)->firstOrFail());

        $terapis = User::where('email', 'terapis@eklinik.test')->firstOrFail();

        // Seeder sudah membuat Senin 09:00-17:00 untuk terapis
        $this->postJson('/api/jadwals', [
            'user_id' => $terapis->id, 'hari' => 1, 'jam_mulai' => '16:00', 'jam_selesai' => '18:00',
        ])->assertStatus(422)->assertJsonValidationErrors('jam_mulai');

        // Tidak beririsan -> diterima
        $this->postJson('/api/jadwals', [
            'user_id' => $terapis->id, 'hari' => 1, 'jam_mulai' => '17:00', 'jam_selesai' => '19:00',
        ])->assertCreated();
    }
}
