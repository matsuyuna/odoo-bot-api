<?php

namespace Tests\Feature;

use App\Models\BcvRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncBcvRateCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_bcv_rate_command_stores_latest_rate(): void
    {
        config()->set('services.bcv.rate_urls', ['https://api-bcv-pi.vercel.app/api/tasa/usd']);

        Http::fake([
            'https://api-bcv-pi.vercel.app/api/tasa/usd' => Http::response([
                'valor' => ['valor_num' => 427.9302],
                'fecha' => '2026-03-04',
            ], 200),
        ]);

        $this->artisan('bcv:rates:sync')
            ->expectsOutput('Tasa BCV sincronizada para 2026-03-04: 427.9302')
            ->assertSuccessful();

        $this->assertDatabaseHas('bcv_rates', [
            'date' => '2026-03-04',
            'dollar' => 427.9302,
        ]);

        $this->assertSame(1, BcvRate::query()->count());
    }

    public function test_sync_bcv_rate_command_accepts_nested_payload_shape(): void
    {
        config()->set('services.bcv.rate_urls', ['https://api-bcv-pi.vercel.app/api/tasa/usd']);

        Http::fake([
            'https://api-bcv-pi.vercel.app/api/tasa/usd' => Http::response([
                'valor' => [
                    'valor_num' => 431.4501,
                    'fecha' => '2026-03-06',
                ],
            ], 200),
        ]);

        $this->artisan('bcv:rates:sync')
            ->expectsOutput('Tasa BCV sincronizada para 2026-03-06: 431.4501')
            ->assertSuccessful();

        $this->assertDatabaseHas('bcv_rates', [
            'date' => '2026-03-06',
            'dollar' => 431.4501,
        ]);
    }

    public function test_sync_bcv_rate_command_tries_next_url_when_first_fails(): void
    {
        config()->set('services.bcv.rate_urls', [
            'https://first-source.example/rates/',
            'https://second-source.example/rates/',
        ]);

        Http::fake([
            'https://first-source.example/rates/' => Http::response([], 403),
            'https://second-source.example/rates/' => Http::response([
                'dollar' => 430.1100,
                'date' => '2026-03-05',
            ], 200),
        ]);

        $this->artisan('bcv:rates:sync')
            ->expectsOutput('Tasa BCV sincronizada para 2026-03-05: 430.1100')
            ->assertSuccessful();

        $this->assertDatabaseHas('bcv_rates', [
            'date' => '2026-03-05',
            'dollar' => 430.1100,
        ]);
    }
}
