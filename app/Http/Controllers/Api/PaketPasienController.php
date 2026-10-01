<?php

namespace App\Http\Controllers\Api;

use App\Enums\MetodeBayar;
use App\Enums\StatusKunjungan;
use App\Http\Controllers\Controller;
use App\Models\Kunjungan;
use App\Models\KunjunganTindakan;
use App\Models\Paket;
use App\Models\PaketPasien;
use App\Models\Pasien;
use App\Services\PaketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Paket milik pasien (PRD TR-02): daftar & sisa sesi, dipesan dokter/terapis dari pemeriksaan (ditagihkan bersama kunjungan) atau
 * dijual langsung di kasir (tagihan mandiri), riwayat pemakaian, dan tindakan kebijakan (perpanjang masa berlaku, alihkan ke pasien
 * lain, refund sisa) yang butuh persetujuan manajer (`kasir.void`).
 */
class PaketPasienController extends Controller
{
    public function __construct(private PaketService $service) {}

    /**
     * **Array** terbaru dulu, + sisa per item & `status_efektif`. `aktif=1` = hanya yang bisa dipakai sekarang; `kunjungan_id=` ikut
     * menyertakan paket yang dipesan di kunjungan itu (belum ditagihkan, sesinya boleh dipakai di kunjungan tersebut).
     */
    public function index(Request $request, Pasien $pasien): JsonResponse
    {
        if ($request->boolean('aktif')) {
            return response()->json($this->service->aktif($pasien, $request->integer('kunjungan_id') ?: null));
        }

        $pakets = $pasien->paketPasiens()->with(PaketService::RELASI)->latest('id')->get()
            ->map(fn (PaketPasien $p) => $this->service->ringkas($p));

        return response()->json($pakets);
    }

    /** Jual paket ke pasien → 201 paket (menunggu bayar) + `tagihan` untuk dibayar di kasir. */
    public function store(Request $request, Pasien $pasien): JsonResponse
    {
        $data = $request->validate([
            'paket_id' => ['required', Rule::exists('pakets', 'id')->whereNull('deleted_at')],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);

        $paket = $this->service->jual($pasien, Paket::findOrFail($data['paket_id']), $data['catatan'] ?? null, $request->user());

        return response()->json($paket, 201);
    }

    /** Dokter/terapis memesankan paket dari pemeriksaan → 201 paket menunggu bayar (ditagihkan saat pemeriksaan ditutup). */
    public function pesan(Request $request, Kunjungan $kunjungan): JsonResponse
    {
        $data = $request->validate([
            'paket_id' => ['required', Rule::exists('pakets', 'id')->whereNull('deleted_at')],
            'catatan' => ['nullable', 'string', 'max:255'],
        ]);

        return response()->json($this->service->pesanDariKunjungan($kunjungan, Paket::findOrFail($data['paket_id']), $data['catatan'] ?? null,
            $request->user()), 201);
    }

    public function batalPesanan(Kunjungan $kunjungan, PaketPasien $paketPasien): JsonResponse
    {
        return response()->json($this->service->batalPesanan($paketPasien, $kunjungan));
    }

    /** Detail + riwayat pemakaian sesi (tindakan kunjungan bukan batal). */
    public function show(PaketPasien $paketPasien): JsonResponse
    {
        $paket = $this->service->muat($paketPasien->load('pasien:id,no_rm,nama'));

        $pemakaian = KunjunganTindakan::whereIn('paket_pasien_item_id', $paket->items->pluck('id'))
            ->whereHas('kunjungan', fn ($q) => $q->where('status', '!=', StatusKunjungan::Batal->value))
            ->with(['tindakan:id,nama', 'petugas:id,name', 'kunjungan:id,cabang_id,no_registrasi,tanggal,status', 'kunjungan.cabang:id,nama'])
            ->orderBy('id')
            ->get(['id', 'kunjungan_id', 'tindakan_id', 'jumlah', 'petugas_id', 'paket_pasien_item_id']);

        return response()->json([...$paket->toArray(), 'pemakaian' => $pemakaian, 'refund_sisa' => $this->service->simulasiRefundSisa($paket)]);
    }

    public function perpanjang(Request $request, PaketPasien $paketPasien): JsonResponse
    {
        $data = $request->validate([
            'berlaku_sampai' => ['required', 'date', 'after_or_equal:today'],
            'alasan' => ['required', 'string', 'max:500'],
        ]);

        return response()->json($this->service->perpanjang($paketPasien, Carbon::parse($data['berlaku_sampai']), $data['alasan']));
    }

    public function alihkan(Request $request, PaketPasien $paketPasien): JsonResponse
    {
        $data = $request->validate([
            'pasien_id' => ['required', Rule::exists('pasiens', 'id')->whereNull('deleted_at')],
            'alasan' => ['required', 'string', 'max:500'],
        ]);

        return response()->json($this->service->alihkan($paketPasien, Pasien::findOrFail($data['pasien_id']), $data['alasan'], $request->user()), 201);
    }

    public function refund(Request $request, PaketPasien $paketPasien): JsonResponse
    {
        $data = $request->validate([
            'metode' => ['required', Rule::enum(MetodeBayar::class)->only([MetodeBayar::Tunai, MetodeBayar::Transfer])],
            'referensi' => ['nullable', 'string', 'max:100'],
            'alasan' => ['required', 'string', 'max:500'],
        ]);

        return response()->json($this->service->refundSisa($paketPasien, MetodeBayar::from($data['metode']), $data['referensi'] ?? null, $data['alasan'], $request->user()));
    }
}
