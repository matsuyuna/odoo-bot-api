<?php

namespace Tests\Feature;

use App\Models\BcvRate;
use App\Services\OdooXmlRpc;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class BotProductoControllerTest extends TestCase
{
    use RefreshDatabase;

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



    public function test_inspeccionar_producto_devuelve_campos_y_posibles_precios(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->nameSearchXml(), 200)
                ->push($this->productFieldsGetXml(), 200)
                ->push($this->productInspectReadXml(), 200)
                ->push($this->productTemplateFieldsGetXml(), 200)
                ->push($this->productTemplateReadXml(), 200),
        ]);

        $response = $this->getJson('/api/inspeccionar-producto?nombre=acetaminofen');

        $response
            ->assertOk()
            ->assertJsonPath('id', 501)
            ->assertJsonPath('record.lst_price', 19.9)
            ->assertJsonPath('record.x_price_bs', 745.2)
            ->assertJsonPath('product_template.id', 701)
            ->assertJsonPath('product_template.record.name', 'Acetaminofen')
            ->assertJsonPath('price_candidates.lst_price.value', 19.9)
            ->assertJsonPath('price_candidates.x_price_bs.value', 745.2);
    }


    public function test_inspeccionar_producto_omite_campos_danados_y_no_falla(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->nameSearchXml(), 200)
                ->push($this->productFieldsGetWithBrokenFieldXml(), 200)
                ->push($this->xmlRpcFaultReadUnknownField(), 200)
                ->push($this->productInspectReadWithoutBrokenFieldXml(), 200),
        ]);

        $response = $this->getJson('/api/inspeccionar-producto?nombre=acetaminofen');

        $response
            ->assertOk()
            ->assertJsonPath('id', 501)
            ->assertJsonPath('record.lst_price', 19.9)
            ->assertJsonPath('record.x_broken_rel', null)
            ->assertJsonPath('unreadable_fields.0', 'x_broken_rel')
            ->assertJsonPath('price_candidates.lst_price.value', 19.9);
    }
    public function test_buscar_producto_actualiza_custom_param_productos_en_wati(): void
    {
        BcvRate::query()->create([
            'date' => '2026-03-04',
            'dollar' => 427.9302,
            'res_currency_rate' => 427.9302,
            'res_currency' => 427.9302,
        ]);

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
            ->assertJsonCount(1)
            ->assertJsonPath('availability_text', "- Acetaminofen 500mg Sí hay disponible Precio 8.516 bs\n\n- Acetaminofen Infantil Sí hay disponible Precio 5.306 bs");

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/updateContactAttributes/584001112233')
                && $request['customParams'][0]['name'] === 'productos'
                && $request['customParams'][0]['value'] === 'Acetaminofen 500mg, Acetaminofen Infantil';
        });
    }

    public function test_buscar_objcompleto_devuelve_respuesta_de_busqueda(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->nameSearchXml(), 200)
                ->push($this->productReadXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto-objcompleto?nombre=acetaminofen');

        $response
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Acetaminofen 500mg')
            ->assertJsonPath('0.default_code', 'ACE500')
            ->assertJsonPath('1.name', 'Acetaminofen Infantil');
    }

    public function test_buscar_objcompleto_excluye_productos_con_copiar_en_nombre_sin_importar_formato(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->nameSearchXml(), 200)
                ->push($this->productReadWithCopyVariantsXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto-objcompleto?nombre=acetaminofen');

        $response
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Acetaminofen 500mg')
            ->assertJsonPath('1.name', 'Acetaminofen Infantil');
    }

    public function test_buscar_objcompleto_prioriza_coincidencia_mas_cercana_por_similitud(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->emptyNameSearchXml(), 200)
                ->push($this->nameSearchTirzepatideXml(), 200)
                ->push($this->productReadTirzepatidaXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto-objcompleto?nombre=tirzepatide');

        $response
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Tirzepatida 5mg')
            ->assertJsonPath('1.name', 'Triprolidina Jarabe');
    }

    public function test_buscar_objcompleto_usa_fallback_por_prefijo_si_name_search_no_consigue(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->emptyNameSearchXml(), 200) // tirzepatide
                ->push($this->emptyNameSearchXml(), 200) // tirzepatida (variante)
                ->push($this->searchReadPrefixTirzepatidaXml(), 200) // fallback por prefijo
                ->push($this->productReadSingleTirzepatidaXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto-objcompleto?nombre=tirzepatide');

        $response
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.name', 'Tirzepatida 5mg');
    }

    public function test_buscar_objcompleto_prioriza_palabra_especifica_de_la_query(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->searchReadPhraseVitaminaCXml(), 200)
                ->push($this->nameSearchVitaminaXml(), 200)
                ->push($this->nameSearchVitaminaCXml(), 200)
                ->push($this->productReadVitaminasXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto-objcompleto?nombre=vitamina c');

        $response
            ->assertOk()
            ->assertJsonCount(4)
            ->assertJsonPath('0.default_code', 'VITAMINA C')
            ->assertJsonPath('1.name', 'Vitamina C 1g')
            ->assertJsonPath('2.name', 'Vitamina C con Zinc')
            ->assertJsonPath('3.name', 'Vitamina D 2000UI');
    }

    public function test_buscar_objcompleto_no_hace_name_search_para_token_unitario(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->searchReadPhraseVitaminaCXml(), 200)
                ->push($this->nameSearchVitaminaXml(), 200)
                ->push($this->productReadVitaminasXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto-objcompleto?nombre=vitamina c');

        $response
            ->assertOk()
            ->assertJsonPath('0.default_code', 'VITAMINA C')
            ->assertJsonPath('1.name', 'Vitamina C 1g');
    }

    public function test_buscar_objcompleto_prioriza_frase_completa_en_query_compuesta(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->searchReadPhraseAcetaminofen500Xml(), 200)
                ->push($this->nameSearchXml(), 200) // acetaminofen
                ->push($this->nameSearch500Xml(), 200) // 500
                ->push($this->productReadAcetaminofen500Xml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto-objcompleto?nombre=acetaminofen 500');

        $response
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.name', 'Acetaminofen 500mg')
            ->assertJsonPath('1.name', 'Acetaminofen 500mg Plus')
            ->assertJsonPath('2.name', 'Acetaminofen Infantil');
    }

    public function test_buscar_objcompleto_prioriza_codigo_exacto_500mg_sobre_similares(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->nameSearch500mgXml(), 200)
                ->push($this->productRead500mgRankingXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto-objcompleto?nombre=500mg');

        $response
            ->assertOk()
            ->assertJsonCount(3)
            ->assertJsonPath('0.default_code', '500MG')
            ->assertJsonPath('1.default_code', 'ACE500')
            ->assertJsonPath('2.default_code', 'JBE500');
    }

    public function test_tokenize_query_conserva_tokens_unitarios_relevantes_y_alfanumericos(): void
    {
        $service = OdooXmlRpc::fromEnv();
        $method = new ReflectionMethod(OdooXmlRpc::class, 'tokenizeQuery');
        $method->setAccessible(true);

        $vitaminaTokens = $method->invoke($service, 'vitamina c');
        $this->assertSame(['vitamina', 'c'], $vitaminaTokens);

        $doseTokens = $method->invoke($service, '500mg');
        $this->assertSame(['500mg', '500', 'mg'], $doseTokens);

        $b12Tokens = $method->invoke($service, 'b12');
        $this->assertSame(['b12', 'b', '12'], $b12Tokens);

        $d3Tokens = $method->invoke($service, 'd3');
        $this->assertSame(['d3', 'd', '3'], $d3Tokens);
    }

    public function test_token_matches_unitario_no_coincide_por_substring_libre(): void
    {
        $service = OdooXmlRpc::fromEnv();
        $method = new ReflectionMethod(OdooXmlRpc::class, 'tokenMatches');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($service, 'c', 'vitamina c 1g'));
        $this->assertFalse($method->invoke($service, 'c', 'calcibon vitamina d'));
        $this->assertFalse($method->invoke($service, 'c', 'calcio vitamina d'));
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

        $response->assertOk()->assertJsonCount(1)->assertJsonStructure(['availability_text']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'updateContactAttributes'));
    }

    public function test_buscar_producto_devuelve_mensaje_cuando_no_hay_resultados(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->emptyNameSearchXml(), 200)
                ->push($this->emptyNameSearchXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto?nombre=zzzz');

        $response
            ->assertOk()
            ->assertJsonPath('availability_text', 'No encontramos ningún producto bajo esa descripción');

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'updateContactAttributes'));
    }

    public function test_buscar_producto_excluye_productos_con_copiar_en_nombre_sin_importar_espacios_y_mayusculas(): void
    {
        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->nameSearchXml(), 200)
                ->push($this->productReadWithCopyVariantsXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto?nombre=acetaminofen');

        $response
            ->assertOk()
            ->assertJsonPath('availability_text', "- Acetaminofen 500mg Sí hay disponible Precio 20 bs\n\n- Acetaminofen Infantil Sí hay disponible Precio 12 bs");
    }

    public function test_buscar_producto_suma_stock_solo_en_depositos_configurados(): void
    {
        putenv('ODOO_STORE_LOCATION_IDS=11,12');

        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::sequence()
                ->push($this->nameSearchXml(), 200)
                ->push($this->productReadXml(), 200)
                ->push($this->productReadByLocationXml(3, 1), 200)
                ->push($this->productReadByLocationXml(4, 2), 200),
        ]);

        $response = $this->getJson('/api/buscar-producto-objcompleto?nombre=acetaminofen');

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

    private function nameSearchTirzepatideXml(): string
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
                  <value><int>700</int></value>
                  <value><string>Triprolidina Jarabe</string></value>
                </data>
              </array>
            </value>
            <value>
              <array>
                <data>
                  <value><int>701</int></value>
                  <value><string>Tirzepatida 5mg</string></value>
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

    private function nameSearchVitaminaXml(): string
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
                  <value><int>801</int></value>
                  <value><string>Vitamina D 2000UI</string></value>
                </data>
              </array>
            </value>
            <value>
              <array>
                <data>
                  <value><int>802</int></value>
                  <value><string>Vitamina C con Zinc</string></value>
                </data>
              </array>
            </value>
            <value>
              <array>
                <data>
                  <value><int>803</int></value>
                  <value><string>Vitamina C 1g</string></value>
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

    private function nameSearchVitaminaCXml(): string
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
                  <value><int>803</int></value>
                  <value><string>Vitamina C 1g</string></value>
                </data>
              </array>
            </value>
            <value>
              <array>
                <data>
                  <value><int>802</int></value>
                  <value><string>Vitamina C con Zinc</string></value>
                </data>
              </array>
            </value>
            <value>
              <array>
                <data>
                  <value><int>801</int></value>
                  <value><string>Vitamina D 2000UI</string></value>
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

    private function emptyNameSearchXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value>
        <array>
          <data>
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

    private function productReadTirzepatidaXml(): string
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
                <member><name>id</name><value><int>700</int></value></member>
                <member><name>name</name><value><string>Triprolidina Jarabe</string></value></member>
                <member><name>default_code</name><value><string>TRP500</string></value></member>
                <member><name>qty_available</name><value><double>5</double></value></member>
                <member><name>lst_price</name><value><double>10.0</double></value></member>
                <member><name>barcode</name><value><string>33333</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>701</int></value></member>
                <member><name>name</name><value><string>Tirzepatida 5mg</string></value></member>
                <member><name>default_code</name><value><string>TIR5</string></value></member>
                <member><name>qty_available</name><value><double>3</double></value></member>
                <member><name>lst_price</name><value><double>50.0</double></value></member>
                <member><name>barcode</name><value><string>44444</string></value></member>
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

    private function productReadWithCopyVariantsXml(): string
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
                <member><name>id</name><value><int>777</int></value></member>
                <member><name>name</name><value><string>Acetaminofen (COPIAR)</string></value></member>
                <member><name>default_code</name><value><string>ACECOP1</string></value></member>
                <member><name>qty_available</name><value><double>8</double></value></member>
                <member><name>lst_price</name><value><double>18.0</double></value></member>
                <member><name>barcode</name><value><string>55555</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>778</int></value></member>
                <member><name>name</name><value><string>Acetaminofen ( cOpIaR )</string></value></member>
                <member><name>default_code</name><value><string>ACECOP2</string></value></member>
                <member><name>qty_available</name><value><double>5</double></value></member>
                <member><name>lst_price</name><value><double>17.0</double></value></member>
                <member><name>barcode</name><value><string>66666</string></value></member>
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

    private function productReadVitaminasXml(): string
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
                <member><name>id</name><value><int>801</int></value></member>
                <member><name>name</name><value><string>Vitamina D 2000UI</string></value></member>
                <member><name>default_code</name><value><string>BIOTINA VITAMINA C Y A</string></value></member>
                <member><name>qty_available</name><value><double>4</double></value></member>
                <member><name>lst_price</name><value><double>14.0</double></value></member>
                <member><name>barcode</name><value><string>11111</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>802</int></value></member>
                <member><name>name</name><value><string>Vitamina C con Zinc</string></value></member>
                <member><name>default_code</name><value><string>VITCZ</string></value></member>
                <member><name>qty_available</name><value><double>4</double></value></member>
                <member><name>lst_price</name><value><double>13.0</double></value></member>
                <member><name>barcode</name><value><string>22222</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>804</int></value></member>
                <member><name>name</name><value><string>Emergen C 1000mg</string></value></member>
                <member><name>default_code</name><value><string>VITAMINA C</string></value></member>
                <member><name>qty_available</name><value><double>4</double></value></member>
                <member><name>lst_price</name><value><double>15.5</double></value></member>
                <member><name>barcode</name><value><string>44444</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>803</int></value></member>
                <member><name>name</name><value><string>Vitamina C 1g</string></value></member>
                <member><name>default_code</name><value><string>VITC</string></value></member>
                <member><name>qty_available</name><value><double>4</double></value></member>
                <member><name>lst_price</name><value><double>15.0</double></value></member>
                <member><name>barcode</name><value><string>33333</string></value></member>
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

    private function searchReadPrefixTirzepatidaXml(): string
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
                <member><name>id</name><value><int>701</int></value></member>
                <member><name>name</name><value><string>Tirzepatida 5mg</string></value></member>
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

    private function searchReadPhraseVitaminaCXml(): string
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
                <member><name>id</name><value><int>804</int></value></member>
                <member><name>name</name><value><string>Emergen C 1000mg</string></value></member>
                <member><name>default_code</name><value><string>VITAMINA C</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>803</int></value></member>
                <member><name>name</name><value><string>Vitamina C 1g</string></value></member>
                <member><name>default_code</name><value><string>VITC</string></value></member>
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

    private function searchReadPhraseAcetaminofen500Xml(): string
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
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>503</int></value></member>
                <member><name>name</name><value><string>Acetaminofen 500mg Plus</string></value></member>
                <member><name>default_code</name><value><string>ACE500P</string></value></member>
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

    private function nameSearch500Xml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value>
        <array>
          <data>
            <value><array><data><value><int>501</int></value><value><string>Acetaminofen 500mg</string></value></data></array></value>
            <value><array><data><value><int>503</int></value><value><string>Acetaminofen 500mg Plus</string></value></data></array></value>
          </data>
        </array>
      </value>
    </param>
  </params>
