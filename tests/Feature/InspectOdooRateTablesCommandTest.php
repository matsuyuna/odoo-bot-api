<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InspectOdooRateTablesCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_command_muestra_modelos_relacionados_a_tasa_de_forma_ligera(): void
    {
        putenv('ODOO_URL=https://odoo.test');
        putenv('ODOO_DB=test_db');
        putenv('ODOO_USERNAME=test_user');
        putenv('ODOO_PASSWORD=test_pass');

        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(10), 200),
            'https://odoo.test/xmlrpc/2/object' => function ($request) {
                $body = $request->body();

                if (str_contains($body, '<string>res.currency.rate</string>') && str_contains($body, '<string>fields_get</string>')) {
                    return Http::response($this->rateFieldsXml(), 200);
                }

                if (str_contains($body, '<string>res.currency.rate</string>') && str_contains($body, '<string>search_read</string>')) {
                    return Http::response($this->rateRowsXml(), 200);
                }

                if (str_contains($body, '<string>res.currency</string>') && str_contains($body, '<string>fields_get</string>')) {
                    return Http::response($this->currencyFieldsXml(), 200);
                }

                if (str_contains($body, '<string>res.currency</string>') && str_contains($body, '<string>search_read</string>')) {
                    return Http::response($this->currencyRowsXml(), 200);
                }

                if (str_contains($body, '<string>ir.config_parameter</string>') && str_contains($body, '<string>fields_get</string>')) {
                    return Http::response($this->configFieldsXml(), 200);
                }

                return Http::response($this->configRowsXml(), 200);
            },
        ]);

        $this->artisan('odoo:rates:inspect --limit=2')
            ->expectsOutputToContain('Modelo: res.currency.rate')
            ->expectsOutputToContain('Modelo: res.currency')
            ->expectsOutputToContain('Modelo: ir.config_parameter')
            ->assertSuccessful();
    }

    public function test_command_serializa_celdas_complejas_al_renderizar_tabla(): void
    {
        putenv('ODOO_URL=https://odoo.test');
        putenv('ODOO_DB=test_db');
        putenv('ODOO_USERNAME=test_user');
        putenv('ODOO_PASSWORD=test_pass');

        Http::fake([
            'https://odoo.test/xmlrpc/2/common' => Http::response($this->authXml(10), 200),
            'https://odoo.test/xmlrpc/2/object' => function ($request) {
                $body = $request->body();

                if (str_contains($body, '<string>res.currency.rate</string>') && str_contains($body, '<string>fields_get</string>')) {
                    return Http::response($this->rateFieldsXml(), 200);
                }

                if (str_contains($body, '<string>res.currency.rate</string>') && str_contains($body, '<string>search_read</string>')) {
                    return Http::response($this->rateRowsWithMany2oneXml(), 200);
                }

                if (str_contains($body, '<string>res.currency</string>') && str_contains($body, '<string>fields_get</string>')) {
                    return Http::response($this->currencyFieldsXml(), 200);
                }

                if (str_contains($body, '<string>res.currency</string>') && str_contains($body, '<string>search_read</string>')) {
                    return Http::response($this->currencyRowsXml(), 200);
                }

                if (str_contains($body, '<string>ir.config_parameter</string>') && str_contains($body, '<string>fields_get</string>')) {
                    return Http::response($this->configFieldsXml(), 200);
                }

                return Http::response($this->configRowsXml(), 200);
            },
        ]);

        $this->artisan('odoo:rates:inspect --limit=1')
            ->expectsOutputToContain('Modelo: res.currency.rate')
            ->expectsOutputToContain('[2,"USD"]')
            ->assertSuccessful();
    }

    private function authXml(int $uid): string
    {
        return <<<XML
<?xml version="1.0"?>
<methodResponse><params><param><value><int>{$uid}</int></value></param></params></methodResponse>
XML;
    }

    private function rateFieldsXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse><params><param><value><struct>
<member><name>name</name><value><struct><member><name>type</name><value><string>date</string></value></member></struct></value></member>
<member><name>rate</name><value><struct><member><name>type</name><value><string>float</string></value></member></struct></value></member>
<member><name>currency_id</name><value><struct><member><name>type</name><value><string>many2one</string></value></member></struct></value></member>
</struct></value></param></params></methodResponse>
XML;
    }

    private function rateRowsXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse><params><param><value><array><data><value><struct>
<member><name>id</name><value><int>1</int></value></member>
<member><name>name</name><value><string>2026-03-10</string></value></member>
<member><name>rate</name><value><double>0.0023</double></value></member>
</struct></value></data></array></value></param></params></methodResponse>
XML;
    }

    private function rateRowsWithMany2oneXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse><params><param><value><array><data><value><struct>
<member><name>id</name><value><int>1</int></value></member>
<member><name>name</name><value><string>2026-03-10</string></value></member>
<member><name>currency_id</name><value><array><data><value><int>2</int></value><value><string>USD</string></value></data></array></value></member>
<member><name>rate</name><value><double>0.0023</double></value></member>
</struct></value></data></array></value></param></params></methodResponse>
XML;
    }

    private function currencyFieldsXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse><params><param><value><struct>
<member><name>name</name><value><struct><member><name>type</name><value><string>char</string></value></member></struct></value></member>
<member><name>display_name</name><value><struct><member><name>type</name><value><string>char</string></value></member></struct></value></member>
</struct></value></param></params></methodResponse>
XML;
    }

    private function currencyRowsXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse><params><param><value><array><data><value><struct>
<member><name>id</name><value><int>2</int></value></member>
<member><name>name</name><value><string>USD</string></value></member>
<member><name>display_name</name><value><string>US Dollar</string></value></member>
</struct></value></data></array></value></param></params></methodResponse>
XML;
    }

    private function configFieldsXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse><params><param><value><struct>
<member><name>key</name><value><struct><member><name>type</name><value><string>char</string></value></member></struct></value></member>
<member><name>value</name><value><struct><member><name>type</name><value><string>char</string></value></member></struct></value></member>
</struct></value></param></params></methodResponse>
XML;
    }

    private function configRowsXml(): string
    {
        return <<<'XML'
<?xml version="1.0"?>
<methodResponse><params><param><value><array><data><value><struct>
<member><name>id</name><value><int>3</int></value></member>
<member><name>key</name><value><string>my_module.bcv_rate</string></value></member>
<member><name>value</name><value><string>43.52</string></value></member>
</struct></value></data></array></value></param></params></methodResponse>
XML;
    }
}
