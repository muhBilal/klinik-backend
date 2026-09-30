<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
use App\Http\Controllers\Controller;
use App\Models\Cabang;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cabang klinik (PRD AD-01). Satu instalasi = satu organisasi klinik dengan banyak cabang.
 */
class CabangController extends Controller
{
    /**
     * Pemegang izin cabang.kelola: semua cabang (termasuk nonaktif) + jumlah pengguna.
     * Lainnya: cabang aktif yang boleh diakses (untuk pemilih cabang).
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->punyaIzin(Izin::CabangKelola)) {
            $cabangs = $this->filterAktif(Cabang::query(), $request)
                ->withCount('users')
                ->when($request->filled('q'), function ($query) use ($request) {
                    $q = $request->string('q')->trim();
                    $query->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhereLike('kode', "{$q}%"));
                })
                ->orderBy('nama')
                ->get();

            return response()->json($cabangs);
        }

        return response()->json(Cabang::where('is_active', true)
            ->when($user->cabang_id, fn ($q, $id) => $q->whereKey($id))
            ->orderBy('nama')
            ->get(['id', 'kode', 'nama', 'alamat', 'telepon']));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json(Cabang::create($this->validated($request)), 201);
    }

    public function show(Cabang $cabang): JsonResponse
    {
        return response()->json($cabang->loadCount('users'));
    }

    public function update(Request $request, Cabang $cabang): JsonResponse
    {
        $cabang->update($this->validated($request, $cabang));

        return response()->json($cabang);
    }

    public function destroy(Cabang $cabang): JsonResponse
    {
        abort_if($cabang->kunjungans()->exists() || $cabang->users()->exists(), 422,
            'Cabang sudah memiliki kunjungan atau pengguna. Nonaktifkan saja, jangan dihapus.');

        $cabang->delete();

        return response()->json(['message' => 'Cabang dihapus.']);
    }

    private function validated(Request $request, ?Cabang $cabang = null): array
    {
        $data = $request->validate([
            'kode' => ['required', 'string', 'max:10', 'regex:/^[A-Za-z0-9-]+$/', Rule::unique('cabangs')->ignore($cabang)],
            'nama' => ['required', 'string', 'max:100'],
            'alamat' => ['nullable', 'string', 'max:255'],
            'telepon' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:100'],
            'jam_buka' => ['nullable', 'date_format:H:i'],
            'jam_tutup' => ['nullable', 'date_format:H:i', 'after:jam_buka'],
            'is_active' => ['boolean'],
        ]);

        return ['kode' => strtoupper($data['kode'])] + $data;
    }
}
