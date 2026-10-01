<?php

namespace App\Http\Controllers\Api;

use App\Enums\KategoriBerkas;
use App\Enums\StatusKunjungan;
use App\Enums\TahapFoto;
use App\Http\Controllers\Controller;
use App\Models\Berkas;
use App\Models\Kunjungan;
use App\Models\ProtokolFoto;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BerkasService;
use App\Services\PersetujuanFotoService;
use App\Services\RekamMedisService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lampiran klinis terenkripsi & foto klinis terstruktur (PRD FT-01..04, RM-04). Daftar & tautan butuh izin rme.lihat,
 * unggah & hapus butuh berkas.kelola; isi file hanya lewat tautan bertanda tangan `GET /berkas/{uuid}/unduh`.
 */
class BerkasController extends Controller
{
    /** Kolom & relasi daftar berkas (dipakai daftar lampiran dan galeri foto). */
    private const RELASI = ['pengunggah:id,name', 'protokol:id,nama,posisi', 'kunjungan:id,pasien_id,poli_id,tanggal,no_registrasi', 'kunjungan.poli:id,nama'];

    public function __construct(private BerkasService $berkas, private RekamMedisService $rekamMedis) {}

    /**
     * Berkas kunjungan berakses terbatas (DR-03) tidak ikut terdaftar untuk pengguna di luar tim yang menangani.
     * Filter foto: `kategori=foto_klinis`, `protokol_foto_id`, `posisi`, `tahap`.
     */
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'pasien_id' => ['required_without:kunjungan_id', 'nullable', 'integer'],
            'kunjungan_id' => ['required_without:pasien_id', 'nullable', 'integer'],
            'kategori' => ['nullable', Rule::enum(KategoriBerkas::class)],
            'protokol_foto_id' => ['nullable', 'integer'],
            'posisi' => ['nullable', 'string', 'max:30'],
            'tahap' => ['nullable', Rule::enum(TahapFoto::class)],
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
            ->with(self::RELASI)
            ->when($request->filled('pasien_id'), fn ($q) => $q->where('pasien_id', $request->integer('pasien_id')))
            ->when($request->filled('kunjungan_id'), fn ($q) => $q->where('kunjungan_id', $request->integer('kunjungan_id')))
            ->when($request->filled('kategori'), fn ($q) => $q->where('kategori', $request->input('kategori')))
            ->when($request->filled('protokol_foto_id'), fn ($q) => $q->where('protokol_foto_id', $request->integer('protokol_foto_id')))
            ->when($request->filled('posisi'), fn ($q) => $q->where('posisi', $request->input('posisi')))
            ->when($request->filled('tahap'), fn ($q) => $q->where('tahap', $request->input('tahap')))
            ->latest('id')
            ->limit(200)
            ->get()
            ->each(fn (Berkas $b) => $b->setAttribute('ada_thumbnail', $b->punyaThumbnail()));

