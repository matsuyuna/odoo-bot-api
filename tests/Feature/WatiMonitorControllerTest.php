<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WatiMonitorControllerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        putenv('WATI_BASE_URL=https://wati.test');
        putenv('WATI_TENANT_ID=10101449');
        putenv('WATI_TOKEN=token123');
        putenv('WATI_SOURCE_TYPE=Wati');
    }

    public function test_muestra_lista_acotada_de_contactos_de_wati(): void
    {
        Http::fake([
            'https://wati.test/10101449/api/v1/getContacts*' => Http::response([
                'contacts' => [
                    ['id' => 1, 'name' => 'Ana', 'phone' => '584111111111'],
                ],
            ], 200),
        ]);

        $response = $this->get('/wati/monitor?limit=5&page=1');

        $response->assertOk();
        $response->assertSee('Ana');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'pageSize=5')
                && str_contains($request->url(), 'pageNumber=1');
        });
    }

    public function test_envia_contacto_a_wati_para_testing(): void
    {
        Http::fake([
            'https://wati.test/10101449/api/v1/addContact/584244162964*' => Http::response([
                'result' => true,
            ], 200),
            'https://wati.test/10101449/api/v1/getContacts*' => Http::response(['contacts' => []], 200),
        ]);

        $response = $this->post('/wati/monitor/contactos', [
            'phone' => '584244162964',
            'name' => 'Prueba WATI',
        ]);

        $response->assertRedirect('/wati/monitor');

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && str_starts_with($request->url(), 'https://wati.test/10101449/api/v1/addContact/584244162964'));
    }
}
