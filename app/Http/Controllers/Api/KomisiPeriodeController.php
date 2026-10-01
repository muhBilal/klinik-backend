<?php

namespace App\Http\Controllers\Api;

use App\Enums\StatusKomisiPeriode;
use App\Http\Controllers\Controller;
use App\Models\KomisiBaris;
use App\Models\KomisiPeriode;
use App\Models\User;
use App\Services\KomisiService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Rekap & slip komisi per periode (PRD KM-03). Periode milik satu cabang; daftar mengikuti cabang aktif (lintas cabang tanpa
 * cabang aktif = semua). `komisi-saya` = slip milik user yang login dari periode yang sudah disetujui.
 */
class KomisiPeriodeController extends Controller
{
    private const RELASI = ['cabang:id,kode,nama,alamat,telepon', 'penghitung:id,name', 'penyetuju:id,name'];

    public function __construct(private KomisiService $service) {}

    public function index(Request $request): JsonResponse
    {
        $periodes = KomisiPeriode::query()
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->with(self::RELASI)
            ->withCount('barises')
            ->latest('mulai')->latest('id');

        return response()->json($this->paginate($periodes, $request));
    }

    public function store(Request $request, CabangAktif $cabang): JsonResponse
    {
        // Periode selalu untuk cabang aktif (periode lain tidak terlihat dari cabang ini).
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'mulai' => ['required', 'date'],
            'selesai' => ['required', 'date', 'after_or_equal:mulai'],
            'catatan' => ['nullable', 'string', 'max:500'],
        ]);
        $data['cabang_id'] = $cabang->untukDataBaru();

        return response()->json($this->service->buatPeriode($data, $request->user())->load(self::RELASI), 201);
    }

    /** Periode + ringkasan per petugas + baris (`?user_id=` = baris satu petugas saja, untuk slip). */
    public function show(Request $request, KomisiPeriode $komisiPeriode): JsonResponse
    {
        return response()->json($this->detail($komisiPeriode, $request->integer('user_id') ?: null));
    }

    public function hitung(Request $request, KomisiPeriode $komisiPeriode): JsonResponse
    {
        return response()->json($this->detail($this->service->hitung($komisiPeriode, $request->user())));
    }

    public function setujui(Request $request, KomisiPeriode $komisiPeriode): JsonResponse
    {
        return response()->json($this->detail($this->service->setujui($komisiPeriode, $request->user())));
    }

    public function penyesuaian(Request $request, KomisiPeriode $komisiPeriode): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')],
            'komisi' => ['required', 'integer', 'not_in:0', 'between:-100000000,100000000'],
            'keterangan' => ['required', 'string', 'max:255'],
        ]);

        $this->service->penyesuaian($komisiPeriode, (int) $data['user_id'], (int) $data['komisi'], $data['keterangan'], $request->user());

        return response()->json($this->detail($komisiPeriode->refresh()), 201);
    }

    public function hapusPenyesuaian(KomisiPeriode $komisiPeriode, KomisiBaris $baris): JsonResponse
    {
        $this->service->hapusPenyesuaian($komisiPeriode, $baris);

        return response()->json($this->detail($komisiPeriode->refresh()));
    }

    public function destroy(KomisiPeriode $komisiPeriode): JsonResponse
    {
        abort_if($komisiPeriode->terkunci(), 422, 'Rekap komisi yang sudah disetujui tidak bisa dihapus.');
        $komisiPeriode->delete();

        return response()->json(['message' => 'Periode komisi dihapus.']);
    }

    /**
     * Slip milik user yang login (semua peran): daftar periode disetujui yang memuat komisinya, atau `?periode_id=` = baris periode itu.
     */
    public function saya(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($request->filled('periode_id')) {
            // Slip lintas cabang (petugas yang bertugas di beberapa cabang).
            $periode = KomisiPeriode::withoutGlobalScope('cabang')->where('status', StatusKomisiPeriode::Disetujui)->findOrFail($request->integer('periode_id'));
            $baris = $periode->barises()->where('user_id', $user->id)->with('kunjungan:id,no_registrasi')->get();
            abort_if($baris->isEmpty(), 404);

            return response()->json([...$periode->load(self::RELASI)->toArray(), 'user' => $user->only(['id', 'name', 'role']),
                'barises' => $baris, 'total' => (int) $baris->sum('komisi')]);
        }

        $periodes = KomisiPeriode::withoutGlobalScope('cabang')->where('status', StatusKomisiPeriode::Disetujui)
            ->whereHas('barises', fn ($q) => $q->where('user_id', $user->id))
            ->withSum(['barises as total_saya' => fn ($q) => $q->where('user_id', $user->id)], 'komisi')
            ->with('cabang:id,nama')
            ->latest('mulai')
            ->get();

        return response()->json($periodes);
    }

    private function detail(KomisiPeriode $periode, ?int $userId = null): array
    {
        $baris = $periode->barises()
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->with(['user:id,name,role', 'kunjungan:id,no_registrasi'])
            ->get();

        return [
            ...$periode->load(self::RELASI)->toArray(),
            'ringkasan' => $this->service->ringkasan($periode),
            'barises' => $baris,
            'petugas' => $userId ? User::withTrashed()->find($userId, ['id', 'name', 'role']) : null,
        ];
    }
}
