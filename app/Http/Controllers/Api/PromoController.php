<?php

namespace App\Http\Controllers\Api;

use App\Enums\JenisPotongan;
use App\Http\Controllers\Controller;
use App\Models\Cabang;
use App\Models\Paket;
use App\Models\Promo;
use App\Models\Tindakan;
use App\Services\PromoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/**
 * Voucher & kode promo (PRD TR-06). `dipakai` = pemakaian yang masih dihitung kuota.
 */
class PromoController extends Controller
{
    /** Paginated. `q` (kode/nama), `status` (aktif/nonaktif), `berlaku=1` (aktif & dalam periode hari ini). */
    public function index(Request $request): JsonResponse
    {
        $promos = $this->filterAktif(Promo::query(), $request)
            ->when($request->boolean('berlaku'), fn ($q) => $q->where('is_active', true)->whereDate('mulai', '<=', today())
                ->where(fn ($w) => $w->whereNull('berakhir')->orWhereDate('berakhir', '>=', today())))
            ->when($request->filled('q'), function ($query) use ($request) {
                $q = $request->string('q')->trim();
                $query->where(fn ($w) => $w->whereLike('nama', "%{$q}%")->orWhereLike('kode', "%{$q}%"));
            })
            ->withCount(['pemakaianBerlaku as dipakai'])
            ->latest('id');

        $hasil = $this->paginate($promos, $request);
        $this->lengkapi(collect($hasil->items()));

        return response()->json($hasil);
    }

    public function store(Request $request): JsonResponse
    {
        $promo = Promo::create([...$this->validated($request), 'created_by' => $request->user()->id]);

        return response()->json($this->lengkapi(collect([$promo->loadCount(['pemakaianBerlaku as dipakai'])]))->first(), 201);
    }

    public function show(Promo $promo): JsonResponse
    {
        return response()->json($this->lengkapi(collect([$promo->loadCount(['pemakaianBerlaku as dipakai'])]))->first());
    }

    public function update(Request $request, Promo $promo): JsonResponse
    {
        $promo->update($this->validated($request, $promo));

        return response()->json($this->lengkapi(collect([$promo->loadCount(['pemakaianBerlaku as dipakai'])]))->first());
    }

    /** Nama treatment / paket / cabang dari daftar id (untuk tampilan & form ubah). */
    private function lengkapi(Collection $promos): Collection
    {
        $ambil = fn (string $kolom, string $model) => $model::withTrashed()
            ->whereIn('id', $promos->pluck($kolom)->flatten()->filter()->unique())
            ->get(['id', 'nama'])->keyBy('id');
        $tindakan = $ambil('tindakan_ids', Tindakan::class);
        $paket = $ambil('paket_ids', Paket::class);
        $cabang = $ambil('cabang_ids', Cabang::class);

        return $promos->each(function (Promo $p) use ($tindakan, $paket, $cabang) {
            $p->setAttribute('tindakans', collect($p->tindakan_ids ?? [])->map(fn ($id) => $tindakan->get($id))->filter()->values());
            $p->setAttribute('pakets', collect($p->paket_ids ?? [])->map(fn ($id) => $paket->get($id))->filter()->values());
            $p->setAttribute('cabangs', collect($p->cabang_ids ?? [])->map(fn ($id) => $cabang->get($id))->filter()->values());
        });
    }

    public function destroy(Promo $promo): JsonResponse
    {
        abort_if($promo->pemakaians()->exists(), 422, 'Kode promo sudah pernah dipakai. Nonaktifkan saja, jangan dihapus.');

        $promo->delete();

        return response()->json(['message' => 'Kode promo dihapus.']);
    }

    private function validated(Request $request, ?Promo $promo = null): array
    {
        $request->merge(['kode' => PromoService::normalKode((string) $request->input('kode'))]);

        $data = $request->validate([
            'kode' => ['required', 'string', 'max:30', 'regex:/^[A-Z0-9_-]{3,30}$/', Rule::unique('promos')->ignore($promo)],
            'nama' => ['required', 'string', 'max:150'],
            'deskripsi' => ['nullable', 'string', 'max:2000'],
            'jenis' => ['required', Rule::enum(JenisPotongan::class)],
            'nilai' => ['required', 'integer', 'min:1', $request->input('jenis') === JenisPotongan::Persen->value ? 'max:100' : 'max:100000000'],
            'maks_potongan' => ['nullable', 'integer', 'min:1'],
            'min_transaksi' => ['nullable', 'integer', 'min:0'],
            'mulai' => ['required', 'date'],
            'berakhir' => ['nullable', 'date', 'after_or_equal:mulai'],
            'kuota' => ['nullable', 'integer', 'min:1'],
            'kuota_per_pasien' => ['nullable', 'integer', 'min:1'],
            'cabang_ids' => ['nullable', 'array'],
            'cabang_ids.*' => ['integer', 'distinct', Rule::exists('cabangs', 'id')->whereNull('deleted_at')],
            'tindakan_ids' => ['nullable', 'array'],
            'tindakan_ids.*' => ['integer', 'distinct', Rule::exists('tindakans', 'id')->whereNull('deleted_at')],
            'paket_ids' => ['nullable', 'array'],
            'paket_ids.*' => ['integer', 'distinct', Rule::exists('pakets', 'id')->whereNull('deleted_at')],
            'is_active' => ['boolean'],
        ], [
            'kode.regex' => 'Kode 3–30 karakter: huruf besar, angka, - atau _.',
        ]);

        // Daftar kosong = tidak dibatasi (disimpan null agar mudah dibaca).
        foreach (['cabang_ids', 'tindakan_ids', 'paket_ids'] as $kolom) {
            if (array_key_exists($kolom, $data)) {
                $data[$kolom] = $data[$kolom] ? array_values(array_map('intval', $data[$kolom])) : null;
            }
        }
        $data['min_transaksi'] = $data['min_transaksi'] ?? 0;
        if ($data['jenis'] === JenisPotongan::Nominal->value) {
            $data['maks_potongan'] = null;
        }

        return $data;
    }
}
