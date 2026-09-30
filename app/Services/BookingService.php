<?php

namespace App\Services;

use App\Enums\Penjamin;
use App\Enums\StatusAppointment;
use App\Enums\StatusKunjungan;
use App\Models\Appointment;
use App\Models\Kunjungan;
use App\Models\SumberDaya;
use App\Models\Tindakan;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking: buat, reschedule, batal, dan check-in menjadi kunjungan (PRD BK-01, BK-02, AN-01).
 *
 * Panjang slot dihitung dari durasi + buffer treatment yang dipilih, bukan dari input user.
 */
class BookingService
{
    public function __construct(
        private JadwalService $jadwal,
        private NomorUrutService $nomor,
    ) {}

    /**
     * Buat booking. `$data`: pasien_id, poli_id?, petugas_id?, mulai_at, tindakan_ids[], sumber_daya_ids[], catatan?
     */
    public function buat(array $data, int $cabangId, User $user): Appointment
    {
        return DB::transaction(function () use ($data, $cabangId, $user) {
            $tindakans = $this->tindakans($data['tindakan_ids'] ?? [], $cabangId);
            $sumberDayaIds = $this->sumberDayaIds($data['sumber_daya_ids'] ?? [], $cabangId);

            $mulai = CarbonImmutable::parse($data['mulai_at']);
            $selesai = $mulai->addMinutes($this->jadwal->durasiTotal($tindakans));

            $this->pastikanBebas($cabangId, $data['petugas_id'] ?? null, $sumberDayaIds, $mulai, $selesai);

            $appointment = Appointment::create([
                ...Arr::only($data, ['pasien_id', 'poli_id', 'petugas_id', 'catatan']),
                'cabang_id' => $cabangId,
                'no_booking' => $this->nomor->noBooking($mulai),
                'mulai_at' => $mulai,
                'selesai_at' => $selesai,
                'status' => StatusAppointment::Dijadwalkan,
                'created_by' => $user->id,
            ]);

            $this->syncTindakan($appointment, $tindakans);
            $appointment->sumberDayas()->sync($sumberDayaIds);

            return $appointment;
        });
    }

    /** Ubah jadwal &/atau treatment booking yang belum check-in. */
    public function ubah(Appointment $appointment, array $data, User $user): Appointment
    {
        return DB::transaction(function () use ($appointment, $data) {
            $this->pastikanBelumSelesai($appointment);

            $cabangId = $appointment->cabang_id;

            $tindakans = array_key_exists('tindakan_ids', $data)
                ? $this->tindakans($data['tindakan_ids'] ?? [], $cabangId)
                : $appointment->tindakans()->with('tindakan')->get()->pluck('tindakan');

            $sumberDayaIds = array_key_exists('sumber_daya_ids', $data)
                ? $this->sumberDayaIds($data['sumber_daya_ids'] ?? [], $cabangId)
                : $appointment->sumberDayas()->pluck('sumber_dayas.id')->all();

            $petugasId = array_key_exists('petugas_id', $data) ? $data['petugas_id'] : $appointment->petugas_id;
            $mulai = CarbonImmutable::parse($data['mulai_at'] ?? $appointment->mulai_at);
            $selesai = $mulai->addMinutes($this->jadwal->durasiTotal($tindakans));

            $this->pastikanBebas($cabangId, $petugasId, $sumberDayaIds, $mulai, $selesai, $appointment->id);

            $appointment->update([
                ...Arr::only($data, ['pasien_id', 'poli_id', 'catatan']),
                'petugas_id' => $petugasId,
                'mulai_at' => $mulai,
                'selesai_at' => $selesai,
            ]);

            if (array_key_exists('tindakan_ids', $data)) {
                $this->syncTindakan($appointment, $tindakans);
            }
            if (array_key_exists('sumber_daya_ids', $data)) {
                $appointment->sumberDayas()->sync($sumberDayaIds);
            }

            return $appointment;
        });
    }

    public function konfirmasi(Appointment $appointment): Appointment
    {
        $this->pastikanBelumSelesai($appointment);

        $appointment->update(['status' => StatusAppointment::Dikonfirmasi, 'dikonfirmasi_at' => now()]);

        return $appointment;
    }

    public function batal(Appointment $appointment, ?string $alasan): Appointment
    {
        $this->pastikanBelumSelesai($appointment);

        $appointment->update(['status' => StatusAppointment::Batal, 'alasan_batal' => $alasan]);

        return $appointment;
    }

    /** Tandai tidak hadir (no-show) — hanya untuk booking yang jadwalnya sudah lewat. */
    public function tidakHadir(Appointment $appointment): Appointment
    {
        $this->pastikanBelumSelesai($appointment);

        if ($appointment->mulai_at->isFuture()) {
            throw ValidationException::withMessages(['status' => 'Booking belum lewat jadwalnya.']);
        }

        $appointment->update(['status' => StatusAppointment::TidakHadir]);

        return $appointment;
    }

