<?php

namespace Tests\Feature;

use App\Models\BcvExchangeRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FetchBcvUsdRateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_guarda_tasa_usd_desde_export_y_borra_archivo_temporal(): void
    {
        Cache::flush();
        Storage::fake('local');

        Http::fake([
            'https://bcv.org.ve/cambiaria/export/tasas-informativas-sistema-bancario' => Http::response("Fecha;Moneda;Tasa\n04-03-2026;USD;436,7273", 200, ['Content-Type' => 'text/plain']),
        ]);

        $this->artisan('bcv:usd-rate:fetch')
            ->assertSuccessful();

        $this->assertDatabaseHas('bcv_exchange_rates', [
            'rate_date' => '2026-03-04',
            'usd_rate' => '436.7273',
            'source' => 'export',
        ]);

        Storage::disk('local')->assertDirectoryEmpty('tmp');
    }

    public function test_hace_fallback_html_si_export_falla(): void
    {
        Cache::flush();

        Http::fake([
            'https://bcv.org.ve/cambiaria/export/tasas-informativas-sistema-bancario' => Http::response('bad gateway', 502),
            'https://bcv.org.ve/cambiaria/tasas-informativas-sistema-bancario' => Http::response('<html><body><p>Fecha: 05-03-2026</p><table><tr><td>Dólar</td><td>437,1200</td></tr></table></body></html>', 200, ['Content-Type' => 'text/html']),
        ]);

        $this->artisan('bcv:usd-rate:fetch')
            ->assertSuccessful();

        $rate = BcvExchangeRate::query()->firstOrFail();

        $this->assertSame('2026-03-05', $rate->rate_date->toDateString());
        $this->assertSame('437.1200', number_format((float) $rate->usd_rate, 4, '.', ''));
        $this->assertSame('html_fallback', $rate->source);
    }
}
