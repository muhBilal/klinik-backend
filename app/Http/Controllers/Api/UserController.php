<?php

namespace App\Http\Controllers\Api;

use App\Enums\Izin;
use App\Http\Controllers\Controller;
use App\Models\Peran;
use App\Models\User;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    private const RELASI = ['poli:id,nama', 'cabang:id,kode,nama', 'peran:id,kode,nama'];

    public function index(Request $request): JsonResponse
    {
        $users = $this->filterAktif(User::query(), $request)
            ->select(['id', 'name', 'email', 'role', 'poli_id', 'cabang_id', 'sip', 'is_active', 'two_factor_confirmed_at'])
            ->with(self::RELASI)
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->filled('poli_id'), fn ($q) => $q->where('poli_id', $request->integer('poli_id')))
            ->when($request->filled('cabang_id'), fn ($q) => $q->where('cabang_id', $request->integer('cabang_id')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('name', "%{$q}%")->orWhereLike('email', "%{$q}%"));
            })
            ->orderBy('name');

        return response()->json($this->paginate($users, $request));
    }

    /**
     * Daftar dokter aktif (untuk pilihan saat pendaftaran). Dapat diakses semua role.
     * Bila ada cabang aktif: dokter cabang itu + dokter lintas cabang.
     */
    public function dokter(Request $request, CabangAktif $cabang): JsonResponse
    {
        $dokters = User::dokter()
            ->when($request->filled('poli_id'), fn ($q) => $q->where('poli_id', $request->integer('poli_id')))
            ->when($cabang->id(), fn ($q, $id) => $q->where(fn ($w) => $w->where('cabang_id', $id)->orWhereNull('cabang_id')))
            ->orderBy('name')
            ->get(['id', 'name', 'poli_id', 'cabang_id', 'sip']);

        return response()->json($dokters);
    }

    public function store(Request $request): JsonResponse
    {
        $user = User::create($this->validated($request));

        return response()->json($user->load(self::RELASI), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load(self::RELASI));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        abort_if($user->is($request->user()) && (($data['is_active'] ?? true) === false || $data['role'] !== $user->role),
            422, 'Anda tidak dapat menonaktifkan atau mengubah peran akun Anda sendiri.');

        $user->update($data);

        if (! $user->is($request->user()) && $user->wasChanged(['role', 'is_active', 'cabang_id', 'password'])) {
            // Hak akses / password diubah admin -> paksa pengguna itu login ulang
            $user->tokens()->delete();
        }

        return response()->json($user->load(self::RELASI));
    }

    /** Soft delete: jejak nama pengguna di rekam medis & audit tetap terbaca. */
    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422, 'Anda tidak dapat menghapus akun Anda sendiri.');

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User dihapus.']);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        $peranDokter = fn () => (bool) Peran::with('izins')->firstWhere('kode', $request->input('role'))
            ?->punya(Izin::PemeriksaanDokter);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::min(8)],
            'role' => ['required', 'string', Rule::exists('perans', 'kode')],
            'poli_id' => ['nullable', Rule::requiredIf($peranDokter), Rule::exists('polis', 'id')->whereNull('deleted_at')],
            'cabang_id' => ['nullable', Rule::exists('cabangs', 'id')->whereNull('deleted_at')],
            'sip' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ], [
            'poli_id.required' => 'Poli wajib diisi untuk peran yang bertugas sebagai dokter.',
        ]);
    }
}
