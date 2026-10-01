<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
use App\Enums\JenisCatatanTindakan;
use App\Enums\JenisKomisi;
use App\Enums\KondisiGigi;
use App\Enums\PeranKomisi;
use App\Http\Controllers\Controller;
use App\Models\KunjunganTindakan;
use App\Models\Poli;
use App\Models\Tindakan;
use App\Services\TindakanService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Katalog treatment / tindakan (PRD TR-01): kategori, durasi, harga dasar + harga per cabang, BHP standar, komisi per peran (KM-01).
 * Komisi hanya terlihat & bisa diubah pemegang izin `komisi.kelola`.
 */
class TindakanController extends Controller
{
    public function __construct(private TindakanService $service) {}

    /**
     * `tarif` = harga dasar; `tarif_cabang` & `tersedia` = untuk cabang `?cabang_id=` atau cabang aktif.
     * `aktif=1` (pilihan di pemeriksaan) juga menyembunyikan treatment yang tidak dilayani di cabang itu.
     * `komisi=1` (master treatment) menyertakan `komisis` bagi pemegang izin `komisi.kelola`.
     */
    public function index(Request $request, CabangAktif $cabangAktif): JsonResponse
    {
        $cabangId = $request->integer('cabang_id') ?: $cabangAktif->id();

        $tindakans = $this->filterAktif(Tindakan::query(), $request)
            ->select(['id', 'kode', 'nama', 'kategori_id', 'icd9cm_id', 'template_consent_id', 'jenis_catatan', 'protokol_foto_id', 'per_gigi', 'kondisi_gigi_hasil', 'durasi_menit', 'buffer_menit', 'tarif', 'is_active'])
            ->denganHargaCabang($cabangId)
            ->with(['kategori:id,nama', 'icd9cm:id,kode,nama'])
            ->withCount(['hargas', 'bhps', 'sumberDayas'])
            ->when($request->boolean('komisi') && $this->bolehKomisi($request), fn ($q) => $q->with('komisis:id,tindakan_id,peran,jenis,nilai'))
            ->when($request->boolean('aktif'), fn ($q) => $q->where('is_active', true)->tersediaDi($cabangId))
            ->when($request->filled('kategori_id'), fn ($q) => $q->where('kategori_id', $request->integer('kategori_id')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhereLike('kode', "{$q}%"));
            })
            ->orderBy('nama');