</methodResponse>
XML;
    }

    private function productReadAcetaminofen500Xml(): string
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
                <member><name>qty_available</name><value><double>6</double></value></member>
                <member><name>lst_price</name><value><double>20</double></value></member>
                <member><name>barcode</name><value><string>11111</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>503</int></value></member>
                <member><name>name</name><value><string>Acetaminofen 500mg Plus</string></value></member>
                <member><name>default_code</name><value><string>ACE500P</string></value></member>
                <member><name>qty_available</name><value><double>5</double></value></member>
                <member><name>lst_price</name><value><double>21</double></value></member>
                <member><name>barcode</name><value><string>33331</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>502</int></value></member>
                <member><name>name</name><value><string>Acetaminofen Infantil</string></value></member>
                <member><name>default_code</name><value><string>ACEINF</string></value></member>
                <member><name>qty_available</name><value><double>3</double></value></member>
                <member><name>lst_price</name><value><double>12</double></value></member>
                <member><name>barcode</name><value><string>22222</string></value></member>
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

    private function nameSearch500mgXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value>
        <array>
          <data>
            <value><array><data><value><int>610</int></value><value><string>Tableta 500mg</string></value></data></array></value>
            <value><array><data><value><int>611</int></value><value><string>Acetaminofen 500mg</string></value></data></array></value>
            <value><array><data><value><int>612</int></value><value><string>Jarabe 500mg</string></value></data></array></value>
          </data>
        </array>
      </value>
    </param>
  </params>
