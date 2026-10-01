<?php

namespace App\Services\WhatsApp;

use App\Enums\StatusAppointment;
use App\Enums\StatusKunjungan;
use App\Jobs\KirimWhatsApp;
use App\Models\Appointment;
use App\Models\Kunjungan;
use App\Models\Pasien;
use App\Models\PesanWhatsapp;
use App\Services\PengaturanService;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Reminder booking (BK-06) & follow-up pasca tindakan (CR-01) lewat WhatsApp: penjadwal membuat pesan yang jatuh tempo (sekali per
 * booking/kunjungan per jenis), job mengirim lewat gateway, webhook memperbarui status & memproses tombol balasan.
 * Pesan ini bersifat layanan (bukan pemasaran), sehingga tidak bergantung opt-in marketing (PS-04).
 */
class WhatsAppService
{
    private const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    private const BULAN = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function __construct(private WhatsAppGateway $gateway, private PengaturanService $pengaturan) {}

    public function aktif(): bool
    {
        return (bool) config('services.whatsapp.aktif');
    }

    /** Nomor WhatsApp format internasional tanpa "+" (0812… → 62812…); null bila tidak valid. */
    public static function nomor(?string $hp): ?string
    {
        $n = Pasien::normalkanHp($hp);

        if (! $n || strlen($n) < 9) {
            return null;
        }

        return str_starts_with($n, '0') ? '62'.substr($n, 1) : $n;
    }

    /** Buat pesan yang jatuh tempo pada `$sekarang` lalu antrekan pengirimannya. Mengembalikan jumlah pesan baru. */
    public function jadwalkan(?CarbonImmutable $sekarang = null): int
    {
        if (! $this->aktif()) {
            return 0;
        }

        $now = $sekarang ?? CarbonImmutable::now();
        $baru = 0;
        $aktif = [StatusAppointment::Dijadwalkan->value, StatusAppointment::Dikonfirmasi->value];
        $relasi = ['pasien:id,nama,no_hp', 'cabang:id,nama', 'tindakans.tindakan:id,nama'];

        // Reminder H-1: dikirim sekali setelah jam yang diatur, untuk booking besok
        if ($this->pengaturan->get('wa.reminder_h1') && $now->format('H:i') >= $this->pengaturan->get('wa.jam_reminder_h1')) {
            Appointment::withoutGlobalScope('cabang')->with($relasi)->whereIn('status', $aktif)
                ->whereBetween('mulai_at', [$now->addDay()->startOfDay(), $now->addDay()->endOfDay()])
                ->each(function (Appointment $a) use (&$baru) {
                    $baru += $this->buatReminder($a, 'reminder_h1') ? 1 : 0;
                });
        }

        // Reminder ±2 jam sebelum: jendela 90–150 menit agar penjadwal tiap 10 menit pasti menangkapnya
        if ($this->pengaturan->get('wa.reminder_2jam')) {
            Appointment::withoutGlobalScope('cabang')->with($relasi)->whereIn('status', $aktif)
                ->whereBetween('mulai_at', [$now->addMinutes(90), $now->addMinutes(150)])
                ->each(function (Appointment $a) use (&$baru) {
                    $baru += $this->buatReminder($a, 'reminder_2jam') ? 1 : 0;
                });
        }

        // Follow-up pasca tindakan H+1 / H+7 (kunjungan yang sudah ditutup dan berisi tindakan)
        foreach ([1 => 'followup_h1', 7 => 'followup_h7'] as $hari => $jenis) {
            if (! $this->pengaturan->get("wa.{$jenis}")) {
                continue;
            }
            Kunjungan::withoutGlobalScope('cabang')
                ->with(['pasien:id,nama,no_hp', 'tindakans.tindakan:id,nama'])
                ->whereIn('status', [StatusKunjungan::MenungguPembayaran->value, StatusKunjungan::Selesai->value])
                ->whereDate('selesai_at', $now->subDays($hari)->toDateString())
                ->whereHas('tindakans')
                ->each(function (Kunjungan $k) use ($jenis, $hari, &$baru) {
                    $baru += $this->buatFollowup($k, $jenis, $hari) ? 1 : 0;
                });
        }

        return $baru;
    }

