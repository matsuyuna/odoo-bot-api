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
        Http::fake([
            'https://bcv-api.rafnixg.dev/rates/' => Http::response([
                'dollar' => 427.9302,
                'date' => '2026-03-04',
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
}