        return response()->json($berkas);
    }

    public function store(Request $request, PersetujuanFotoService $persetujuanFoto): JsonResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:'.implode(',', config('eklinik.berkas.mimes')), 'max:'.config('eklinik.berkas.maks_kb')],
            'thumbnail' => ['nullable', 'file', 'mimes:jpg,jpeg', 'max:1024'],
            'kategori' => ['required', Rule::enum(KategoriBerkas::class)],
            'pasien_id' => ['required', Rule::exists('pasiens', 'id')->whereNull('deleted_at')],
            'kunjungan_id' => ['nullable', 'integer'],
            'kunjungan_tindakan_id' => ['nullable', 'integer'],
            'keterangan' => ['nullable', 'string', 'max:255'],
            // Metadata foto klinis (FT-01): protokol + posisi, tahap before-after, waktu & ukuran asli
            'protokol_foto_id' => ['nullable', Rule::exists('protokol_fotos', 'id')->whereNull('deleted_at')],
            'posisi' => ['nullable', 'required_with:protokol_foto_id', 'string', 'max:30'],
            'tahap' => ['nullable', Rule::enum(TahapFoto::class)],
            'diambil_at' => ['nullable', 'date', 'before_or_equal:now'],
            'lebar' => ['nullable', 'integer', 'between:1,20000'],
            'tinggi' => ['nullable', 'integer', 'between:1,20000'],
        ]);

        $foto = $data['kategori'] === KategoriBerkas::FotoKlinis->value;

        // Consent foto bertingkat (FT-04): foto klinis butuh persetujuan pasien yang berlaku.
        if ($foto) {
            $persetujuanFoto->pastikanBolehFoto((int) $data['pasien_id']);
        }

        $cabangId = null;

        if ($data['kunjungan_id'] ?? null) {
            // Kunjungan harus milik cabang aktif, milik pasien yang sama, dan tidak batal.
            $kunjungan = Kunjungan::whereKey($data['kunjungan_id'])->first();

            if (! $kunjungan || (int) $kunjungan->pasien_id !== (int) $data['pasien_id'] || $kunjungan->status === StatusKunjungan::Batal) {
                throw ValidationException::withMessages(['kunjungan_id' => 'Kunjungan tidak valid untuk pasien ini.']);
            }

            if (($data['kunjungan_tindakan_id'] ?? null) && ! $kunjungan->tindakans()->whereKey($data['kunjungan_tindakan_id'])->exists()) {
                throw ValidationException::withMessages(['kunjungan_tindakan_id' => 'Tindakan tidak ada di kunjungan ini.']);
            }

            $cabangId = $kunjungan->cabang_id;
        } elseif ($data['kunjungan_tindakan_id'] ?? null) {
            throw ValidationException::withMessages(['kunjungan_tindakan_id' => 'Pilih kunjungan dari tindakan ini.']);
        }

        if ($data['protokol_foto_id'] ?? null) {
            if (! in_array($data['posisi'], ProtokolFoto::findOrFail($data['protokol_foto_id'])->kodePosisi(), true)) {
                throw ValidationException::withMessages(['posisi' => 'Posisi tidak ada di protokol foto ini.']);
            }
        }

        $berkas = $this->berkas->simpan($request->file('file'), [
            'pasien_id' => (int) $data['pasien_id'],
            'kunjungan_id' => $data['kunjungan_id'] ?? null,
            'kunjungan_tindakan_id' => $data['kunjungan_tindakan_id'] ?? null,
            'cabang_id' => $cabangId,
            'kategori' => $data['kategori'],
            'keterangan' => $data['keterangan'] ?? null,
            // Metadata foto hanya bermakna untuk kategori foto klinis
            'protokol_foto_id' => $foto ? ($data['protokol_foto_id'] ?? null) : null,
            'posisi' => $foto ? ($data['posisi'] ?? null) : null,
            'tahap' => $foto ? ($data['tahap'] ?? null) : null,
            'diambil_at' => $foto ? ($data['diambil_at'] ?? now()) : null,
            'lebar' => $data['lebar'] ?? null,
            'tinggi' => $data['tinggi'] ?? null,
        ], $request->user(), $request->file('thumbnail'));

        $berkas->load(self::RELASI)->setAttribute('ada_thumbnail', $berkas->punyaThumbnail());

        return response()->json($berkas, 201);
    }

    /** Tautan unduh bertanda tangan yang berlaku beberapa menit. `?pratinjau=1` = thumbnail. Tercatat di audit log. */
    public function tautan(Request $request, Berkas $berkas): JsonResponse
    {
        if ($berkas->kunjungan_id) {
            abort_unless($this->rekamMedis->bolehLihat($request->user(), $berkas->kunjungan), 403,
                'Berkas ini milik rekam medis berakses terbatas.');
        }

        return response()->json($this->berkas->tautan($berkas, $request->user(), $request->boolean('pratinjau')));
    }

    /**
     * Tautan banyak berkas sekaligus (galeri & perbandingan before-after, FT-02). Berkas yang tidak boleh dibuka
     * (akses terbatas) atau tidak ada dilewati. Setiap tautan tercatat di audit log.
     */
    public function tautanBanyak(Request $request): JsonResponse
    {
        $data = $request->validate([
            'uuids' => ['required', 'array', 'min:1', 'max:60'],
            'uuids.*' => ['uuid'],
            'pratinjau' => ['boolean'],
        ]);

        $user = $request->user();

        $tautan = Berkas::with('kunjungan')
            ->whereIn('uuid', $data['uuids'])
            ->get()
            ->filter(fn (Berkas $b) => ! $b->kunjungan_id || $this->rekamMedis->bolehLihat($user, $b->kunjungan))
            ->map(fn (Berkas $b) => $this->berkas->tautan($b, $user, (bool) ($data['pratinjau'] ?? false)))
            ->values();

        return response()->json($tautan);
    }

    /**
     * Isi berkas. Tanpa header Authorization (dipakai <img src> / tab baru); keabsahan dijamin tanda tangan URL
     * yang memuat id pengguna penerbit tautan. `t=1` (ikut ditandatangani) = thumbnail.
     */
    public function unduh(Request $request, Berkas $berkas, AuditService $audit): Response
    {
        $user = User::find($request->integer('u'));
        abort_unless($user?->is_active, 403, 'Tautan tidak berlaku.');

        $thumbnail = $request->boolean('t') && $berkas->punyaThumbnail();
        $isi = $this->berkas->isi($berkas, $thumbnail);

        // Pratinjau galeri tidak dicatat dua kali (permintaan tautannya sudah tercatat `akses_berkas`).
        if (! $thumbnail) {
            $audit->catat('unduh_berkas', 'berkas', $berkas->id, [
                'user_id' => $user->id, 'cabang_id' => $berkas->cabang_id,
                'pasien_id' => $berkas->pasien_id, 'label' => $berkas->auditLabel(),
            ]);
        }

        return response($isi, 200, [
            'Content-Type' => $thumbnail ? 'image/jpeg' : $berkas->mime,
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
