<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatusAppointment;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Tindakan;
use App\Services\BookingService;
use App\Services\JadwalService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Booking / kalender (PRD BK-01, BK-02, AN-01).
 */
class AppointmentController extends Controller
{
    public function __construct(private BookingService $service) {}

    /**
     * Daftar booking cabang aktif. Default rentang: hari ini. `?dari=&sampai=` untuk tampilan kalender.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'dari' => ['nullable', 'date'],
            'sampai' => ['nullable', 'date', 'after_or_equal:dari'],
            'petugas_id' => ['nullable', 'integer'],
            'poli_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);

        $dari = $request->date('dari') ?? today();
        $sampai = $request->date('sampai') ?? $dari;

        $appointments = Appointment::query()
            ->select(['id', 'cabang_id', 'no_booking', 'pasien_id', 'poli_id', 'petugas_id', 'mulai_at',
                'selesai_at', 'status', 'catatan', 'alasan_batal', 'kunjungan_id', 'minta_ubah_at', 'dikonfirmasi_via'])
            ->with(['pasien:id,no_rm,nama,no_hp', 'poli:id,kode,nama', 'petugas:id,name',
                'tindakans:id,appointment_id,tindakan_id,durasi_menit,buffer_menit', 'tindakans.tindakan:id,kode,nama',
                'sumberDayas:id,kode,nama,tipe', 'kunjungan:id,no_registrasi,no_antrian,status'])
            ->whereBetween('mulai_at', [$dari->copy()->startOfDay(), $sampai->copy()->endOfDay()])
            ->when($request->filled('petugas_id'), fn ($q) => $q->where('petugas_id', $request->integer('petugas_id')))
            ->when($request->filled('poli_id'), fn ($q) => $q->where('poli_id', $request->integer('poli_id')))
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->input('status'))))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w
                    ->whereLike('no_booking', "{$q}%")
                    ->orWhereHas('pasien', fn ($p) => $p->where(fn ($x) => $x
                        ->whereLike('nama', "%{$q}%")->orWhere('no_rm', 'like', "{$q}%"))));
            })
            ->orderBy('mulai_at');

        return response()->json($this->paginate($appointments, $request, 50, 500));
    }

    /**
     * Slot kosong satu petugas pada satu tanggal, untuk treatment yang dipilih (BK-02).
     */
    public function slot(Request $request, JadwalService $jadwal, CabangAktif $cabangAktif): JsonResponse
    {
        $data = $request->validate([
            'petugas_id' => ['required', 'integer'],
            'tanggal' => ['required', 'date'],
            'tindakan_ids' => ['required', 'array', 'min:1'],
            'tindakan_ids.*' => ['required', 'integer', 'distinct', Rule::exists('tindakans', 'id')->whereNull('deleted_at')],
            'sumber_daya_ids' => ['nullable', 'array'],
            'sumber_daya_ids.*' => ['integer', 'distinct'],
            'kecuali_id' => ['nullable', 'integer'],
        ]);

        $cabangId = $cabangAktif->untukDataBaru();

        $menit = $jadwal->durasiTotal(
            Tindakan::whereKey($data['tindakan_ids'])->get(['id', 'durasi_menit', 'buffer_menit']),
        );

        return response()->json([
            'durasi_menit' => $menit,
            'jam_kerja' => array_map(
                fn ($r) => ['mulai' => $r['mulai']->toDateTimeString(), 'selesai' => $r['selesai']->toDateTimeString()],
                $jadwal->jamKerja($cabangId, $data['petugas_id'], $request->date('tanggal')),
            ),
            'slot' => $jadwal->slotTersedia(
                $cabangId, $data['petugas_id'], $request->date('tanggal'), $menit, $data['sumber_daya_ids'] ?? [],
                $data['kecuali_id'] ?? null,
            ),
        ]);
    }

