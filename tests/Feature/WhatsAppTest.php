<?php

namespace Tests\Feature;

use App\Enums\StatusAppointment;
use App\Enums\StatusKunjungan;
use App\Models\Appointment;
use App\Models\Kunjungan;
use App\Models\Pasien;
use App\Models\PesanWhatsapp;
use App\Models\Poli;
use App\Models\Tindakan;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * WhatsApp (PRD BK-06, CR-01): penjadwal reminder H-1 & 2 jam serta follow-up H+1, driver log & WhatsApp Cloud API (HTTP tiruan),
 * webhook bertanda tangan untuk status & tombol balasan (konfirmasi / ubah jadwal), pemantauan admin.
 */
class WhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private Pasien $pasien;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        config(['services.whatsapp' => [
            'aktif' => true, 'driver' => 'log', 'token' => null, 'phone_number_id' => null, 'base_url' => 'https://graph.test',
            'api_version' => 'v20.0', 'bahasa' => 'id', 'verify_token' => 'verif-123', 'app_secret' => 'rahasia-app', 'timeout' => 5,
        ]]);
        $this->pasien = Pasien::whereNotNull('no_hp')->firstOrFail();
        $this->pasien->update(['no_hp' => '0812-3456-7890']);
    }

    private function booking(CarbonImmutable $mulai, StatusAppointment $status = StatusAppointment::Dijadwalkan, ?Pasien $pasien = null): Appointment
    {
        $dokter = User::where('email', 'dokter@eklinik.test')->firstOrFail();
        $a = Appointment::withoutGlobalScopes()->create([
            'cabang_id' => $dokter->cabang_id, 'no_booking' => 'BOK-'.uniqid(), 'pasien_id' => ($pasien ?? $this->pasien)->id,
            'petugas_id' => $dokter->id, 'mulai_at' => $mulai, 'selesai_at' => $mulai->addMinutes(40), 'status' => $status,
        ]);
        $a->tindakans()->create(['tindakan_id' => Tindakan::where('kode', 'TRT-001')->value('id'), 'durasi_menit' => 30, 'buffer_menit' => 10]);

        return $a;
    }

    private function wa(): WhatsAppService
    {
        return app(WhatsAppService::class);
    }

    public function test_penjadwal_reminder_h1_2jam_dan_followup_sekali_saja(): void
    {
        $sekarang = CarbonImmutable::parse('2026-10-05 10:00:00');
        $this->travelTo($sekarang);

        $besok = $this->booking($sekarang->addDay()->setTime(14, 0));
        $duaJam = $this->booking($sekarang->addMinutes(120));
        $this->booking($sekarang->addDay()->setTime(15, 0), StatusAppointment::Batal); // batal → tidak diingatkan
        $tanpaHp = Pasien::create(['nama' => 'Tanpa HP', 'jenis_kelamin' => 'L', 'tanggal_lahir' => '1990-01-01']);
        $this->booking($sekarang->addDay()->setTime(16, 0), pasien: $tanpaHp);

        // Kunjungan kemarin yang sudah ditutup & berisi tindakan → follow-up H+1
        $k = Kunjungan::withoutGlobalScopes()->create([
            'cabang_id' => $besok->cabang_id, 'no_registrasi' => 'REG-T1', 'pasien_id' => $this->pasien->id, 'poli_id' => Poli::value('id'),
            'tanggal' => $sekarang->subDay()->toDateString(), 'no_antrian' => 99, 'penjamin' => 'umum', 'status' => StatusKunjungan::Selesai,
            'selesai_at' => $sekarang->subDay()->setTime(11, 0),
        ]);
        $k->tindakans()->create(['tindakan_id' => Tindakan::where('kode', 'TRT-011')->value('id'), 'jumlah' => 1, 'tarif' => 0]);

        $this->assertSame(3, $this->wa()->jadwalkan($sekarang));
        $this->assertSame(0, $this->wa()->jadwalkan($sekarang->addMinutes(10))); // tidak dobel

        $pesan = PesanWhatsapp::orderBy('id')->get()->keyBy('jenis');
        $this->assertSame($besok->id, $pesan['reminder_h1']->appointment_id);
        $this->assertSame($duaJam->id, $pesan['reminder_2jam']->appointment_id);
        $this->assertSame($k->id, $pesan['followup_h1']->kunjungan_id);
        $this->assertSame('6281234567890', $pesan['reminder_h1']->no_tujuan);
        $this->assertSame('terkirim', $pesan['reminder_h1']->status); // driver log
        $this->assertStringStartsWith('log-', $pesan['reminder_h1']->wa_message_id);
        $this->assertStringContainsString('Selasa, 6 Okt pukul 14:00', $pesan['reminder_h1']->pratinjau);

        // Sebelum jam reminder H-1 (default 09:00): hanya follow-up H+1 kunjungan kemarin, booking besok belum diingatkan
        PesanWhatsapp::query()->delete();
        $this->assertSame(1, $this->wa()->jadwalkan(CarbonImmutable::parse('2026-10-05 08:00:00')));
        $this->assertSame('followup_h1', PesanWhatsapp::value('jenis'));
    }

    public function test_nonaktif_tidak_membuat_pesan(): void
    {
        config(['services.whatsapp.aktif' => false]);
        $this->booking(CarbonImmutable::now()->addMinutes(120));

        $this->assertSame(0, $this->wa()->jadwalkan());
        $this->assertSame(0, PesanWhatsapp::count());
    }

    public function test_driver_cloud_mengirim_template_dengan_tombol_balasan(): void
    {
        config(['services.whatsapp.driver' => 'cloud', 'services.whatsapp.token' => 'TKN', 'services.whatsapp.phone_number_id' => '1099']);
        // Respons berurutan: pertama sukses, kedua template ditolak (galat permanen)
        Http::fake(['https://graph.test/*' => Http::sequence()
            ->push(['messages' => [['id' => 'wamid.ABC']]])
            ->push(['error' => ['message' => 'Template tidak ditemukan']], 400)]);
        $a = $this->booking(CarbonImmutable::now()->addMinutes(120));

        $this->wa()->jadwalkan();

        $pesan = PesanWhatsapp::firstOrFail();
        $this->assertSame('terkirim', $pesan->status);
        $this->assertSame('wamid.ABC', $pesan->wa_message_id);
        Http::assertSent(function (HttpRequest $r) use ($a) {
            $d = $r->data();

            return $r->url() === 'https://graph.test/v20.0/1099/messages'
                && $r->hasHeader('Authorization', 'Bearer TKN')
                && $d['to'] === '6281234567890'
                && $d['template']['name'] === 'pengingat_booking'
                && $d['template']['components'][0]['parameters'][0]['text'] === $this->pasien->nama
                && $d['template']['components'][1]['parameters'][0]['payload'] === "KONFIRMASI:{$a->id}"
                && $d['template']['components'][2]['parameters'][0]['payload'] === "UBAH:{$a->id}";
        });

        // Galat permanen (template ditolak) → gagal
        $b = $this->booking(CarbonImmutable::now()->addDay()->setTime(23, 0));
        $this->travelTo(now()->setTime(10, 0));
        $this->wa()->jadwalkan();
        $gagal = PesanWhatsapp::where('appointment_id', $b->id)->firstOrFail();
        $this->assertSame('gagal', $gagal->status);
        $this->assertStringContainsString('Template tidak ditemukan', $gagal->error);
    }

    public function test_webhook_status_dan_tombol_balasan(): void
    {
        $a = $this->booking(CarbonImmutable::now()->addMinutes(120));
        $this->wa()->jadwalkan();
        $pesan = PesanWhatsapp::firstOrFail();

        $kirim = function (array $value, ?string $tanda = null) {
            $body = json_encode(['object' => 'whatsapp_business_account', 'entry' => [['changes' => [['field' => 'messages', 'value' => $value]]]]]);

            return $this->call('POST', '/api/webhook/whatsapp', [], [], [], [
                'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_HUB_SIGNATURE_256' => $tanda ?? 'sha256='.hash_hmac('sha256', $body, 'rahasia-app'),
            ], $body);
        };

        // Tanda tangan salah ditolak
        $kirim(['statuses' => [['id' => $pesan->wa_message_id, 'status' => 'read']]], 'sha256=salah')->assertForbidden();

        // Status dibaca, lalu status "delivered" yang datang terlambat tidak menurunkan status
        $kirim(['statuses' => [['id' => $pesan->wa_message_id, 'status' => 'read']]])->assertOk()->assertJsonPath('status', 1);
        $kirim(['statuses' => [['id' => $pesan->wa_message_id, 'status' => 'delivered']]])->assertOk();
        $this->assertSame('dibaca', $pesan->refresh()->status);

        // Tombol dari nomor lain diabaikan; dari nomor pasien → booking dikonfirmasi via WhatsApp
        $kirim(['messages' => [['from' => '6289999999999', 'type' => 'button', 'button' => ['payload' => "KONFIRMASI:{$a->id}"]]]])->assertJsonPath('balasan', 0);
        $kirim(['messages' => [['from' => '6281234567890', 'type' => 'button', 'context' => ['id' => $pesan->wa_message_id], 'button' => ['payload' => "KONFIRMASI:{$a->id}"]]]])
            ->assertJsonPath('balasan', 1);
        $a->refresh();
        $this->assertSame(StatusAppointment::Dikonfirmasi, $a->status);
        $this->assertSame('wa', $a->dikonfirmasi_via);
        $this->assertSame('konfirmasi', $pesan->refresh()->balasan);

        // Minta ubah jadwal → ditandai untuk front office; reschedule oleh staf membersihkan tanda
        $kirim(['messages' => [['from' => '6281234567890', 'type' => 'button', 'button' => ['payload' => "UBAH:{$a->id}"]]]])->assertJsonPath('balasan', 1);
        $this->assertNotNull($a->refresh()->minta_ubah_at);

        Sanctum::actingAs(User::where('email', 'pendaftaran@eklinik.test')->firstOrFail());
        $this->getJson('/api/appointments?dari='.$a->mulai_at->toDateString())->assertOk()->assertJsonPath('data.0.minta_ubah_at', fn ($v) => $v !== null);
    }

    public function test_verifikasi_webhook_dan_pemantauan_admin(): void
    {
        $this->get('/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=verif-123&hub.challenge=777')->assertOk()->assertSee('777');
        $this->get('/api/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=salah&hub.challenge=777')->assertForbidden();

        $this->booking(CarbonImmutable::now()->addMinutes(120));
        Sanctum::actingAs(User::where('email', 'admin@eklinik.test')->firstOrFail());
        $this->postJson('/api/whatsapp/jadwalkan')->assertOk()->assertJsonPath('dibuat', 1);
        $this->getJson('/api/whatsapp/status')->assertOk()->assertJsonPath('driver', 'log')->assertJsonPath('per_status_7_hari.terkirim', 1);
        $this->getJson('/api/whatsapp/pesan?jenis=reminder_2jam')->assertOk()->assertJsonPath('data.0.pasien.nama', $this->pasien->nama);

        Sanctum::actingAs(User::where('email', 'kasir@eklinik.test')->firstOrFail());
        $this->getJson('/api/whatsapp/status')->assertForbidden();
    }
}
