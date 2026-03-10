<?php

namespace Tests\Feature;

use App\Models\OdooContactSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncOdooContactsToQueueCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_guarda_producto_mas_comprado_vacio_si_cliente_no_tiene_compras(): void
    {
        putenv('ODOO_URL=https://odoo.test');
        putenv('ODOO_DB=test_db');
        putenv('ODOO_USERNAME=test_user');
        putenv('ODOO_PASSWORD=test_pass');

        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(7), 200),
            'https://odoo.test/xmlrpc/2/object' => function ($request) {
                $body = $request->body();

                if (str_contains($body, '<string>res.partner</string>') && str_contains($body, '<string>search_read</string>')) {
                    if (str_contains($body, '<name>offset</name><value><int>0</int></value>')) {
                        return Http::response($this->partnersXml(), 200);
                    }

                    return Http::response($this->emptyArrayXml(), 200);
                }

                if (str_contains($body, '<string>sale.order</string>') && str_contains($body, '<string>search_read</string>')) {
                    return Http::response($this->emptyArrayXml(), 200);
                }

                return Http::response($this->emptyArrayXml(), 200);
            },
        ]);

        $this->artisan('odoo:contacts:pull --batch-size=1 --max-total=1')->assertSuccessful();

        $this->assertDatabaseHas('odoo_contact_syncs', [
            'odoo_contact_id' => 101,
            'producto_mas_comprado' => '',
            'ultimo_producto_comprado' => '',
        ]);

        $record = OdooContactSync::query()->where('odoo_contact_id', 101)->firstOrFail();
        $this->assertSame('', $record->producto_mas_comprado);
        $this->assertSame('', $record->ultimo_producto_comprado);
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

    private function partnersXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value>
        <array>
          <data>
            <value>
              <struct>
                <member><name>id</name><value><int>101</int></value></member>
                <member><name>name</name><value><string>Cliente sin compras</string></value></member>
                <member><name>email</name><value><string>cliente@test.com</string></value></member>
                <member><name>phone</name><value><string>04244162964</string></value></member>
                <member><name>mobile</name><value><boolean>0</boolean></value></member>
                <member><name>vat</name><value><string>V-12345678</string></value></member>
                <member><name>is_company</name><value><boolean>0</boolean></value></member>
                <member><name>write_date</name><value><string>2026-03-10 10:00:00</string></value></member>
                <member><name>country_id</name><value><array><data><value><int>239</int></value><value><string>Venezuela</string></value></data></array></value></member>
              </struct>
            </value>
          </data>
        </array>
      </value>
    </param>
  </params>
</methodResponse>
XML;
    }

    private function emptyArrayXml(): string
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
}
