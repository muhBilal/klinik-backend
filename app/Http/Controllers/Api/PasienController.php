<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
use App\Enums\JenisPersetujuanData;
use App\Enums\StatusPersetujuanData;
use App\Http\Controllers\Controller;
use App\Models\Pasien;
use App\Services\AuditService;
use App\Services\RekamMedisService;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Master pasien: identitas & kontak saja. Data klinis (PS-03) lewat DataKlinisController (rme.lihat), persetujuan UU PDP (PS-04)
 * lewat PersetujuanDataController; daftar & detail menyertakan `pdp_pemrosesan` / `pdp_marketing` (persetujuan yang berlaku).
 */
class PasienController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Semua kolom identitas dipakai list & form ubah; timestamp tidak perlu.
        $pasiens = Pasien::query()
            ->select(['id', 'no_rm', 'nik', 'no_bpjs', 'nama', 'jenis_kelamin', 'tempat_lahir', 'tanggal_lahir',
                'golongan_darah', 'alamat', 'no_hp', 'pekerjaan'])
            ->withExists(self::persetujuan())
            ->when($request->input('persetujuan') === 'belum', fn ($q) => $q->whereDoesntHave('persetujuanDatas', self::berlaku(JenisPersetujuanData::Pemrosesan)))
            ->when($request->input('persetujuan') === 'ada', fn ($q) => $q->whereHas('persetujuanDatas', self::berlaku(JenisPersetujuanData::Pemrosesan)))
            ->when($request->input('persetujuan') === 'marketing', fn ($q) => $q->whereHas('persetujuanDatas', self::berlaku(JenisPersetujuanData::Marketing)))
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
    public function show(Request $request, Pasien $pasien, AuditService $audit, RekamMedisService $rme): JsonResponse
    {
        $pasien->loadExists(self::persetujuan());

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

    /** Subquery persetujuan PDP yang berlaku → atribut boolean `pdp_pemrosesan`, `pdp_marketing`. */
    private static function persetujuan(): array
    {
        return [
            'persetujuanDatas as pdp_pemrosesan' => self::berlaku(JenisPersetujuanData::Pemrosesan),
            'persetujuanDatas as pdp_marketing' => self::berlaku(JenisPersetujuanData::Marketing),
        ];
    }

    private static function berlaku(JenisPersetujuanData $jenis): Closure
    {
        return fn (Builder $q) => $q->where('jenis', $jenis->value)->where('status', StatusPersetujuanData::Berlaku->value);
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
        ]);
    }
}
