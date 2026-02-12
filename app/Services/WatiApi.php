<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class WatiApi
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $tenantId,
        private readonly string $token,
        private readonly string $sourceType,
    ) {}

    public static function fromEnv(): self
    {
        $baseUrl = rtrim((string) env('WATI_BASE_URL', 'https://live-mt-server.wati.io'), '/');
        $tenantId = (string) env('WATI_TENANT_ID', '');
        $token = (string) env('WATI_TOKEN', '');
        $sourceType = (string) env('WATI_SOURCE_TYPE', 'Wati');

        if (!$baseUrl || !$tenantId || !$token) {
            throw new RuntimeException('Faltan variables de entorno de WATI (WATI_BASE_URL, WATI_TENANT_ID, WATI_TOKEN).');
        }

        return new self($baseUrl, $tenantId, $token, $sourceType);
    }

    public function addContact(string $phone, string $name, array $customParams = []): array
    {
        $url = sprintf(
            '%s/%s/api/v1/addContact/%s?sourceType=%s',
            $this->baseUrl,
            $this->tenantId,
            rawurlencode($phone),
            rawurlencode($this->sourceType)
        );

        $payload = [
            'name' => $name,
            'customParams' => $customParams,
        ];

        $res = Http::timeout(20)
            ->retry(2, 300)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'accept' => '*/*',
                'Content-Type' => 'application/json-patch+json',
            ])
            ->post($url, $payload);

        if (!$res->successful()) {
            throw new RuntimeException('Error en WATI (' . $res->status() . '): ' . substr($res->body(), 0, 300));
        }

        return [
            'status' => $res->status(),
            'body' => $res->json() ?? $res->body(),
        ];
    }

    public function updateContactAttributes(string $phone, array $customParams): array
    {
        $url = sprintf(
            '%s/%s/api/v1/updateContactAttributes/%s?sourceType=%s',
            $this->baseUrl,
            $this->tenantId,
            rawurlencode($phone),
            rawurlencode($this->sourceType)
        );

        $payload = [
            'customParams' => $customParams,
        ];

        $res = Http::timeout(20)
            ->retry(2, 300)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'accept' => '*/*',
                'Content-Type' => 'application/json-patch+json',
            ])
            ->post($url, $payload);

        if (!$res->successful()) {
            throw new RuntimeException('Error en WATI (' . $res->status() . '): ' . substr($res->body(), 0, 300));
        }

        return [
            'status' => $res->status(),
            'body' => $res->json() ?? $res->body(),
        ];
    }
}
