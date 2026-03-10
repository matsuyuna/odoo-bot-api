<?php

namespace Tests\Feature;

use App\Models\OdooContactSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PushPendingContactsToWatiCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_marca_error_cuando_telefono_no_es_valido_y_muestra_resumen(): void
    {
        putenv('WATI_BASE_URL=https://wati.test');
        putenv('WATI_TENANT_ID=tenant123');
        putenv('WATI_TOKEN=token123');
        putenv('WATI_SOURCE_TYPE=Wati');

        OdooContactSync::query()->create([
            'odoo_contact_id' => 101,
            'name' => 'Contacto Inválido',
            'preferred_whatsapp' => 'abc',
            'wati_status' => 'pending',
        ]);

        Http::fake();

        $this->artisan('wati:contacts:push')
            ->expectsOutputToContain('fallidos: 1')
            ->expectsOutputToContain('Motivos de fallo detectados')
            ->expectsOutputToContain('Teléfono inválido o ausente para WATI')
            ->assertSuccessful();

        $this->assertDatabaseHas('odoo_contact_syncs', [
            'odoo_contact_id' => 101,
            'wati_status' => 'error',
        ]);
    }

    public function test_envia_telefono_normalizado_a_wati(): void
    {
        putenv('WATI_BASE_URL=https://wati.test');
        putenv('WATI_TENANT_ID=tenant123');
        putenv('WATI_TOKEN=token123');
        putenv('WATI_SOURCE_TYPE=Wati');

        OdooContactSync::query()->create([
            'odoo_contact_id' => 102,
            'name' => 'Contacto Válido',
            'preferred_whatsapp' => '+58 (424) 229-0660',
            'ultimo_producto_comprado' => 'Acetaminofén 500mg, Vitamina C',
            'producto_mas_comprado' => 'Acetaminofén 500mg',
            'wati_status' => 'pending',
        ]);

        Http::fake([
            'https://wati.test/tenant123/api/v1/addContact/%2B584242290660*' => Http::response(['ok' => true], 200),
        ]);

        $this->artisan('wati:contacts:push')->assertSuccessful();

        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), '/addContact/%2B584242290660')) {
                return false;
            }

            $payload = $request->data();
            $customParams = collect($payload['customParams'] ?? [])->keyBy('name');

            return ($customParams['ultimoproductocomprado']['value'] ?? null) === 'Acetaminofén 500mg, Vitamina C'
                && ($customParams['productomascomprado']['value'] ?? null) === 'Acetaminofén 500mg';
        });

        $this->assertDatabaseHas('odoo_contact_syncs', [
            'odoo_contact_id' => 102,
            'wati_status' => 'sent',
        ]);
    }
}