</methodResponse>
XML;
    }

    private function productRead500mgRankingXml(): string
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
                <member><name>id</name><value><int>610</int></value></member>
                <member><name>name</name><value><string>Tableta 500mg</string></value></member>
                <member><name>default_code</name><value><string>500MG</string></value></member>
                <member><name>qty_available</name><value><double>7</double></value></member>
                <member><name>lst_price</name><value><double>10</double></value></member>
                <member><name>barcode</name><value><string>50001</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>611</int></value></member>
                <member><name>name</name><value><string>Acetaminofen 500mg</string></value></member>
                <member><name>default_code</name><value><string>ACE500</string></value></member>
                <member><name>qty_available</name><value><double>6</double></value></member>
                <member><name>lst_price</name><value><double>11</double></value></member>
                <member><name>barcode</name><value><string>50002</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>612</int></value></member>
                <member><name>name</name><value><string>Jarabe 500mg</string></value></member>
                <member><name>default_code</name><value><string>JBE500</string></value></member>
                <member><name>qty_available</name><value><double>4</double></value></member>
                <member><name>lst_price</name><value><double>9</double></value></member>
                <member><name>barcode</name><value><string>50003</string></value></member>
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

    private function productReadSingleTirzepatidaXml(): string
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
                <member><name>id</name><value><int>701</int></value></member>
                <member><name>name</name><value><string>Tirzepatida 5mg</string></value></member>
                <member><name>default_code</name><value><string>TIR5</string></value></member>
                <member><name>qty_available</name><value><double>3</double></value></member>
                <member><name>lst_price</name><value><double>50.0</double></value></member>
                <member><name>barcode</name><value><string>44444</string></value></member>
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

    private function productReadByLocationXml(float $qty501, float $qty502): string
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
                <member><name>id</name><value><int>501</int></value></member>
                <member><name>qty_available</name><value><double>{$qty501}</double></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>502</int></value></member>
                <member><name>qty_available</name><value><double>{$qty502}</double></value></member>
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


    private function productFieldsGetWithBrokenFieldXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value>
        <struct>
          <member>
            <name>name</name>
            <value><struct>
              <member><name>string</name><value><string>Nombre</string></value></member>
              <member><name>type</name><value><string>char</string></value></member>
            </struct></value>
          </member>
          <member>
            <name>x_broken_rel</name>
            <value><struct>
              <member><name>string</name><value><string>Broken Rel</string></value></member>
              <member><name>type</name><value><string>many2one</string></value></member>
            </struct></value>
          </member>
          <member>
            <name>lst_price</name>
            <value><struct>
              <member><name>string</name><value><string>Precio de Venta</string></value></member>
              <member><name>type</name><value><string>float</string></value></member>
            </struct></value>
          </member>
        </struct>
      </value>
    </param>
  </params>
