<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
use App\Http\Controllers\Controller;
use App\Models\Pasien;
use App\Services\AuditService;
use App\Services\PersetujuanDataService;
use App\Services\RekamMedisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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

    /**
     * Kandidat pasien ganda (PS-02): NIK sama, nomor HP sama (9 digit terakhir), atau tanggal lahir sama dengan nama mirip
     * (kata pertama nama). `kecuali_id` = pasien yang sedang diubah.
     */
    public function duplikat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nama' => ['nullable', 'string', 'max:255'],
            'tanggal_lahir' => ['nullable', 'date'],
            'no_hp' => ['nullable', 'string', 'max:20'],
            'nik' => ['nullable', 'string', 'max:16'],
            'kecuali_id' => ['nullable', 'integer'],
        ]);

        $nik = preg_replace('/\D/', '', $data['nik'] ?? '');
        $hp = substr((string) Pasien::normalkanHp($data['no_hp'] ?? null), -9);
        $kataPertama = strtok(trim($data['nama'] ?? ''), ' ') ?: null;
        $tanggal = $data['tanggal_lahir'] ?? null;

        if (strlen($nik) < 16 && strlen($hp) < 9 && ! ($kataPertama && $tanggal)) {
            return response()->json([]);
        }

        $kandidat = Pasien::query()
            ->select(['id', 'no_rm', 'nik', 'nama', 'jenis_kelamin', 'tanggal_lahir', 'no_hp', 'alamat'])
            ->when($data['kecuali_id'] ?? null, fn ($q, $id) => $q->whereKeyNot($id))
            ->where(fn ($w) => $w
                ->when(strlen($nik) === 16, fn ($q) => $q->orWhere('nik', $nik))
                ->when(strlen($hp) === 9, fn ($q) => $q->orWhere('no_hp_digit', 'like', "%{$hp}"))
                ->when($kataPertama && $tanggal, fn ($q) => $q->orWhere(fn ($x) => $x
                    ->whereDate('tanggal_lahir', $tanggal)->whereLike('nama', "%{$kataPertama}%"))))
            ->limit(5)
            ->get();

        return response()->json($kandidat->map(fn (Pasien $p) => [
            ...$p->toArray(),
            'alasan' => array_values(array_filter([
                strlen($nik) === 16 && $p->nik === $nik ? 'NIK sama' : null,
                strlen($hp) === 9 && str_ends_with((string) Pasien::normalkanHp($p->no_hp), $hp) ? 'No. HP sama' : null,
                $tanggal && $p->tanggal_lahir?->toDateString() === Carbon::parse($tanggal)->toDateString() ? 'Nama mirip & tanggal lahir sama' : null,
            ])),
        ]));
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
    public function show(Request $request, Pasien $pasien, AuditService $audit, RekamMedisService $rme): JsonResponse
    {
        if (! $request->boolean('ringkas')) {
            $rekamMedis = $request->user()->punyaIzin(Izin::RmeLihat);

            $pasien->load(['kunjungans' => fn ($q) => $q
                ->withoutGlobalScope('cabang')
                ->select(['id', 'cabang_id', 'pasien_id', 'poli_id', 'dokter_id', 'tanggal', 'penjamin', 'status', 'akses_terbatas'])
                ->with([
                    'poli:id,nama,spesialisasi', 'dokter:id,name', 'cabang:id,kode,nama',
                    ...($rekamMedis ? [
                        'pemeriksaan:id,kunjungan_id,dokter_id,perawat_id',
                        'pemeriksaan.diagnosas:id,pemeriksaan_id,icd10_id,jenis', 'pemeriksaan.diagnosas.icd10:id,kode,nama',
                    ] : []),
                ])
                ->latest('tanggal')->latest('id')
                ->limit(50)]);

            if ($rekamMedis) {
                // Diagnosa kunjungan berakses terbatas (IMS) hanya untuk tim yang menangani (DR-03).
                $rme->sembunyikanTerbatas($pasien->kunjungans, $request->user());
                // Odontogram & rencana perawatan ditampilkan bila pasien punya data gigi (DG-01/02).
                $pasien->setAttribute('data_gigi', $pasien->odontogramKondisis()->exists() || $pasien->rencanaPerawatans()->exists());
            }

            $audit->catat('lihat', 'pasien', $pasien->id, ['pasien_id' => $pasien->id, 'label' => $pasien->auditLabel()]);
        }

        // Status consent UU PDP (PS-04) — identitas, bukan data klinis
        $pasien->setAttribute('persetujuan_data', app(PersetujuanDataService::class)->ringkasan($pasien));

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
