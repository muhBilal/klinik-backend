<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/** WhatsApp Cloud API (Meta Graph): POST /{versi}/{phone_number_id}/messages dengan pesan template. */
class CloudGateway implements WhatsAppGateway
{
    public function kirimTemplate(string $ke, string $template, array $parameter, array $tombol = []): string
    {
        $c = config('services.whatsapp');
        if (blank($c['token']) || blank($c['phone_number_id'])) {
            throw new WhatsAppException('Kredensial WhatsApp belum diisi (WHATSAPP_TOKEN / WHATSAPP_PHONE_NUMBER_ID).');
        }

        $komponen = [];
        if ($parameter) {
            $komponen[] = ['type' => 'body', 'parameters' => array_map(fn ($p) => ['type' => 'text', 'text' => (string) $p], $parameter)];
        }
        foreach (array_values($tombol) as $i => $payload) {
            $komponen[] = ['type' => 'button', 'sub_type' => 'quick_reply', 'index' => (string) $i, 'parameters' => [['type' => 'payload', 'payload' => $payload]]];
        }

        try {
            $res = Http::withToken($c['token'])->acceptJson()->timeout($c['timeout'])
                ->post(rtrim($c['base_url'], '/')."/{$c['api_version']}/{$c['phone_number_id']}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $ke,
                    'type' => 'template',
                    'template' => ['name' => $template, 'language' => ['code' => $c['bahasa']], 'components' => $komponen],
                ]);
        } catch (ConnectionException $e) {
            throw new WhatsAppException('Tidak dapat terhubung ke WhatsApp: '.$e->getMessage(), sementara: true);
        }

        if ($res->failed()) {
            $pesan = $res->json('error.message') ?? $res->body();
            throw new WhatsAppException("WhatsApp HTTP {$res->status()}: ".mb_substr((string) $pesan, 0, 500), sementara: $res->serverError() || $res->status() === 429);
        }

        return $res->json('messages.0.id') ?? throw new WhatsAppException('Respons WhatsApp tidak memuat id pesan.');
    }
}