    /** Kirim satu pesan. Galat sementara dilempar (job menjadwalkan ulang); galat lain → `gagal`. */
    public function kirim(PesanWhatsapp $pesan): PesanWhatsapp
    {
        // Booking yang batal/sudah hadir tidak perlu diingatkan lagi
        if (str_starts_with($pesan->jenis, 'reminder') && $pesan->appointment
            && ! in_array($pesan->appointment->status, [StatusAppointment::Dijadwalkan, StatusAppointment::Dikonfirmasi], true)) {
            $pesan->update(['status' => 'gagal', 'error' => 'Dibatalkan: booking sudah '.$pesan->appointment->status->label()]);

            return $pesan;
        }

        $pesan->update(['percobaan' => $pesan->percobaan + 1]);
        $tombol = $pesan->appointment_id && str_starts_with($pesan->jenis, 'reminder')
            ? ["KONFIRMASI:{$pesan->appointment_id}", "UBAH:{$pesan->appointment_id}"]
            : [];

        try {
            $id = $this->gateway->kirimTemplate($pesan->no_tujuan, $pesan->template, $pesan->parameter, $tombol);
            $pesan->update(['status' => 'terkirim', 'wa_message_id' => $id, 'error' => null, 'terkirim_at' => now()]);
        } catch (WhatsAppException $e) {
            $pesan->update(['status' => $e->sementara ? 'antre' : 'gagal', 'error' => mb_substr($e->getMessage(), 0, 1000)]);

            if ($e->sementara) {
                throw $e;
            }
        }

        return $pesan;
    }

