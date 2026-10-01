<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
use App\Enums\Penjamin;
use App\Enums\StatusKunjungan;
use App\Http\Controllers\Controller;
use App\Models\Kunjungan;
use App\Models\Pasien;
use App\Models\User;
use App\Services\AuditService;
use App\Services\NomorUrutService;
use App\Services\PemeriksaanService;
use App\Services\PersetujuanDataService;
use App\Services\RekamMedisService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KunjunganController extends Controller
{
    /**
     * Daftar kunjungan / antrian cabang aktif. Default: hari ini.
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
            ->select(['id', 'cabang_id', 'no_registrasi', 'pasien_id', 'poli_id', 'dokter_id', 'tanggal', 'no_antrian', 'penjamin', 'keluhan', 'status', 'created_at'])
            ->with(['pasien:id,no_rm,nama,jenis_kelamin', 'poli:id,kode,nama', 'dokter:id,name', 'cabang:id,kode,nama'])
            ->whereDate('tanggal', $request->input('tanggal', today()->toDateString()))
            ->when($request->filled('poli_id'), fn ($q) => $q->where('poli_id', $request->integer('poli_id')))
            ->when($request->filled('dokter_id'), fn ($q) => $q->where('dokter_id', $request->integer('dokter_id')))
            ->when($request->filled('penjamin'), fn ($q) => $q->where('penjamin', $request->input('penjamin')))
            ->when($request->filled('status'), fn ($q) => $q->whereIn('status', explode(',', $request->input('status'))))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->whereHas('pasien', fn ($p) => $p->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhere('no_rm', 'like', "{$q}%")));
            })
            ->orderBy('cabang_id')
            ->orderBy('poli_id')
            ->orderBy('no_antrian');

        return response()->json($this->paginate($kunjungans, $request, 50, 200));
    }

    /**
     * Pendaftaran kunjungan & pengambilan nomor antrian di cabang aktif.
     */
    public function store(Request $request, NomorUrutService $nomor, CabangAktif $cabang): JsonResponse
    {
        $cabangId = $cabang->untukDataBaru();

        $data = $request->validate([
            'pasien_id' => ['required', Rule::exists('pasiens', 'id')->whereNull('deleted_at')],
            'poli_id' => ['required', Rule::exists('polis', 'id')->where('is_active', true)->whereNull('deleted_at')],
            'dokter_id' => ['nullable', 'integer'],
            'penjamin' => ['required', Rule::enum(Penjamin::class)],
            'no_penjamin' => ['nullable', 'required_unless:penjamin,umum', 'string', 'max:30'],
            'keluhan' => ['nullable', 'string', 'max:1000'],
        ]);

        app(PersetujuanDataService::class)->pastikanAda((int) $data['pasien_id']);

        $dokterValid = ! isset($data['dokter_id']) || User::dokter()
            ->whereKey($data['dokter_id'])
            ->where(fn ($w) => $w->where('cabang_id', $cabangId)->orWhereNull('cabang_id'))
            ->exists();

        if (! $dokterValid) {
            throw ValidationException::withMessages(['dokter_id' => 'Dokter tidak ditemukan atau tidak bertugas di cabang ini.']);
        }

        $sudahTerdaftar = Kunjungan::withoutGlobalScope('cabang')
            ->where('cabang_id', $cabangId)
            ->where('pasien_id', $data['pasien_id'])
            ->where('poli_id', $data['poli_id'])
            ->whereDate('tanggal', today())
            ->whereNotIn('status', [StatusKunjungan::Batal, StatusKunjungan::Selesai])
            ->exists();

        if ($sudahTerdaftar) {
            throw ValidationException::withMessages(['pasien_id' => 'Pasien sudah terdaftar di poli ini hari ini.']);
        }

        $kunjungan = DB::transaction(fn () => Kunjungan::create([
            ...$data,
            'cabang_id' => $cabangId,
            'no_registrasi' => $nomor->noRegistrasi(today()),
            'no_antrian' => $nomor->noAntrian($cabangId, $data['poli_id'], today()),
            'tanggal' => today(),
            'status' => StatusKunjungan::Menunggu,
            'created_by' => $request->user()->id,
        ]));

        // Cukup untuk tiket antrian
        return response()->json($kunjungan->load(['pasien:id,no_rm,nama', 'poli:id,kode,nama', 'dokter:id,name', 'cabang:id,kode,nama']), 201);
    }

    /**
     * Detail kunjungan (read-only), termasuk kunjungan cabang lain milik pasien (riwayat lintas cabang).
     * Isi rekam medis hanya untuk pemegang izin rme.lihat — dan untuk kunjungan berakses terbatas hanya tim yang
     * menanganinya / pemegang rme.terbatas (`rme_disembunyikan: true`). Aksesnya dicatat di audit log.
     */
    public function show(Request $request, int $kunjungan, AuditService $audit, RekamMedisService $rme): JsonResponse
    {
        $user = $request->user();
        $kunjungan = Kunjungan::withoutGlobalScope('cabang')->findOrFail($kunjungan);
        $izinRme = $user->punyaIzin(Izin::RmeLihat);
        $rekamMedis = $izinRme && $rme->bolehLihat($user, $kunjungan);

        $kunjungan->loadDetail($rekamMedis)->setAttribute('rme_disembunyikan', $izinRme && ! $rekamMedis);

        if ($rekamMedis) {
            $audit->catat('lihat', 'kunjungan', $kunjungan->id, [
                'pasien_id' => $kunjungan->pasien_id, 'label' => "Rekam medis {$kunjungan->no_registrasi}",
            ]);
        }

        return response()->json($kunjungan);
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
     * Riwayat kunjungan pasien (rekam medis) lintas cabang untuk ditampilkan saat pemeriksaan.
     * `?kecuali={id}` mengecualikan kunjungan yang sedang diperiksa.
     */
    public function riwayat(Request $request, Pasien $pasien, AuditService $audit, RekamMedisService $rme): JsonResponse
    {
        $riwayat = $pasien->kunjungans()
            ->withoutGlobalScope('cabang')
            ->select(['id', 'cabang_id', 'pasien_id', 'poli_id', 'dokter_id', 'tanggal', 'status', 'akses_terbatas'])
            ->whereIn('status', [StatusKunjungan::MenungguPembayaran, StatusKunjungan::Selesai])
            ->when($request->filled('kecuali'), fn ($q) => $q->whereKeyNot($request->integer('kecuali')))
            ->with(['poli:id,nama', 'cabang:id,kode,nama', ...Kunjungan::relasiRekamMedis(), 'resep.items.obat:id,nama,satuan'])
            ->latest('tanggal')->latest('id')
            ->limit(20)
            ->get();

        // Kunjungan berakses terbatas yang tidak ditangani user ini tampil tanpa isi rekam medis.
        $rme->sembunyikanTerbatas($riwayat, $request->user());

        $audit->catat('lihat', 'pasien', $pasien->id, ['pasien_id' => $pasien->id, 'label' => "Riwayat rekam medis {$pasien->no_rm}"]);

        return response()->json($riwayat);
    }
}