    /**
     * Ruang/alat wajib untuk treatment yang dipilih di cabang aktif (BK-08). Dipakai form booking sebelum memilih slot.
     */
    public function kebutuhan(Request $request, CabangAktif $cabangAktif): JsonResponse
    {
        $data = $request->validate([
            'tindakan_ids' => ['required', 'array', 'min:1', 'max:20'],
            'tindakan_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $tindakans = Tindakan::whereKey($data['tindakan_ids'])->get(['id', 'nama']);

        return response()->json($this->service->kebutuhan($tindakans, $cabangAktif->untukDataBaru()));
    }

    public function store(Request $request, CabangAktif $cabang): JsonResponse
    {
        $appointment = $this->service->buat(
            $this->validated($request), $cabang->untukDataBaru(), $request->user(),
        );

        return response()->json($this->detail($appointment), 201);
    }

    public function show(Appointment $appointment): JsonResponse
    {
        return response()->json($this->detail($appointment));
    }

    public function update(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $this->validated($request, wajib: false);

        return response()->json($this->detail($this->service->ubah($appointment, $data, $request->user())));
    }

    public function konfirmasi(Appointment $appointment): JsonResponse
    {
        return response()->json($this->detail($this->service->konfirmasi($appointment)));
    }

    public function batal(Request $request, Appointment $appointment): JsonResponse
    {
        $data = $request->validate(['alasan_batal' => ['nullable', 'string', 'max:255']]);

        return response()->json($this->detail($this->service->batal($appointment, $data['alasan_batal'] ?? null)));
    }

    public function tidakHadir(Appointment $appointment): JsonResponse
    {
        return response()->json($this->detail($this->service->tidakHadir($appointment)));
    }

    /** Check-in: booking menjadi kunjungan hari ini beserta nomor antrian. */
    public function checkin(Request $request, Appointment $appointment): JsonResponse
    {
        $kunjungan = $this->service->checkin($appointment, $request->user());

        return response()->json([
            'appointment' => $this->detail($appointment->refresh()),
            'kunjungan' => $kunjungan->load(['pasien:id,no_rm,nama', 'poli:id,kode,nama', 'dokter:id,name', 'cabang:id,kode,nama']),
        ], 201);
    }

    public function destroy(Appointment $appointment): JsonResponse
    {
        abort_if($appointment->kunjungan_id !== null, 422,
            'Booking sudah menjadi kunjungan dan tidak bisa dihapus.');

        $appointment->delete();

        return response()->json(['message' => 'Booking dihapus.']);
    }

    private function detail(Appointment $appointment): Appointment
    {
        return $appointment->load([
            'pasien:id,no_rm,nama,no_hp,jenis_kelamin',
            'poli:id,kode,nama',
            'petugas:id,name',
            'cabang:id,kode,nama',
            'tindakans:id,appointment_id,tindakan_id,durasi_menit,buffer_menit',
            'tindakans.tindakan:id,kode,nama',
            'sumberDayas:id,kode,nama,tipe',
            'kunjungan:id,no_registrasi,no_antrian,status',
        ]);
    }

    /** `$wajib = false` (update): semua field opsional, hanya yang dikirim yang diubah. */
    private function validated(Request $request, bool $wajib = true): array
    {
        $ada = $wajib ? 'required' : 'sometimes';

        return $request->validate([
            'pasien_id' => [$ada, Rule::exists('pasiens', 'id')->whereNull('deleted_at')],
            'poli_id' => ['nullable', Rule::exists('polis', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'petugas_id' => ['nullable', 'integer'],
            'mulai_at' => [$ada, 'date'],
            'catatan' => ['nullable', 'string', 'max:1000'],

            'tindakan_ids' => [$ada, 'array', 'min:1', 'max:20'],
            'tindakan_ids.*' => ['required', 'integer', 'distinct', Rule::exists('tindakans', 'id')->whereNull('deleted_at')],

            'sumber_daya_ids' => ['sometimes', 'array', 'max:10'],
            'sumber_daya_ids.*' => ['integer', 'distinct'],
        ]);
    }

    /** Pilihan status untuk filter di UI. */
    public static function statusPilihan(): array
    {
        return array_map(fn (StatusAppointment $s) => ['nilai' => $s->value, 'label' => $s->label()], StatusAppointment::cases());
    }
}
