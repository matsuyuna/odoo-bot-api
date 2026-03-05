<?php

namespace App\Console\Commands;

use App\Models\BcvRate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncBcvRateCommand extends Command
{
    protected $signature = 'bcv:rates:sync';

    protected $description = 'Sincroniza la tasa BCV diaria desde el endpoint público';

    public function handle(): int
    {
        $response = Http::acceptJson()
            ->withHeaders([
                'User-Agent' => 'odoo-bot-api/1.0',
            ])
            ->timeout(20)
            ->get('https://bcv-api.rafnixg.dev/rates/');

        if (!$response->successful()) {
            throw new RuntimeException('No se pudo consultar la tasa BCV. Status: ' . $response->status());
        }

        $data = $response->json();

        $date = $data['date'] ?? null;
        $dollar = $data['dollar'] ?? null;

        if (!is_string($date) || !is_numeric($dollar)) {
            throw new RuntimeException('Respuesta inválida del API BCV.');
        }

        BcvRate::query()->updateOrCreate(
            ['date' => $date],
            ['dollar' => (float) $dollar],
        );

        $this->info(sprintf('Tasa BCV sincronizada para %s: %.4f', $date, (float) $dollar));

        return self::SUCCESS;
    }
}
