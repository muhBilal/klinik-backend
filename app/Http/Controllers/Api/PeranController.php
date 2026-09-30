<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
use App\Http\Controllers\Controller;
use App\Models\Peran;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Peran & izin (RBAC dinamis, PRD AD-02). Peran sistem tidak bisa dihapus dan kodenya tetap;
 * izin administrator (akses penuh) tidak bisa diubah.
 */
class PeranController extends Controller
{
    /** Daftar peran (array) + jumlah pengguna. Dipakai halaman Peran & pilihan peran di form pengguna. */
    public function index(): JsonResponse
    {
        return response()->json(
            Peran::with('izins')->withCount('users')->orderByDesc('is_sistem')->orderBy('nama')->get(),
        );
    }

    /** Katalog izin berkelompok untuk form peran. */
    public function izin(): JsonResponse
    {
        return response()->json(
            collect(Izin::cases())
                ->groupBy(fn (Izin $izin) => $izin->grup())
                ->map(fn ($items, $grup) => [
                    'grup' => $grup,
                    'izin' => $items->map(fn (Izin $izin) => ['kode' => $izin->value, 'label' => $izin->label()])->values(),
                ])
                ->values(),
        );
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $peran = DB::transaction(function () use ($data) {
            $peran = Peran::create($data);
            $peran->aturIzin($data['izin']);

            return $peran;
        });

        return response()->json($peran->load('izins')->loadCount('users'), 201);
    }

    public function update(Request $request, Peran $peran): JsonResponse
    {
        $data = $this->validated($request, $peran);

        DB::transaction(function () use ($peran, $data) {
            $peran->update($peran->is_sistem ? collect($data)->except('kode')->all() : $data);

            if (! $peran->akses_penuh) {
                $peran->aturIzin($data['izin']);
            }
        });

        return response()->json($peran->load('izins')->loadCount('users'));
    }

    public function destroy(Peran $peran): JsonResponse
    {
        abort_if($peran->is_sistem, 422, 'Peran sistem tidak dapat dihapus.');
        abort_if($peran->users()->withTrashed()->exists(), 422, 'Peran masih dipakai pengguna. Pindahkan penggunanya ke peran lain terlebih dahulu.');

        $peran->delete();

        return response()->json(['message' => 'Peran dihapus.']);
    }

    private function validated(Request $request, ?Peran $peran = null): array
    {
        return $request->validate([
            'kode' => [$peran?->is_sistem ? 'nullable' : 'required', 'string', 'max:30', 'regex:/^[a-z][a-z0-9_]*$/',
                Rule::unique('perans')->ignore($peran)],
            'nama' => ['required', 'string', 'max:100'],
            'deskripsi' => ['nullable', 'string', 'max:255'],
            'izin' => ['present', 'array'],
            'izin.*' => ['string', 'distinct', Rule::enum(Izin::class)],
        ], [
            'kode.regex' => 'Kode peran hanya huruf kecil, angka dan garis bawah, diawali huruf (mis. terapis_senior).',
        ]);
    }
}
