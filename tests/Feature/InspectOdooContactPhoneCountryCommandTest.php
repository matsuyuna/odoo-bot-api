<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InspectOdooContactPhoneCountryCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_command_lists_country_fields_from_odoo_contacts(): void
    {
        putenv('ODOO_URL=https://odoo.test');
        putenv('ODOO_DB=test_db');
        putenv('ODOO_USERNAME=test_user');
        putenv('ODOO_PASSWORD=test_pass');

        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(11), 200),
            'https://odoo.test/xmlrpc/2/object' => function ($request) {
                $body = $request->body();

                if (str_contains($body, '<string>fields_get</string>')) {
                    return Http::response($this->fieldsXml(), 200);
                }

                return Http::response($this->contactsXml(), 200);
            },
        ]);

        $this->artisan('odoo:contacts:inspect-phone-country --limit=10')
            ->expectsOutputToContain('country_id')
            ->expectsTable(
                ['id', 'name', 'phone', 'mobile', 'country_id', 'phone_country_code'],
                [
                    [501, 'Ana Country', '+584121234567', '', 'Venezuela', '+58'],
                    [502, 'Luis NoCountry', '', '+51987654321', '', '+51'],
                ]
            )
            ->assertSuccessful();
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

    private function fieldsXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse>
  <params>
    <param>
      <value>
        <struct>
          <member><name>country_id</name><value><struct><member><name>type</name><value><string>many2one</string></value></member></struct></value></member>
          <member><name>phone_country_code</name><value><struct><member><name>type</name><value><string>char</string></value></member></struct></value></member>
          <member><name>phone_sanitized</name><value><struct><member><name>type</name><value><string>char</string></value></member></struct></value></member>
          <member><name>mobile_sanitized</name><value><struct><member><name>type</name><value><string>char</string></value></member></struct></value></member>
        </struct>
      </value>
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
                <member><name>id</name><value><int>501</int></value></member>
                <member><name>name</name><value><string>Ana Country</string></value></member>
                <member><name>phone</name><value><string>+584121234567</string></value></member>
                <member><name>mobile</name><value><string></string></value></member>
                <member><name>country_id</name><value><array><data><value><int>238</int></value><value><string>Venezuela</string></value></data></array></value></member>
                <member><name>phone_country_code</name><value><string>+58</string></value></member>
              </struct>
            </value>
            <value>
              <struct>
                <member><name>id</name><value><int>502</int></value></member>
                <member><name>name</name><value><string>Luis NoCountry</string></value></member>
                <member><name>phone</name><value><string></string></value></member>
                <member><name>mobile</name><value><string>+51987654321</string></value></member>
                <member><name>country_id</name><value><boolean>0</boolean></value></member>
                <member><name>phone_country_code</name><value><string>+51</string></value></member>
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
