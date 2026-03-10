<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncWatiContactsToOdooCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_convierte_telefono_de_wati_a_formato_odoo_11_digitos(): void
    {
        putenv('WATI_BASE_URL=https://wati.test');
        putenv('WATI_TENANT_ID=tenant123');
        putenv('WATI_TOKEN=token123');
        putenv('WATI_SOURCE_TYPE=Wati');

        putenv('ODOO_URL=https://odoo.test');
        putenv('ODOO_DB=test_db');
        putenv('ODOO_USERNAME=test_user');
        putenv('ODOO_PASSWORD=test_pass');

        Http::fake([
            'https://wati.test/tenant123/api/v1/getContacts*' => Http::response([
                'contacts' => [
                    [
                        'name' => 'Cliente Wati',
                        'whatsappNumber' => '+584244162964',
                        'email' => 'cliente@wati.test',
                    ],
                ],
                'hasMore' => false,
            ], 200),
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(7), 200),
            'https://odoo.test/xmlrpc/2/object' => function ($request) {
                $body = $request->body();

                if (str_contains($body, '<string>search</string>')) {
                    return Http::response($this->searchEmptyXml(), 200);
                }

                return Http::response($this->createXml(901), 200);
            },
        ]);

        $this->artisan('wati:contacts:pull --page-size=10 --max-pages=1')
            ->expectsOutputToContain('Insertados: 1')
            ->expectsOutputToContain('creados en Odoo: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('odoo_contact_syncs', [
            'name' => 'Cliente Wati',
            'preferred_whatsapp' => '04244162964',
            'phone' => '04244162964',
            'mobile' => '04244162964',
            'odoo_contact_id' => 901,
        ]);
    }

    private function authXml(int $uid): string
    {
        return <<<XML
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value><int>{$uid}</int></value>
    </param>
  </params>
</methodResponse>
XML;
    }

    private function searchEmptyXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value><array><data></data></array></value>
    </param>
  </params>
</methodResponse>
XML;
    }

    private function createXml(int $id): string
    {
        return <<<XML
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value><int>{$id}</int></value>
    </param>
  </params>
</methodResponse>
XML;
    }
}
