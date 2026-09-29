<?php

namespace App\Http\Controllers\Api;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $users = $this->filterAktif(User::query(), $request)
            ->select(['id', 'name', 'email', 'role', 'poli_id', 'sip', 'is_active'])
            ->with('poli:id,nama')
            ->when($request->filled('role'), fn ($q) => $q->where('role', $request->input('role')))
            ->when($request->filled('poli_id'), fn ($q) => $q->where('poli_id', $request->integer('poli_id')))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('name', "%{$q}%")->orWhereLike('email', "%{$q}%"));
            })
            ->orderBy('name');

        return response()->json($this->paginate($users, $request));
    }

    /**
     * Daftar dokter aktif (untuk pilihan saat pendaftaran). Dapat diakses semua role.
     */
    public function dokter(Request $request): JsonResponse
    {
        $dokters = User::dokter()
            ->when($request->filled('poli_id'), fn ($q) => $q->where('poli_id', $request->integer('poli_id')))
            ->orderBy('name')
            ->get(['id', 'name', 'poli_id', 'sip']);

        return response()->json($dokters);
    }

    public function store(Request $request): JsonResponse
    {
        $user = User::create($this->validated($request));

        return response()->json($user->load('poli:id,nama'), 201);
    }

    public function show(User $user): JsonResponse
    {
        return response()->json($user->load('poli:id,nama'));
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $data = $this->validated($request, $user);

        if (empty($data['password'])) {
            unset($data['password']);
        }

        abort_if($user->is($request->user()) && (($data['is_active'] ?? true) === false || $data['role'] !== Role::Admin->value),
            422, 'Anda tidak dapat menonaktifkan atau menurunkan role akun Anda sendiri.');

        $user->update($data);

        return response()->json($user->load('poli:id,nama'));
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_if($user->is($request->user()), 422, 'Anda tidak dapat menghapus akun Anda sendiri.');

        $user->tokens()->delete();
        $user->delete();

        return response()->json(['message' => 'User dihapus.']);
    }

    private function validated(Request $request, ?User $user = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'password' => [$user ? 'nullable' : 'required', 'string', Password::min(8)],
            'role' => ['required', Rule::enum(Role::class)],
            'poli_id' => ['nullable', 'required_if:role,dokter', 'exists:polis,id'],
            'sip' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);
    }
}
