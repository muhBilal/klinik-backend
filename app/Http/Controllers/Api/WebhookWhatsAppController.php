<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Webhook WhatsApp Cloud API (publik, tanpa login): verifikasi langganan (GET) & notifikasi status/balasan (POST).
 * POST wajib bertanda tangan X-Hub-Signature-256 = HMAC-SHA256(body, WHATSAPP_APP_SECRET).
 */
class WebhookWhatsAppController extends Controller
{
    public function verifikasi(Request $request): Response
    {
        $token = config('services.whatsapp.verify_token');
        abort_unless($request->query('hub_mode') === 'subscribe' && filled($token) && hash_equals($token, (string) $request->query('hub_verify_token')), 403);

        return response((string) $request->query('hub_challenge'), 200, ['Content-Type' => 'text/plain']);
    }

    public function terima(Request $request, WhatsAppService $service): JsonResponse
    {
        $rahasia = config('services.whatsapp.app_secret');
        $tanda = (string) $request->header('X-Hub-Signature-256');
        abort_unless(filled($rahasia) && hash_equals('sha256='.hash_hmac('sha256', $request->getContent(), $rahasia), $tanda), 403, 'Tanda tangan webhook tidak valid.');

        return response()->json($service->webhook($request->json()->all()));
    }
}
