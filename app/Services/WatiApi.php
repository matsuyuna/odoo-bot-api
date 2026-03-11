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
        $tenantId = trim((string) env('WATI_TENANT_ID', ''));
        $token = trim((string) env('WATI_TOKEN', ''));
        $sourceType = (string) env('WATI_SOURCE_TYPE', 'Wati');

        if (str_starts_with(strtolower($token), 'bearer ')) {
            $token = trim(substr($token, 7));
        }

        if (!$tenantId) {
            $tenantId = self::extractTenantIdFromBaseUrl($baseUrl);
        }

        if (!$baseUrl || !$tenantId || !$token) {
            throw new RuntimeException('Faltan variables de entorno de WATI (WATI_BASE_URL, WATI_TENANT_ID, WATI_TOKEN).');
        }

        return new self($baseUrl, $tenantId, $token, $sourceType);
    }

    public function addContact(string $phone, string $name, array $customParams = []): array
    {
        $url = sprintf(
            '%s/api/v1/addContact/%s?sourceType=%s',
            $this->resolveTenantBaseUrl(),
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
            '%s/api/v1/updateContactAttributes/%s?sourceType=%s',
            $this->resolveTenantBaseUrl(),
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
            '%s/api/v1/getContacts',
            $this->resolveTenantBaseUrl(),
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

        $contacts = $this->extractContacts($payload);
        $hasMore = $this->extractHasMore($payload, $contacts, $pageSize);

        return [
            'contacts' => array_values(array_filter($contacts, fn ($item) => is_array($item))),
            'has_more' => $hasMore,
            'raw' => $payload,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,array<string,mixed>>
     */
    private function extractContacts(array $payload): array
    {
        $candidates = [
            $payload['contacts'] ?? null,
            $payload['contact_list'] ?? null,
            $payload['contactList'] ?? null,
            $payload['result'] ?? null,
            $payload['data'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $rows = $this->extractContactRows($candidate);
            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @param mixed $candidate
     * @return array<int,array<string,mixed>>
     */
    private function extractContactRows(mixed $candidate): array
    {
        if (!is_array($candidate)) {
            return [];
        }

        if ($this->isSequentialArray($candidate)) {
            return array_values(array_filter($candidate, fn ($item) => is_array($item)));
        }

        foreach (['contacts', 'contact_list', 'contactList', 'items', 'rows', 'results', 'data'] as $key) {
            if (!array_key_exists($key, $candidate)) {
                continue;
            }

            $rows = $this->extractContactRows($candidate[$key]);
            if ($rows !== []) {
                return $rows;
            }
        }

        return [];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<int,array<string,mixed>> $contacts
     */
    private function extractHasMore(array $payload, array $contacts, int $pageSize): bool
    {
        foreach (['hasMore', 'has_more', 'hasNextPage', 'has_next_page'] as $key) {
            if (array_key_exists($key, $payload)) {
                return (bool) $payload[$key];
            }
        }

        foreach (['result', 'data'] as $key) {
            $container = $payload[$key] ?? null;
            if (!is_array($container)) {
                continue;
            }

            foreach (['hasMore', 'has_more', 'hasNextPage', 'has_next_page'] as $nestedKey) {
                if (array_key_exists($nestedKey, $container)) {
                    return (bool) $container[$nestedKey];
                }
            }
        }

        return count($contacts) >= $pageSize;
    }

    /**
     * @param array<int|string,mixed> $items
     */
    private function isSequentialArray(array $items): bool
    {
        return array_keys($items) === range(0, count($items) - 1);
    }

    private function resolveTenantBaseUrl(): string
    {
        $normalizedBaseUrl = rtrim($this->baseUrl, '/');

        if (str_ends_with($normalizedBaseUrl, '/' . $this->tenantId)) {
            return $normalizedBaseUrl;
        }

        return $normalizedBaseUrl . '/' . $this->tenantId;
    }

    private static function extractTenantIdFromBaseUrl(string $baseUrl): string
    {
        $path = trim((string) parse_url($baseUrl, PHP_URL_PATH), '/');

        if ($path === '') {
            return '';
        }

        $parts = explode('/', $path);

        return trim((string) end($parts));
    }
}
