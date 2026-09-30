<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pengaturan klinik (key-value). Definisi & default: config('eklinik.pengaturan').
 * Baca dengan `app(PengaturanService::class)->get('klinik.nama')`; nilai tersimpan di-cache sampai diubah.
 */
class PengaturanService
{
    private const CACHE_KEY = 'eklinik:pengaturan';

    /** @var array<string, mixed>|null nilai tersimpan (tanpa default), memo per request */
    private ?array $tersimpan = null;

    /** @return array<string, array{default: mixed, rules: list<string>, publik: bool, item_rules?: list<string>}> */
    public function definisi(): array
    {
        return config('eklinik.pengaturan');
    }

    public function get(string $kunci): mixed
    {
        $tersimpan = $this->tersimpan();

        return array_key_exists($kunci, $tersimpan) ? $tersimpan[$kunci] : ($this->definisi()[$kunci]['default'] ?? null);
    }

    /** Semua nilai efektif dalam bentuk bertingkat: ['klinik' => ['nama' => ...], ...]. */
    public function semua(bool $hanyaPublik = false): array
    {
        $nilai = [];

        foreach ($this->definisi() as $kunci => $def) {
            if (! $hanyaPublik || $def['publik']) {
                Arr::set($nilai, $kunci, $this->get($kunci));
            }
        }

        return $nilai;
    }

    /** Aturan validasi untuk payload bertingkat (hanya kunci yang dikirim yang divalidasi). */
    public function aturanValidasi(): array
    {
        $rules = [];

        foreach ($this->definisi() as $kunci => $def) {
            $rules[$kunci] = ['sometimes', ...$def['rules']];

            if (isset($def['item_rules'])) {
                $rules["{$kunci}.*"] = $def['item_rules'];
            }
        }

        return $rules;
    }

    /**
     * Simpan kunci yang ada di payload bertingkat (sudah divalidasi). Perubahan dicatat di audit log.
     */
    public function simpan(array $input, ?User $user = null): array
    {
        $baru = [];

        foreach (array_keys($this->definisi()) as $kunci) {
            if (Arr::has($input, $kunci)) {
                $baru[$kunci] = data_get($input, $kunci);
            }
        }

        $perubahan = collect($baru)
            ->filter(fn ($nilai, $kunci) => $nilai !== $this->get($kunci))
            ->map(fn ($nilai, $kunci) => ['lama' => $this->get($kunci), 'baru' => $nilai]);

        DB::transaction(function () use ($perubahan, $user) {
            foreach ($perubahan as $kunci => $nilai) {
                DB::table('pengaturans')->updateOrInsert(
                    ['kunci' => $kunci],
                    ['nilai' => json_encode($nilai['baru']), 'updated_by' => $user?->id, 'updated_at' => now()],
                );
            }

            if ($perubahan->isNotEmpty()) {
                app(AuditService::class)->catat('ubah', 'pengaturan', null, [
                    'label' => 'Pengaturan klinik',
                    'perubahan' => $perubahan->all(),
                ]);
            }
        });

        Cache::forget(self::CACHE_KEY);
        $this->tersimpan = null;

        return $this->semua();
    }

    private function tersimpan(): array
    {
        return $this->tersimpan ??= Cache::rememberForever(self::CACHE_KEY, fn () => DB::table('pengaturans')
            ->pluck('nilai', 'kunci')
            ->map(fn ($nilai) => json_decode($nilai, true))
            ->all());
    }
}