</methodResponse>
XML;
    }

    private function xmlRpcFaultReadUnknownField(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse>
  <fault>
    <value>
      <struct>
        <member><name>faultCode</name><value><int>1</int></value></member>
        <member><name>faultString</name><value><string>AttributeError: '_unknown' object has no attribute 'id'</string></value></member>
      </struct>
    </value>
  </fault>
</methodResponse>
XML;
    }

    private function productInspectReadWithoutBrokenFieldXml(): string
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
                <member><name>lst_price</name><value><double>19.9</double></value></member>
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

    private function productFieldsGetXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value>
        <struct>
          <member>
            <name>name</name>
            <value><struct>
              <member><name>string</name><value><string>Nombre</string></value></member>
              <member><name>type</name><value><string>char</string></value></member>
            </struct></value>
          </member>
          <member>
            <name>lst_price</name>
            <value><struct>
              <member><name>string</name><value><string>Precio de Venta</string></value></member>
              <member><name>type</name><value><string>float</string></value></member>
            </struct></value>
          </member>
          <member>
            <name>x_price_bs</name>
            <value><struct>
              <member><name>string</name><value><string>Precio Bs</string></value></member>
              <member><name>type</name><value><string>float</string></value></member>
            </struct></value>
          </member>
        </struct>
      </value>
    </param>
  </params>
</methodResponse>
XML;
    }

    private function productInspectReadXml(): string
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
                <member><name>product_tmpl_id</name><value><array><data><value><int>701</int></value><value><string>Acetaminofen</string></value></data></array></value></member>
                <member><name>lst_price</name><value><double>19.9</double></value></member>
                <member><name>x_price_bs</name><value><double>745.2</double></value></member>
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

    private function productTemplateFieldsGetXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value>
        <struct>
          <member>
            <name>name</name>
            <value><struct>
              <member><name>string</name><value><string>Nombre</string></value></member>
              <member><name>type</name><value><string>char</string></value></member>
            </struct></value>
          </member>
          <member>
            <name>list_price</name>
            <value><struct>
              <member><name>string</name><value><string>Precio de Venta</string></value></member>
              <member><name>type</name><value><string>float</string></value></member>
            </struct></value>
          </member>
        </struct>
      </value>
    </param>
  </params>
</methodResponse>
XML;
    }

    private function productTemplateReadXml(): string
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
                <member><name>id</name><value><int>701</int></value></member>
                <member><name>name</name><value><string>Acetaminofen</string></value></member>
                <member><name>list_price</name><value><double>19.9</double></value></member>
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
