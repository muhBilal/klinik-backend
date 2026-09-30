<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
use App\Http\Controllers\Controller;
use App\Models\Pasien;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PasienController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Semua kolom identitas dipakai list & form ubah; timestamp tidak perlu.
        $pasiens = Pasien::query()
            ->select(['id', 'no_rm', 'nik', 'no_bpjs', 'nama', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir',
                'golongan_darah', 'alamat', 'no_hp', 'pekerjaan', 'alergi'])
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w
                    ->whereLike('nama', "%{$q}%")
                    ->orWhere('no_rm', 'like', "{$q}%")
                    ->orWhere('nik', 'like', "{$q}%")
                    ->orWhere('no_bpjs', 'like', "{$q}%"));
            })
            ->when(in_array($request->input('jenis_kelamin'), ['L', 'P'], true), fn ($q) => $q->where('jenis_kelamin', $request->input('jenis_kelamin')))
            ->when($request->filled('golongan_darah'), fn ($q) => $q->where('golongan_darah', $request->input('golongan_darah')))
            ->when($request->input('bpjs') === 'ya', fn ($q) => $q->whereNotNull('no_bpjs'))
            ->when($request->input('bpjs') === 'tidak', fn ($q) => $q->whereNull('no_bpjs'))
            ->latest('id');

        return response()->json($this->paginate($pasiens, $request, 15));
    }

    public function store(Request $request): JsonResponse
    {
        $pasien = Pasien::create($this->validated($request));

        return response()->json($pasien, 201);
    }

    /**
     * `?ringkas=1` hanya identitas pasien (tanpa riwayat kunjungan), mis. untuk form pendaftaran.
     * Riwayat kunjungan mencakup semua cabang; diagnosa hanya untuk pemegang izin rme.lihat.
     */
    public function show(Request $request, Pasien $pasien, AuditService $audit): JsonResponse
    {
        if (! $request->boolean('ringkas')) {
            $rekamMedis = $request->user()->punyaIzin(Izin::RmeLihat);

            $pasien->load(['kunjungans' => fn ($q) => $q
                ->withoutGlobalScope('cabang')
                ->select(['id', 'cabang_id', 'pasien_id', 'poli_id', 'dokter_id', 'tanggal', 'penjamin', 'status'])
                ->with([
                    'poli:id,nama', 'dokter:id,name', 'cabang:id,kode,nama',
                    ...($rekamMedis ? [
                        'pemeriksaan:id,kunjungan_id',
                        'pemeriksaan.diagnosas:id,pemeriksaan_id,icd10_id,jenis', 'pemeriksaan.diagnosas.icd10:id,kode,nama',
                    ] : []),
                ])
                ->latest('tanggal')->latest('id')
                ->limit(50)]);

            $audit->catat('lihat', 'pasien', $pasien->id, ['pasien_id' => $pasien->id, 'label' => $pasien->auditLabel()]);
        }

        return response()->json($pasien);
    }

    public function update(Request $request, Pasien $pasien): JsonResponse
    {
        $pasien->update($this->validated($request, $pasien));

        return response()->json($pasien);
    }

    /** Soft delete; pasien yang pernah berkunjung (di cabang mana pun) tidak boleh dihapus. */
    public function destroy(Pasien $pasien): JsonResponse
    {
        abort_if($pasien->kunjungans()->withoutGlobalScope('cabang')->exists(), 422, 'Pasien yang sudah memiliki riwayat kunjungan tidak dapat dihapus.');

        $pasien->delete();

        return response()->json(['message' => 'Data pasien dihapus.']);
    }

    private function validated(Request $request, ?Pasien $pasien = null): array
    {
        return $request->validate([
            'nik' => ['nullable', 'digits:16', Rule::unique('pasiens')->ignore($pasien)],
            'no_bpjs' => ['nullable', 'digits:13'],
            'nama' => ['required', 'string', 'max:255'],
            'jenis_kelamin' => ['required', Rule::in(['L', 'P'])],
            'tempat_lahir' => ['nullable', 'string', 'max:100'],
            'tanggal_lahir' => ['required', 'date', 'before_or_equal:today'],
            'golongan_darah' => ['nullable', Rule::in(['A', 'B', 'AB', 'O', '-'])],
            'alamat' => ['nullable', 'string', 'max:500'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'pekerjaan' => ['nullable', 'string', 'max:100'],
            'alergi' => ['nullable', 'string', 'max:500'],
        ]);
    }
}