    /**
     * Webhook WhatsApp Cloud API: status pesan (sent/delivered/read/failed) & balasan tombol cepat.
     *
     * @return array{status: int, balasan: int}
     */
    public function webhook(array $payload): array
    {
        $hitung = ['status' => 0, 'balasan' => 0];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value = $change['value'] ?? [];

                foreach ($value['statuses'] ?? [] as $s) {
                    $pesan = PesanWhatsapp::where('wa_message_id', $s['id'] ?? '')->first();
                    if (! $pesan) {
                        continue;
                    }
                    $peta = ['sent' => 'terkirim', 'delivered' => 'diterima', 'read' => 'dibaca', 'failed' => 'gagal'];
                    $status = $peta[$s['status'] ?? ''] ?? null;
                    // Status tidak boleh mundur (dibaca → diterima) karena webhook bisa datang tidak berurutan
                    $urutan = ['antre' => 0, 'terkirim' => 1, 'diterima' => 2, 'dibaca' => 3, 'gagal' => 4];
                    if ($status && ($urutan[$status] > ($urutan[$pesan->status] ?? 0))) {
                        $pesan->update([
                            'status' => $status,
                            'dibaca_at' => $status === 'dibaca' ? now() : $pesan->dibaca_at,
                            'error' => $status === 'gagal' ? mb_substr(json_encode($s['errors'] ?? []), 0, 1000) : $pesan->error,
                        ]);
                        $hitung['status']++;
                    }
                }

                foreach ($value['messages'] ?? [] as $m) {
                    $payloadTombol = $m['button']['payload'] ?? $m['interactive']['button_reply']['id'] ?? null;
                    if ($payloadTombol && $this->prosesTombol($payloadTombol, (string) ($m['from'] ?? ''), $m['context']['id'] ?? null)) {
                        $hitung['balasan']++;
                    }
                }
            }
        }

        return $hitung;
    }

    /** "KONFIRMASI:{id}" → booking dikonfirmasi; "UBAH:{id}" → tanda minta ubah jadwal untuk front office. */
    private function prosesTombol(string $payload, string $dari, ?string $konteks): bool
    {
        if (! preg_match('/^(KONFIRMASI|UBAH):(\d+)$/', $payload, $m)) {
            return false;
        }

        $appointment = Appointment::withoutGlobalScope('cabang')->with('pasien:id,no_hp')->find((int) $m[2]);
        // Hanya nomor pasien pemilik booking yang boleh menjawab
        if (! $appointment || self::nomor($appointment->pasien?->no_hp) !== self::nomor($dari)) {
            return false;
        }

        return DB::transaction(function () use ($appointment, $m, $konteks) {
            $masihAktif = in_array($appointment->status, [StatusAppointment::Dijadwalkan, StatusAppointment::Dikonfirmasi], true);

            if ($m[1] === 'KONFIRMASI' && $appointment->status === StatusAppointment::Dijadwalkan) {
                $appointment->forceFill(['status' => StatusAppointment::Dikonfirmasi, 'dikonfirmasi_at' => now(), 'dikonfirmasi_via' => 'wa'])->save();
            } elseif ($m[1] === 'UBAH' && $masihAktif) {
                $appointment->forceFill(['minta_ubah_at' => now()])->save();
            }

            PesanWhatsapp::where('appointment_id', $appointment->id)
                ->when($konteks, fn ($q) => $q->where('wa_message_id', $konteks))
                ->latest('id')->first()
                ?->update(['balasan' => $m[1] === 'KONFIRMASI' ? 'konfirmasi' : 'ubah_jadwal', 'dibalas_at' => now()]);

            return true;
        });
    }

    private function buatReminder(Appointment $a, string $jenis): bool
    {
        $no = self::nomor($a->pasien?->no_hp);
        if (! $no) {
            return false;
        }

        $treatment = $a->tindakans->map(fn ($t) => $t->tindakan?->nama)->filter()->implode(', ') ?: 'perawatan';
        $waktu = self::HARI[$a->mulai_at->dayOfWeek].', '.$a->mulai_at->day.' '.self::BULAN[$a->mulai_at->month].' pukul '.$a->mulai_at->format('H:i');
        $cabang = $a->cabang?->nama ?? (string) $this->pengaturan->get('klinik.nama');
        $parameter = [$a->pasien->nama, $waktu, $treatment, $cabang];

        return $this->simpan([
            'jenis' => $jenis, 'appointment_id' => $a->id, 'cabang_id' => $a->cabang_id, 'pasien_id' => $a->pasien_id, 'no_tujuan' => $no,
            'template' => (string) $this->pengaturan->get('wa.template_reminder'), 'parameter' => $parameter,
            'pratinjau' => "Halo {$parameter[0]}, mengingatkan jadwal {$treatment} Anda di {$cabang} pada {$waktu}. Pilih Konfirmasi atau Ubah jadwal.",
        ]);
    }

    private function buatFollowup(Kunjungan $k, string $jenis, int $hari): bool
    {
        $no = self::nomor($k->pasien?->no_hp);
        if (! $no) {
            return false;
        }

        $treatment = $k->tindakans->map(fn ($t) => $t->tindakan?->nama)->filter()->unique()->implode(', ');
        $klinik = (string) $this->pengaturan->get('klinik.nama');
        $parameter = [$k->pasien->nama, $treatment, (string) $hari, $klinik];

        return $this->simpan([
            'jenis' => $jenis, 'kunjungan_id' => $k->id, 'cabang_id' => $k->cabang_id, 'pasien_id' => $k->pasien_id, 'no_tujuan' => $no,
            'template' => (string) $this->pengaturan->get('wa.template_followup'), 'parameter' => $parameter,
            'pratinjau' => "Halo {$parameter[0]}, bagaimana kondisi Anda {$hari} hari setelah {$treatment}? Hubungi {$klinik} bila ada keluhan.",
        ]);
    }

    /** Simpan sekali (unik per jenis & booking/kunjungan) lalu antrekan kirim. */
    private function simpan(array $data): bool
    {
        $kunci = isset($data['appointment_id']) ? ['jenis' => $data['jenis'], 'appointment_id' => $data['appointment_id']] : ['jenis' => $data['jenis'], 'kunjungan_id' => $data['kunjungan_id']];
        if (PesanWhatsapp::where($kunci)->exists()) {
            return false;
        }

        try {
            $pesan = PesanWhatsapp::create([...$data, 'status' => 'antre']);
        } catch (UniqueConstraintViolationException) {
            return false; // penjadwal paralel sudah membuatnya
        }

        KirimWhatsApp::dispatch($pesan->id);

        return true;
    }
}
