<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotProductoControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        putenv('ODOO_URL=https://odoo.test');
        putenv('ODOO_DB=test_db');
        putenv('ODOO_USERNAME=test_user');
        putenv('ODOO_PASSWORD=test_pass');
        putenv('ODOO_STORE_LOCATION_IDS');
    }

    public function test_buscar_producto_actualiza_custom_param_productos_en_wati(): void
    {
        putenv('WATI_BASE_URL=https://wati.test');
        putenv('WATI_TENANT_ID=tenant123');
        putenv('WATI_TOKEN=token123');
        putenv('WATI_SOURCE_TYPE=Wati');

        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->nameSearchXml(), 200)
                ->push($this->productReadXml(), 200),
            'https://wati.test/tenant123/api/v1/updateContactAttributes/*' => Http::response(['ok' => true], 200),
        ]);

        $response = $this->getJson('/api/buscar-producto?nombre=acetaminofen&whatsappNumber=584001112233');

        $response
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Acetaminofen 500mg')
            ->assertJsonPath('0.price', 19.9)
            ->assertJsonPath('1.name', 'Acetaminofen Infantil')
            ->assertJsonPath('1.price', 12.4);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/updateContactAttributes/584001112233')
                && $request['customParams'][0]['name'] === 'productos'
                && $request['customParams'][0]['value'] === 'Acetaminofen 500mg, Acetaminofen Infantil';
        });
    }

    public function test_buscar_producto_no_actualiza_wati_si_no_hay_whatsapp_number(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->nameSearchXml(), 200)
                ->push($this->productReadXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto?nombre=acetaminofen');

        $response->assertOk()->assertJsonCount(2);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'updateContactAttributes'));
    }

    public function test_buscar_producto_suma_stock_solo_en_depositos_configurados(): void
    {
        putenv('ODOO_STORE_LOCATION_IDS=11,12');

        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->nameSearchXml(), 200)
                ->push($this->productReadXml(), 200)
                ->push($this->stockQuantByLocationsXml(7, 3), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto?nombre=acetaminofen');

        $response
            ->assertOk()
            ->assertJsonPath('0.qty_available', 7)
            ->assertJsonPath('1.qty_available', 3);
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

    private function nameSearchXml(): string
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
              <array>
                <data>
                  <value><int>501</int></value>
                  <value><string>Acetaminofen 500mg</string></value>
                </data>
              </array>
            </value>
            <value>
              <array>
                <data>
                  <value><int>502</int></value>
                  <value><string>Acetaminofen Infantil</string></value>
                </data>
              </array>
            </value>
          </data>
        </array>
      </value>
    </param>
  </params>
</methodResponse>
XML;
    }

    private function productReadXml(): string
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
                <member><name>id</name><value><int>501</int></value></member>
                <member><name>name</name><value><string>Acetaminofen 500mg</string></value></member>
                <member><name>default_code</name><value><string>ACE500</string></value></member>
                <member><name>qty_available</name><value><double>11</double></value></member>
                <member><name>lst_price</name><value><double>19.9</double></value></member>
                <member><name>barcode</name><value><string>12345</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>502</int></value></member>
                <member><name>name</name><value><string>Acetaminofen Infantil</string></value></member>
                <member><name>default_code</name><value><string>ACEINF</string></value></member>
                <member><name>qty_available</name><value><double>4</double></value></member>
                <member><name>lst_price</name><value><double>12.4</double></value></member>
                <member><name>barcode</name><value><string>67890</string></value></member>
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

    private function stockQuantByLocationsXml(float $qty501, float $qty502): string
    {
        return <<<XML
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value>
        <array>
          <data>
            <value>
              <struct>
                <member><name>product_id</name>
                  <value>
                    <array>
                      <data>
                        <value><int>501</int></value>
                        <value><string>Acetaminofen 500mg</string></value>
                      </data>
                    </array>
                  </value>
                </member>
                <member><name>available_quantity</name><value><double>{$qty501}</double></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>product_id</name>
                  <value>
                    <array>
                      <data>
                        <value><int>502</int></value>
                        <value><string>Acetaminofen Infantil</string></value>
                      </data>
                    </array>
                  </value>
                </member>
                <member><name>available_quantity</name><value><double>{$qty502}</double></value></member>
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
}
