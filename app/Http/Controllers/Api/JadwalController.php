<?php

namespace App\Http\Controllers\Api;

use App\Enums\TipePengecualian;
use App\Http\Controllers\Controller;
use App\Models\JadwalPengecualian;
use App\Models\JadwalPraktik;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Jadwal praktik mingguan & pengecualian (cuti / jadwal tambahan) — PRD BK-03.
 */
class JadwalController extends Controller
{
    /** Jadwal rutin + pengecualian mendatang, dikelompokkan per petugas. */
    public function index(Request $request): JsonResponse
    {
        $request->validate(['user_id' => ['nullable', 'integer']]);

        $praktiks = JadwalPraktik::query()
            ->select(['id', 'cabang_id', 'user_id', 'hari', 'jam_mulai', 'jam_selesai', 'is_active'])
            ->with('user:id,name')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->orderBy('user_id')->orderBy('hari')->orderBy('jam_mulai')
            ->get();

        $pengecualians = JadwalPengecualian::query()
            ->select(['id', 'cabang_id', 'user_id', 'tanggal', 'tipe', 'jam_mulai', 'jam_selesai', 'keterangan'])
            ->with('user:id,name')
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->integer('user_id')))
            ->whereDate('tanggal', '>=', today()->subMonth())
            ->orderBy('tanggal')
            ->get();

        return response()->json(['praktiks' => $praktiks, 'pengecualians' => $pengecualians]);
    }

    public function store(Request $request, CabangAktif $cabang): JsonResponse
    {
        $data = $this->validatedPraktik($request);
        $cabangId = $cabang->untukDataBaru();

        $this->pastikanTidakTumpang($cabangId, $data);

        return response()->json(
            JadwalPraktik::create([...$data, 'cabang_id' => $cabangId])->load('user:id,name'), 201,
        );
    }

    public function update(Request $request, JadwalPraktik $jadwal): JsonResponse
    {
        $data = $this->validatedPraktik($request);

        $this->pastikanTidakTumpang($jadwal->cabang_id, $data, $jadwal->id);

        $jadwal->update($data);

        return response()->json($jadwal->load('user:id,name'));
    }

    public function destroy(JadwalPraktik $jadwal): JsonResponse
    {
        $jadwal->delete();

        return response()->json(['message' => 'Jadwal praktik dihapus.']);
    }

    public function storePengecualian(Request $request, CabangAktif $cabang): JsonResponse
    {
        $data = $this->validatedPengecualian($request);

        return response()->json(
            JadwalPengecualian::create([...$data, 'cabang_id' => $cabang->untukDataBaru()])->load('user:id,name'), 201,
        );
    }

    public function destroyPengecualian(JadwalPengecualian $pengecualian): JsonResponse
    {
        $pengecualian->delete();

        return response()->json(['message' => 'Pengecualian jadwal dihapus.']);
    }

    private function validatedPraktik(Request $request): array
    {
        return $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'hari' => ['required', 'integer', 'between:0,6'],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i', 'after:jam_mulai'],
            'is_active' => ['boolean'],
        ]);
    }

    private function validatedPengecualian(Request $request): array
    {
        $data = $request->validate([
            'user_id' => ['required', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'tanggal' => ['required', 'date'],
            'tipe' => ['required', Rule::enum(TipePengecualian::class)],
            'jam_mulai' => ['nullable', 'date_format:H:i', 'required_if:tipe,tambahan'],
            'jam_selesai' => ['nullable', 'date_format:H:i', 'after:jam_mulai', 'required_with:jam_mulai'],
            'keterangan' => ['nullable', 'string', 'max:255'],
        ]);

        // Cuti sehari penuh = kedua jam kosong; jadwal tambahan wajib punya rentang jam.
        if ($data['tipe'] === TipePengecualian::Tambahan->value && empty($data['jam_selesai'])) {
            throw ValidationException::withMessages(['jam_selesai' => 'Jadwal tambahan wajib punya jam selesai.']);
        }

        return $data;
    }

    /** Satu petugas tidak boleh punya dua jadwal rutin yang beririsan di hari yang sama. */
    private function pastikanTidakTumpang(int $cabangId, array $data, ?int $kecuali = null): void
    {
        $tumpang = JadwalPraktik::withoutGlobalScope('cabang')
            ->where('cabang_id', $cabangId)
            ->where('user_id', $data['user_id'])
            ->where('hari', $data['hari'])
            ->when($kecuali, fn ($q) => $q->whereKeyNot($kecuali))
            ->where('jam_mulai', '<', $data['jam_selesai'])
            ->where('jam_selesai', '>', $data['jam_mulai'])
            ->exists();

        if ($tumpang) {
            throw ValidationException::withMessages(['jam_mulai' => 'Jadwal ini beririsan dengan jadwal praktik lain di hari yang sama.']);
        }
    }
}
