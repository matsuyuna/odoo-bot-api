<?php

namespace Tests\Feature;

use App\Services\WatiApi;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatiApiTest extends TestCase
{
    public function test_from_env_acepta_token_con_prefijo_bearer(): void
    {
        putenv('WATI_BASE_URL=https://wati.test');
        putenv('WATI_TENANT_ID=tenant123');
        putenv('WATI_TOKEN=Bearer token123');
        putenv('WATI_SOURCE_TYPE=Wati');

        Http::fake([
            'https://wati.test/tenant123/api/v1/getContacts*' => Http::response(['contacts' => []], 200),
        ]);

        $wati = WatiApi::fromEnv();
        $wati->getContacts();

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer token123'));
    }

    public function test_from_env_usa_base_url_con_tenant_embebido(): void
    {
        putenv('WATI_BASE_URL=https://wati.test/tenant123');
        putenv('WATI_TENANT_ID');
        putenv('WATI_TOKEN=token123');
        putenv('WATI_SOURCE_TYPE=Wati');

        Http::fake([
            'https://wati.test/tenant123/api/v1/getContacts*' => Http::response(['contacts' => []], 200),
        ]);

        $wati = WatiApi::fromEnv();
        $wati->getContacts();

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://wati.test/tenant123/api/v1/getContacts'));
    }

    public function test_get_contacts_acepta_payload_con_contact_list(): void
    {
        putenv('WATI_BASE_URL=https://wati.test');
        putenv('WATI_TENANT_ID=tenant123');
        putenv('WATI_TOKEN=token123');
        putenv('WATI_SOURCE_TYPE=Wati');

        Http::fake([
            'https://wati.test/tenant123/api/v1/getContacts*' => Http::response([
                'contact_list' => [
                    ['name' => 'Ana', 'phone' => '584111111111'],
                ],
            ], 200),
        ]);

        $result = WatiApi::fromEnv()->getContacts();

        $this->assertCount(1, $result['contacts']);
        $this->assertSame('Ana', $result['contacts'][0]['name']);
    }

    public function test_get_contacts_acepta_payload_con_result_contact_list_anidado(): void
    {
        putenv('WATI_BASE_URL=https://wati.test');
        putenv('WATI_TENANT_ID=tenant123');
        putenv('WATI_TOKEN=token123');
        putenv('WATI_SOURCE_TYPE=Wati');

        Http::fake([
            'https://wati.test/tenant123/api/v1/getContacts*' => Http::response([
                'result' => [
                    'contact_list' => [
                        ['name' => 'Luis', 'phone' => '584222222222'],
                    ],
                    'hasNextPage' => false,
                ],
            ], 200),
        ]);

        $result = WatiApi::fromEnv()->getContacts();

        $this->assertCount(1, $result['contacts']);
        $this->assertSame('Luis', $result['contacts'][0]['name']);
        $this->assertFalse($result['has_more']);
    }
}
