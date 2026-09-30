<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ]);

        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'Akun Anda dinonaktifkan. Hubungi administrator.']);
        }

        $token = $user->createToken($credentials['device_name'] ?? 'spa')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->userPayload($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->userPayload($request->user()));
    }

    /**
     * Ubah profil sendiri. Role, poli & status aktif sengaja tidak dapat diubah di sini
     * (hanya lewat menu admin) agar pengguna tidak bisa menaikkan hak aksesnya sendiri.
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'sip' => ['nullable', 'string', 'max:50'],
            // Foto profil: data URI PNG/JPEG/WebP hasil perkecilan di browser (maks. ~200 KB setelah base64)
            'avatar' => ['nullable', 'string', 'max:262144', 'regex:/^data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+\/=]+$/'],
            'current_password' => ['required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', 'string', Password::min(8)],
        ]);

        if (empty($data['password'])) {
            unset($data['password'], $data['current_password']);
        } else {
            unset($data['current_password']);
        }

        $user->update($data);

        return response()->json($this->userPayload($user));
    }

    /**
     * Simpan preferensi tema tampilan milik pengguna sendiri.
     * Rentang nilai disamakan dengan `sanitize()` di frontend src/lib/theme.js.
     */
    public function updateTheme(Request $request): JsonResponse
    {
        $theme = $request->validate([
            'hue' => ['required', 'numeric', 'min:0', 'max:360'],
            'chroma' => ['required', 'numeric', 'min:0', 'max:1.4'],
            'depth' => ['required', 'numeric', 'min:0.38', 'max:0.68'],
        ]);

        $request->user()->update(['theme' => $theme]);

        return response()->json($theme);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Berhasil logout.']);
    }

    private function userPayload(User $user): array
    {
        $user->load('poli:id,kode,nama');

        return [...$user->toArray(), 'role_label' => $user->role->label()];
    }
}
