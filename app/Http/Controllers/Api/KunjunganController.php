<?php

namespace App\Http\Controllers\Api;

use App\Enums\Penjamin;
use App\Enums\Role;
use App\Enums\StatusKunjungan;
use App\Http\Controllers\Controller;
use App\Models\Kunjungan;
use App\Models\Pasien;
use App\Services\NomorUrutService;
use App\Services\PemeriksaanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KunjunganController extends Controller
{
    /**
     * Daftar kunjungan / antrian. Default: hari ini.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'tanggal' => ['nullable', 'date'],
            'status' => ['nullable', 'string'],
            'poli_id' => ['nullable', 'integer'],
            'dokter_id' => ['nullable', 'integer'],
            'penjamin' => ['nullable', Rule::enum(Penjamin::class)],
        ]);

        $kunjungans = Kunjungan::query()
            ->select(['id', 'no_registrasi', 'pasien_id', 'poli_id', 'dokter_id', 'tanggal', 'no_antrian', 'penjamin', 'keluhan', 'status', 'created_at'])
            ->with(['pasien:id,no_rm,nama,jenis_kelamin', 'poli:id,kode,nama', 'dokter:id,name'])
            ->whereDate('tanggal', $request->input('tanggal', today()->toDateString()))
            ->when($request->filled('poli_id'), fn ($q) => $q->where('poli_id', $request->integer('poli_id')))
            ->when($request->filled('dokter_id'), fn ($q) => $q->where('dokter_id', $request->integer('dokter_id')))
            ->when($request->filled('penjamin'), fn ($q) => $q->where('penjamin', $request->input('penjamin')))
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->input('status'))))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->whereHas('pasien', fn ($p) => $p->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhere('no_rm', 'like', "{$q}%")));
            })
            ->orderBy('poli_id')
            ->orderBy('no_antrian');

        return response()->json($this->paginate($kunjungans, $request, 50, 200));
    }

    /**
     * Pendaftaran kunjungan & pengambilan nomor antrian.
     */
    public function store(Request $request, NomorUrutService $nomor): JsonResponse
    {
        $data = $request->validate([
            'pasien_id' => ['required', 'exists:pasiens,id'],
            'poli_id' => ['required', Rule::exists('polis', 'id')->where('is_active', true)],
            'dokter_id' => ['nullable', Rule::exists('users', 'id')->where('role', Role::Dokter->value)],
            'penjamin' => ['required', Rule::enum(Penjamin::class)],
            'no_penjamin' => ['nullable', 'required_unless:penjamin,umum', 'string', 'max:30'],
            'keluhan' => ['nullable', 'string', 'max:1000'],
        ]);

        $sudahTerdaftar = Kunjungan::where('pasien_id', $data['pasien_id'])
            ->where('poli_id', $data['poli_id'])
            ->whereDate('tanggal', today())
            ->whereNotIn('status', [StatusKunjungan::Batal, StatusKunjungan::Selesai])
            ->exists();

        if ($sudahTerdaftar) {
            throw ValidationException::withMessages(['pasien_id' => 'Pasien sudah terdaftar di poli ini hari ini.']);
        }

        $kunjungan = DB::transaction(fn () => Kunjungan::create([
            ...$data,
            'no_registrasi' => $nomor->noRegistrasi(today()),
            'no_antrian' => $nomor->noAntrian($data['poli_id'], today()),
            'tanggal' => today(),
            'status' => StatusKunjungan::Menunggu,
            'created_by' => $request->user()->id,
        ]));

        // Cukup untuk tiket antrian
        return response()->json($kunjungan->load(['pasien:id,no_rm,nama', 'poli:id,kode,nama', 'dokter:id,name']), 201);
    }

    public function show(Kunjungan $kunjungan): JsonResponse
    {
        return response()->json($kunjungan->loadDetail());
    }

    public function batal(Kunjungan $kunjungan): JsonResponse
    {
        if ($kunjungan->status !== StatusKunjungan::Menunggu) {
            throw ValidationException::withMessages(['status' => 'Hanya kunjungan yang belum dipanggil yang dapat dibatalkan.']);
        }

        $kunjungan->update(['status' => StatusKunjungan::Batal]);

        return response()->json($kunjungan);
    }

    public function panggil(Request $request, Kunjungan $kunjungan, PemeriksaanService $service): JsonResponse
    {
        return response()->json($service->panggil($kunjungan, $request->user())->loadDetail());
    }

    /**
     * Riwayat kunjungan pasien (rekam medis) untuk ditampilkan saat pemeriksaan.
     * `?kecuali={id}` mengecualikan kunjungan yang sedang diperiksa.
     */
    public function riwayat(Request $request, Pasien $pasien): JsonResponse
    {
        $riwayat = $pasien->kunjungans()
            ->select(['id', 'pasien_id', 'poli_id', 'tanggal'])
            ->whereIn('status', [StatusKunjungan::MenungguPembayaran, StatusKunjungan::Selesai])
            ->when($request->filled('kecuali'), fn ($q) => $q->whereKeyNot($request->integer('kecuali')))
            ->with(['poli:id,nama', ...Kunjungan::relasiRekamMedis(), 'resep.items.obat:id,nama,satuan'])
            ->latest('tanggal')->latest('id')
            ->limit(20)
            ->get();

        return response()->json($riwayat);
    }
}
