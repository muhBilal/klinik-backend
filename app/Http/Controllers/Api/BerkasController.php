<?php

namespace App\Http\Controllers\Api;

use App\Enums\KategoriBerkas;
use App\Enums\StatusKunjungan;
use App\Http\Controllers\Controller;
use App\Models\Berkas;
use App\Models\Kunjungan;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BerkasService;
use App\Services\RekamMedisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lampiran klinis terenkripsi (fondasi foto klinis PRD FT-01..04). Daftar & tautan butuh izin rme.lihat,
 * unggah & hapus butuh berkas.kelola; isi file hanya lewat tautan bertanda tangan `GET /berkas/{uuid}/unduh`.
 */
class BerkasController extends Controller
{
    public function __construct(private BerkasService $berkas, private RekamMedisService $rekamMedis) {}

    /** Berkas kunjungan berakses terbatas (DR-03) tidak ikut terdaftar untuk pengguna di luar tim yang menangani. */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'pasien_id' => ['required_without:kunjungan_id', 'nullable', 'integer'],
            'kunjungan_id' => ['required_without:pasien_id', 'nullable', 'integer'],
            'kategori' => ['nullable', Rule::enum(KategoriBerkas::class)],
        ]);

        $user = $request->user();

        if ($request->filled('kunjungan_id')) {
            $kunjungan = Kunjungan::withoutGlobalScope('cabang')->find($request->integer('kunjungan_id'));

            if ($kunjungan && ! $this->rekamMedis->bolehLihat($user, $kunjungan)) {
                return response()->json([]);
            }
        }

        $tersembunyi = $request->filled('pasien_id')
            ? $this->rekamMedis->kunjunganTersembunyi($request->integer('pasien_id'), $user)
            : collect();

        $berkas = Berkas::query()
            ->when($tersembunyi->isNotEmpty(), fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('kunjungan_id')->orWhereNotIn('kunjungan_id', $tersembunyi)))
            ->with('pengunggah:id,name')
            ->when($request->filled('pasien_id'), fn ($q) => $q->where('pasien_id', $request->integer('pasien_id')))
            ->when($request->filled('kunjungan_id'), fn ($q) => $q->where('kunjungan_id', $request->integer('kunjungan_id')))
            ->when($request->filled('kategori'), fn ($q) => $q->where('kategori', $request->input('kategori')))
            ->latest('id')
            ->limit(200)
            ->get();

        return response()->json($berkas);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', config('eklinik.berkas.mimes')), 'max:'.config('eklinik.berkas.maks_kb')],
            'kategori' => ['required', Rule::enum(KategoriBerkas::class)],
            'pasien_id' => ['required', Rule::exists('pasiens', 'id')->whereNull('deleted_at')],
            'kunjungan_id' => ['nullable', 'integer'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        $cabangId = null;

        if ($data['kunjungan_id'] ?? null) {
            // Kunjungan harus milik cabang aktif, milik pasien yang sama, dan tidak batal.
            $kunjungan = Kunjungan::whereKey($data['kunjungan_id'])->first();

            if (! $kunjungan || (int) $kunjungan->pasien_id !== (int) $data['pasien_id'] || $kunjungan->status === StatusKunjungan::Batal) {
                throw ValidationException::withMessages(['kunjungan_id' => 'Kunjungan tidak valid untuk pasien ini.']);
            }

            $cabangId = $kunjungan->cabang_id;
        }

        $berkas = $this->berkas->simpan($request->file('file'), [
            'pasien_id' => (int) $data['pasien_id'],
            'kunjungan_id' => $data['kunjungan_id'] ?? null,
            'cabang_id' => $cabangId,
            'kategori' => $data['kategori'],
            'keterangan' => $data['keterangan'] ?? null,
        ], $request->user());

        return response()->json($berkas->load('pengunggah:id,name'), 201);
    }

    /** Tautan unduh bertanda tangan yang berlaku beberapa menit. Setiap permintaan tercatat di audit log. */
    public function tautan(Request $request, Berkas $berkas): JsonResponse
    {
        if ($berkas->kunjungan_id) {
            abort_unless($this->rekamMedis->bolehLihat($request->user(), $berkas->kunjungan), 403,
                'Berkas ini milik rekam medis berakses terbatas.');
        }

        return response()->json($this->berkas->tautan($berkas, $request->user()));
    }

    /**
     * Isi berkas. Tanpa header Authorization (dipakai <img src> / tab baru); keabsahan dijamin tanda tangan URL
     * yang memuat id pengguna penerbit tautan.
     */
    public function unduh(Request $request, Berkas $berkas, AuditService $audit): Response
    {
        $user = User::find($request->integer('u'));
        abort_unless($user?->is_active, 403, 'Tautan tidak berlaku.');

        $isi = $this->berkas->isi($berkas);

        $audit->catat('unduh_berkas', 'berkas', $berkas->id, [
            'user_id' => $user->id, 'cabang_id' => $berkas->cabang_id,
            'pasien_id' => $berkas->pasien_id, 'label' => $berkas->auditLabel(),
        ]);

        return response($isi, 200, [
            'Content-Type' => $berkas->mime,
            'Content-Length' => (string) strlen($isi),
            'Content-Disposition' => 'inline; filename="'.addcslashes($berkas->nama_file, '"\\').'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** Soft delete: berkas terenkripsi tetap disimpan untuk retensi rekam medis. */
    public function destroy(Berkas $berkas): JsonResponse
    {
        $berkas->delete();

        return response()->json(['message' => 'Berkas dihapus dari daftar.']);
    }
}
