<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BotContactoControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_buscar_contacto_returns_500_if_odoo_env_is_missing(): void
    {
        putenv('ODOO_URL');
        putenv('ODOO_DB');
        putenv('ODOO_USERNAME');
        putenv('ODOO_PASSWORD');

        $response = $this->getJson('/api/buscar-contacto');

        $response
            ->assertStatus(500)
            ->assertJsonPath('error', 'Error consultando contactos en Odoo');
    }

    public function test_buscar_contacto_returns_contacts_from_odoo(): void
    {
        putenv('ODOO_URL=https://odoo.test');
        putenv('ODOO_DB=test_db');
        putenv('ODOO_USERNAME=test_user');
        putenv('ODOO_PASSWORD=test_pass');

        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(9), 200),
            'https://odoo.test/xmlrpc/2/object' => Http::response($this->contactsXml(), 200),
        ]);

        $response = $this->getJson('/api/buscar-contacto?nombre=andrea&limit=10');

        $response
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonPath('0.name', 'Andrea Martinez')
            ->assertJsonPath('0.preferred_whatsapp', '+584242290660')
            ->assertJsonPath('1.name', 'Promujer Contacto');
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

    private function contactsXml(): string
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
                <member><name>name</name><value><string>Andrea Martinez</string></value></member>
                <member><name>email</name><value><string>andrea@example.com</string></value></member>
                <member><name>phone</name><value><string>+584242290660</string></value></member>
                <member><name>mobile</name><value><string></string></value></member>
                <member><name>vat</name><value><string>V12345678</string></value></member>
                <member><name>is_company</name><value><boolean>0</boolean></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>102</int></value></member>
                <member><name>name</name><value><string>Promujer Contacto</string></value></member>
                <member><name>email</name><value><string>contacto@promujer.org</string></value></member>
                <member><name>phone</name><value><string></string></value></member>
                <member><name>mobile</name><value><string>+5215550102030</string></value></member>
                <member><name>vat</name><value><string></string></value></member>
                <member><name>is_company</name><value><boolean>1</boolean></value></member>
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