        return response()->json($this->paginate($tindakans, $request));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json($this->detail($this->service->simpan($this->validated($request)), $request), 201);
    }

    public function show(Request $request, Tindakan $tindakan): JsonResponse
    {
        return response()->json($this->detail($tindakan, $request));
    }

    public function update(Request $request, Tindakan $tindakan): JsonResponse
    {
        return response()->json($this->detail($this->service->simpan($this->validated($request, $tindakan), $tindakan), $request));
    }

    public function destroy(Tindakan $tindakan): JsonResponse
    {
        abort_if(KunjunganTindakan::where('tindakan_id', $tindakan->id)->exists(), 422,
            'Tindakan sudah pernah digunakan. Nonaktifkan saja, jangan dihapus.');
        $poli = Poli::where('tindakan_konsultasi_id', $tindakan->id)->value('nama');
        abort_if($poli !== null, 422, "Treatment ini jasa konsultasi {$poli}. Ganti jasa konsultasinya di Master Poli dulu.");

        $tindakan->delete();

        return response()->json(['message' => 'Tindakan dihapus.']);
    }

    private function bolehKomisi(Request $request): bool
    {
        return (bool) $request->user()?->punyaIzin(Izin::KomisiKelola);
    }

    private function detail(Tindakan $tindakan, Request $request): Tindakan
    {
        return $tindakan->load([
            ...($this->bolehKomisi($request) ? ['komisis' => fn ($q) => $q->select(['id', 'tindakan_id', 'peran', 'jenis', 'nilai'])->orderBy('id')] : []),
            'kategori:id,nama',
            'icd9cm:id,kode,nama',
            'templateConsent:id,nama',
            'protokolFoto:id,nama',
            // Harga cabang yang sudah dihapus tidak ditampilkan (whereHas mengikuti soft delete cabang).
            'hargas' => fn ($q) => $q->select(['id', 'tindakan_id', 'cabang_id', 'tarif', 'tersedia'])
                ->whereHas('cabang')->with('cabang:id,kode,nama,is_active'),
            'bhps' => fn ($q) => $q->select(['id', 'tindakan_id', 'obat_id', 'jumlah'])
                ->with('obat:id,kode,nama,satuan,is_active'),
            'sumberDayas' => fn ($q) => $q->select(['sumber_dayas.id', 'sumber_dayas.cabang_id', 'kode', 'nama', 'tipe', 'is_active'])
                ->with('cabang:id,kode,nama'),
        ]);
    }

    private function validated(Request $request, ?Tindakan $tindakan = null): array
    {
        abort_if($request->has('komisis') && ! $this->bolehKomisi($request), 403, 'Anda tidak berwenang mengubah komisi treatment.');

        $data = $request->validate([
            'kode' => ['required', 'string', 'max:20', Rule::unique('tindakans')->ignore($tindakan)],
            'nama' => ['required', 'string', 'max:255'],
            'kategori_id' => ['nullable', Rule::exists('kategori_tindakans', 'id')->whereNull('deleted_at')],
            'icd9cm_id' => ['nullable', Rule::exists('icd9cms', 'id')],
            'template_consent_id' => ['nullable', Rule::exists('template_consents', 'id')->whereNull('deleted_at')],
            'jenis_catatan' => ['nullable', Rule::enum(JenisCatatanTindakan::class)],
            'protokol_foto_id' => ['nullable', Rule::exists('protokol_fotos', 'id')->whereNull('deleted_at')],
            // Tindakan gigi (DG-01/07): wajib nomor gigi saat dikerjakan; kondisi odontogram setelah tindakan
            'per_gigi' => ['boolean'],
            'kondisi_gigi_hasil' => ['nullable', Rule::enum(KondisiGigi::class)],
            'durasi_menit' => ['required', 'integer', 'between:1,720'],
            'buffer_menit' => ['nullable', 'integer', 'between:0,240'],
            'tarif' => ['required', 'integer', 'min:0'],
            'is_active' => ['boolean'],

            'hargas' => ['sometimes', 'array'],
            'hargas.*.cabang_id' => ['required', 'distinct', Rule::exists('cabangs', 'id')->whereNull('deleted_at')],
            'hargas.*.tarif' => ['required', 'integer', 'min:0'],
            'hargas.*.tersedia' => ['boolean'],

            'bhps' => ['sometimes', 'array', 'max:50'],
            'bhps.*.obat_id' => ['required', 'distinct', Rule::exists('obats', 'id')->whereNull('deleted_at')],
            'bhps.*.jumlah' => ['required', 'numeric', 'gt:0', 'max:99999', 'decimal:0,3'],

            // Komisi per peran (KM-01): dokter = dokter kunjungan, terapis = pelaksana, asisten; tidak dikirim = peran tanpa komisi.
            'komisis' => ['sometimes', 'array', 'max:3'],
            'komisis.*.peran' => ['required', 'distinct', Rule::enum(PeranKomisi::class)->only(PeranKomisi::perTreatment())],
            'komisis.*.jenis' => ['required', Rule::enum(JenisKomisi::class)],
            'komisis.*.nilai' => ['required', 'numeric', 'min:0', 'max:100000000', 'decimal:0,2'],
            // Ruang/alat yang wajib dipakai saat booking (BK-08); hanya ruang/alat cabang yang terlihat pengguna yang diganti.
            'sumber_daya_ids' => ['sometimes', 'array', 'max:50'],
            'sumber_daya_ids.*' => ['integer', 'distinct', Rule::exists('sumber_dayas', 'id')->whereNull('deleted_at')],
        ]);

        foreach ($data['komisis'] ?? [] as $i => $komisi) {
            if ($komisi['jenis'] === JenisKomisi::Persen->value && $komisi['nilai'] > 100) {
                throw ValidationException::withMessages(["komisis.{$i}.nilai" => 'Komisi persen maksimal 100%.']);
            }
        }

        // Kondisi hasil hanya bermakna untuk tindakan per gigi → otomatis per gigi.
        if (! empty($data['kondisi_gigi_hasil'])) {
            $data['per_gigi'] = true;
        }

        return ['buffer_menit' => $data['buffer_menit'] ?? 0, 'jenis_catatan' => $data['jenis_catatan'] ?? JenisCatatanTindakan::Umum->value] + $data;
    }
}
