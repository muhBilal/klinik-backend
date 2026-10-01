<?php

namespace App\Services\SatuSehat;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Klien HTTP SATUSEHAT: OAuth2 client credentials (token di-cache sampai sebelum kedaluwarsa) + FHIR R4 JSON.
 * Konfigurasi `config('services.satusehat')`.
 */
class SatuSehatClient
{
    private const KUNCI_TOKEN = 'satusehat.token';

    public function terkonfigurasi(): bool
    {
        $c = config('services.satusehat');

        return filled($c['client_id']) && filled($c['client_secret']) && filled($c['organization_id']);
    }

    public function organizationId(): ?string
    {
        return config('services.satusehat.organization_id');
    }

    public function token(bool $baru = false): string
    {
        if ($baru) {
            Cache::forget(self::KUNCI_TOKEN);
        }

        return Cache::remember(self::KUNCI_TOKEN, now()->addMinutes(50), function () {
            if (! $this->terkonfigurasi()) {
                throw new SatuSehatException('Kredensial SATUSEHAT belum diisi (SATUSEHAT_CLIENT_ID / SECRET / ORGANIZATION_ID).');
            }

            $res = $this->kirim(fn () => Http::asForm()->timeout(config('services.satusehat.timeout'))
                ->post(config('services.satusehat.auth_url').'/accesstoken?grant_type=client_credentials', [
                    'client_id' => config('services.satusehat.client_id'),
                    'client_secret' => config('services.satusehat.client_secret'),
                ]));

            $token = $res->json('access_token');
            if (! $token) {
                throw new SatuSehatException('Respons token SATUSEHAT tidak memuat access_token.');
            }

            return $token;
        });
    }

    public function get(string $path, array $query = []): array
    {
        return $this->fhir('get', $path, $query);
    }

    public function post(string $path, array $body): array
    {
        return $this->fhir('post', $path, $body);
    }

    private function fhir(string $metode, string $path, array $data): array
    {
        $url = rtrim(config('services.satusehat.base_url'), '/').'/'.ltrim($path, '/');
        $panggil = fn (string $token) => Http::withToken($token)->acceptJson()->timeout(config('services.satusehat.timeout'))
            ->{$metode}($url, $data);

        $res = $this->kirim(fn () => $panggil($this->token()), lempar: false);

        // Token dicabut/kedaluwarsa lebih awal → minta token baru sekali
        if ($res->status() === 401) {
            $res = $this->kirim(fn () => $panggil($this->token(baru: true)), lempar: false);
        }

        if ($res->failed()) {
            throw new SatuSehatException($this->pesan($res), sementara: $res->serverError() || $res->status() === 429);
        }

        return $res->json() ?? [];
    }

    private function kirim(callable $permintaan, bool $lempar = true): Response
    {
        try {
            $res = $permintaan();
        } catch (ConnectionException $e) {
            throw new SatuSehatException('Tidak dapat terhubung ke SATUSEHAT: '.$e->getMessage(), sementara: true);
        }

        if ($lempar && $res->failed()) {
            throw new SatuSehatException($this->pesan($res), sementara: $res->serverError() || $res->status() === 429);
        }

        return $res;
    }

    /** Ringkas pesan galat FHIR (OperationOutcome) agar mudah dibaca petugas. */
    private function pesan(Response $res): string
    {
        $issue = collect($res->json('issue') ?? [])
            ->map(fn ($i) => $i['details']['text'] ?? $i['diagnostics'] ?? $i['code'] ?? null)
            ->filter()->implode('; ');

        return "SATUSEHAT HTTP {$res->status()}".($issue ? ": {$issue}" : ($res->json('error_description') ? ': '.$res->json('error_description') : ''));
    }
}
