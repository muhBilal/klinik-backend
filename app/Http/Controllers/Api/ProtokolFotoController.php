<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProtokolFoto;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Protokol foto klinis (PRD FT-01): posisi standar yang diambil berurutan. Dibaca semua pengguna login, dikelola master.kelola.
 */
class ProtokolFotoController extends Controller
{
    /** **Array**. `aktif=1` untuk pilihan saat mengambil foto; tanpa filter + `tindakans_count`. */
    public function index(Request $request): JsonResponse
    {
        $protokols = $this->filterAktif(ProtokolFoto::query(), $request)
            ->when($request->boolean('aktif'), fn ($q) => $q->where('is_active', true))
            ->when(! $request->boolean('aktif'), fn ($q) => $q->withCount('tindakans'))
            ->when($request->filled('q'), fn ($q) => $q->whereLike('nama', '%'.$request->string('q')->trim().'%'))
            ->orderBy('id')
            ->get();

        return response()->json($protokols);
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(ProtokolFoto::create($this->validated($request)), 201);
    }

    public function update(Request $request, ProtokolFoto $protokolFoto): JsonResponse
    {
        $protokolFoto->update($this->validated($request));

        return response()->json($protokolFoto);
    }

    /** Soft delete: foto lama tetap menampilkan nama protokol & posisinya. */
    public function destroy(ProtokolFoto $protokolFoto): JsonResponse
    {
        abort_if($protokolFoto->tindakans()->exists(), 422,
            'Protokol masih dipasang ke treatment. Lepaskan dari treatment terlebih dahulu atau nonaktifkan saja.');

        $protokolFoto->delete();

        return response()->json(['message' => 'Protokol foto dihapus.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'deskripsi' => ['nullable', 'string', 'max:255'],
            'posisi' => ['required', 'array', 'min:1', 'max:20'],
            'posisi.*.kode' => ['nullable', 'string', 'max:30'],
            'posisi.*.label' => ['required', 'string', 'max:50'],
            'posisi.*.petunjuk' => ['nullable', 'string', 'max:255'],
            'is_active' => ['boolean'],
        ]);

        // Kode posisi = kunci perbandingan before-after; dibentuk dari label bila kosong dan harus unik dalam protokol.
        $data['posisi'] = array_map(fn ($p) => [
            'kode' => Str::slug(($p['kode'] ?? '') ?: $p['label'], '_'),
            'label' => $p['label'],
            'petunjuk' => $p['petunjuk'] ?? null,
        ], $data['posisi']);

        $kode = array_column($data['posisi'], 'kode');
        foreach (array_diff_assoc($kode, array_unique($kode)) as $i => $duplikat) {
            throw ValidationException::withMessages(["posisi.{$i}.kode" => "Kode posisi \"{$duplikat}\" dipakai lebih dari sekali."]);
        }

        return $data;
    }
}
