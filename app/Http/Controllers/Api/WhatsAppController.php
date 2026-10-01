<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\KirimWhatsApp;
use App\Models\PesanWhatsapp;
use App\Services\WhatsApp\WhatsAppService;
use App\Support\CabangAktif;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Pemantauan pesan WhatsApp otomatis (BK-06, CR-01): status, daftar, kirim ulang, jalankan penjadwal. */
class WhatsAppController extends Controller
{
    public function status(CabangAktif $cabang, WhatsAppService $service): JsonResponse
    {
        $perStatus = PesanWhatsapp::query()
            ->when($cabang->id(), fn ($q, $id) => $q->where('cabang_id', $id))
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $c = config('services.whatsapp');

        return response()->json([
            'aktif' => $service->aktif(),
            'driver' => $c['driver'],
            'terkonfigurasi' => $c['driver'] === 'log' || (filled($c['token']) && filled($c['phone_number_id'])),
            'webhook_siap' => filled($c['verify_token']) && filled($c['app_secret']),
            'per_status_7_hari' => collect(['antre', 'terkirim', 'diterima', 'dibaca', 'gagal'])->mapWithKeys(fn ($s) => [$s => (int) ($perStatus[$s] ?? 0)]),
        ]);
    }

    public function index(Request $request, CabangAktif $cabang): JsonResponse
    {
        $request->validate(['status' => ['nullable', 'string'], 'jenis' => ['nullable', 'in:'.implode(',', PesanWhatsapp::JENIS)]]);

        $pesan = PesanWhatsapp::query()
            ->with(['pasien:id,no_rm,nama', 'appointment:id,no_booking,mulai_at,status,minta_ubah_at'])
            ->when($cabang->id(), fn ($q, $id) => $q->where('cabang_id', $id))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('jenis'), fn ($q) => $q->where('jenis', $request->input('jenis')))
            ->latest('id');

        return response()->json($this->paginate($pesan, $request, 25, 100));
    }

    public function ulang(PesanWhatsapp $pesan): JsonResponse
    {
        abort_unless(in_array($pesan->status, ['gagal', 'antre'], true), 422, 'Pesan ini sudah terkirim.');
        $pesan->update(['status' => 'antre', 'error' => null]);
        KirimWhatsApp::dispatch($pesan->id);

        return response()->json($pesan->refresh());
    }

    public function jadwalkan(WhatsAppService $service): JsonResponse
    {
        return response()->json(['dibuat' => $service->jadwalkan()]);
    }
}
