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


    public function getContacts(int $pageSize = 100, int $pageNumber = 1): array
    {
        $url = sprintf(
            '%s/%s/api/v1/getContacts',
            $this->baseUrl,
            $this->tenantId,
        );

        $res = Http::timeout(20)
            ->retry(2, 300)
            ->withHeaders([
                'Authorization' => 'Bearer ' . $this->token,
                'accept' => 'application/json',
            ])
            ->get($url, [
                'pageSize' => max(1, min($pageSize, 500)),
                'pageNumber' => max(1, $pageNumber),
            ]);

        if (!$res->successful()) {
            throw new RuntimeException('Error en WATI (' . $res->status() . '): ' . substr($res->body(), 0, 300));
        }

        $payload = $res->json();

        if (!is_array($payload)) {
            return ['contacts' => [], 'has_more' => false, 'raw' => $res->body()];
        }

        $contacts = $payload['contacts'] ?? $payload['result'] ?? $payload['data'] ?? [];
        if (!is_array($contacts)) {
            $contacts = [];
        }

        $hasMore = (bool) ($payload['hasMore'] ?? $payload['has_more'] ?? false);

        return [
            'contacts' => array_values(array_filter($contacts, fn ($item) => is_array($item))),
            'has_more' => $hasMore,
            'raw' => $payload,
        ];
    }
}
