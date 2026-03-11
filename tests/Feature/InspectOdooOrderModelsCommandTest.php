<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InspectOdooOrderModelsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_command_alinea_partner_ids_con_commercial_partner_id(): void
    {
        putenv('ODOO_URL=https://odoo.test');
        putenv('ODOO_DB=test_db');
        putenv('ODOO_USERNAME=test_user');
        putenv('ODOO_PASSWORD=test_pass');

        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(10), 200),
            'https://odoo.test/xmlrpc/2/object' => function ($request) {
                $body = $request->body();

                if (str_contains($body, '<string>res.partner</string>') && str_contains($body, '<string>search_read</string>')) {
                    return Http::response($this->commercialPartnerRowsXml(), 200);
                }

                if (str_contains($body, '<string>fields_get</string>')) {
                    return Http::response($this->genericFieldsXml(), 200);
                }

                if (str_contains($body, '<string>search_read</string>')) {
                    return Http::response($this->emptyArrayXml(), 200);
                }

                return Http::response($this->emptyArrayXml(), 200);
            },
        ]);

        $this->artisan('odoo:orders:inspect --partner-ids=101 --limit=1')
            ->expectsOutputToContain('Commercial partner IDs usados: 900')
            ->expectsOutputToContain('"101":900')
            ->assertSuccessful();
    }

    private function authXml(int $uid): string
    {
        return <<<XML
<?xml version="1.0"?>
<methodResponse><params><param><value><int>{$uid}</int></value></param></params></methodResponse>
XML;
    }

    private function commercialPartnerRowsXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse><params><param><value><array><data><value><struct>
<member><name>id</name><value><int>101</int></value></member>
<member><name>commercial_partner_id</name><value><array><data><value><int>900</int></value><value><string>Casa Matriz</string></value></data></array></value></member>
</struct></value></data></array></value></param></params></methodResponse>
XML;
    }

    private function genericFieldsXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse><params><param><value><struct>
<member><name>name</name><value><struct><member><name>type</name><value><string>char</string></value></member></struct></value></member>
<member><name>partner_id</name><value><struct><member><name>type</name><value><string>many2one</string></value></member></struct></value></member>
<member><name>order_id</name><value><struct><member><name>type</name><value><string>many2one</string></value></member></struct></value></member>
<member><name>product_id</name><value><struct><member><name>type</name><value><string>many2one</string></value></member></struct></value></member>
<member><name>state</name><value><struct><member><name>type</name><value><string>selection</string></value></member></struct></value></member>
<member><name>date_order</name><value><struct><member><name>type</name><value><string>datetime</string></value></member></struct></value></member>
<member><name>create_date</name><value><struct><member><name>type</name><value><string>datetime</string></value></member></struct></value></member>
<member><name>product_uom_qty</name><value><struct><member><name>type</name><value><string>float</string></value></member></struct></value></member>
<member><name>product_qty</name><value><struct><member><name>type</name><value><string>float</string></value></member></struct></value></member>
<member><name>qty</name><value><struct><member><name>type</name><value><string>float</string></value></member></struct></value></member>
<member><name>price_unit</name><value><struct><member><name>type</name><value><string>float</string></value></member></struct></value></member>
</struct></value></param></params></methodResponse>
XML;
    }

    private function emptyArrayXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse><params><param><value><array><data></data></array></value></param></params></methodResponse>
XML;
    }
}