    /**
     * Check-in: booking menjadi kunjungan hari ini beserta nomor antrian (AN-01).
     * Treatment yang dibooking disalin ke kunjungan dengan tarif cabang saat check-in.
     */
    public function checkin(Appointment $appointment, User $user): Kunjungan
    {
        return DB::transaction(function () use ($appointment, $user) {
            $this->pastikanBelumSelesai($appointment);

            if (! $appointment->mulai_at->isToday()) {
                throw ValidationException::withMessages(['mulai_at' => 'Check-in hanya untuk booking hari ini.']);
            }

            if (! $appointment->poli_id) {
                throw ValidationException::withMessages(['poli_id' => 'Booking belum punya poli; lengkapi sebelum check-in.']);
            }

            $cabangId = $appointment->cabang_id;

            $kunjungan = Kunjungan::create([
                'cabang_id' => $cabangId,
                'no_registrasi' => $this->nomor->noRegistrasi(today()),
                'pasien_id' => $appointment->pasien_id,
                'poli_id' => $appointment->poli_id,
                'dokter_id' => $appointment->petugas_id,
                'tanggal' => today(),
                'no_antrian' => $this->nomor->noAntrian($cabangId, $appointment->poli_id, today()),
                'penjamin' => Penjamin::Umum,
                'keluhan' => $appointment->catatan,
                'status' => StatusKunjungan::Menunggu,
                'created_by' => $user->id,
            ]);

            foreach ($appointment->tindakans()->with('tindakan')->get() as $baris) {
                $tarif = Tindakan::withTrashed()
                    ->whereKey($baris->tindakan_id)
                    ->denganHargaCabang($cabangId)
                    ->value('tarif_cabang');

                $kunjungan->tindakans()->create([
                    'tindakan_id' => $baris->tindakan_id,
                    'jumlah' => 1,
                    'tarif' => (int) $tarif,
                ]);
            }

            $appointment->update([
                'status' => StatusAppointment::Hadir,
                'kunjungan_id' => $kunjungan->id,
                'checkin_at' => now(),
            ]);

            return $kunjungan;
        });
    }

    /**
     * Treatment yang dipilih, dipastikan aktif & dilayani di cabang ini.
     *
     * @return Collection<int, Tindakan>
     */
    private function tindakans(array $ids, int $cabangId): Collection
    {
        if ($ids === []) {
            throw ValidationException::withMessages(['tindakan_ids' => 'Pilih minimal satu treatment.']);
        }

        $tindakans = Tindakan::whereKey($ids)->where('is_active', true)->tersediaDi($cabangId)->get();

        if ($tindakans->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages([
                'tindakan_ids' => 'Ada treatment yang tidak aktif atau tidak dilayani di cabang ini.',
            ]);
        }

        return $tindakans;
    }

    /** @return list<int> */
    private function sumberDayaIds(array $ids, int $cabangId): array
    {
        if ($ids === []) {
            return [];
        }

        $ids = array_values(array_unique(array_map('intval', $ids)));

        $valid = SumberDaya::withoutGlobalScope('cabang')
            ->whereKey($ids)->where('cabang_id', $cabangId)->where('is_active', true)
            ->pluck('id');

        if ($valid->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'sumber_daya_ids' => 'Ada ruang/alat yang tidak aktif atau bukan milik cabang ini.',
            ]);
        }

        return $ids;
    }

    /** @param  list<int>  $sumberDayaIds */
    private function pastikanBebas(int $cabangId, ?int $petugasId, array $sumberDayaIds, CarbonImmutable $mulai, CarbonImmutable $selesai, ?int $kecuali = null): void
    {
        if ($petugasId) {
            $petugasValid = User::whereKey($petugasId)->where('is_active', true)
                ->where(fn ($w) => $w->where('cabang_id', $cabangId)->orWhereNull('cabang_id'))
                ->exists();

            if (! $petugasValid) {
                throw ValidationException::withMessages(['petugas_id' => 'Petugas tidak ditemukan atau tidak bertugas di cabang ini.']);
            }

            if ($this->jadwal->jamKerja($cabangId, $petugasId, $mulai) === []) {
                throw ValidationException::withMessages(['mulai_at' => 'Petugas tidak punya jadwal praktik pada tanggal tersebut.']);
            }
        }

        if ($bentrok = $this->jadwal->bentrok($cabangId, $petugasId, $sumberDayaIds, $mulai, $selesai, $kecuali)) {
            throw ValidationException::withMessages(['mulai_at' => $bentrok]);
        }
    }

    private function pastikanBelumSelesai(Appointment $appointment): void
    {
        if (! in_array($appointment->status, [StatusAppointment::Dijadwalkan, StatusAppointment::Dikonfirmasi], true)) {
            throw ValidationException::withMessages([
                'status' => 'Booking ini sudah '.$appointment->status->label().' dan tidak bisa diubah lagi.',
            ]);
        }
    }

    /** @param  Collection<int, Tindakan>  $tindakans */
    private function syncTindakan(Appointment $appointment, Collection $tindakans): void
    {
        $lama = $appointment->tindakans()->get()->keyBy('tindakan_id');
        $baru = $tindakans->keyBy('id');

        $lama->diffKeys($baru)->each->delete();

        foreach ($baru as $id => $tindakan) {
            ($lama->get($id) ?? $appointment->tindakans()->make(['tindakan_id' => $id]))
                ->fill(['durasi_menit' => $tindakan->durasi_menit, 'buffer_menit' => $tindakan->buffer_menit])
                ->save();
        }
    }
}
