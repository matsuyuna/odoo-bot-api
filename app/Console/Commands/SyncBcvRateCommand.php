<?php

namespace App\Console\Commands;

use App\Models\BcvRate;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class SyncBcvRateCommand extends Command
{
    protected $signature = 'bcv:rates:sync';

    protected $description = 'Sincroniza la tasa BCV diaria desde el endpoint público';

    public function handle(): int
    {
        $rateUrls = config('services.bcv.rate_urls', [
            'https://api-bcv-pi.vercel.app/api/tasa',
        ]);
        $response = null;
        $lastStatus = null;
        $lastError = null;

        foreach ($rateUrls as $rateUrl) {
            try {
                $currentResponse = Http::acceptJson()
                    ->withHeaders([
                        'User-Agent' => 'Mozilla/5.0 (compatible; odoo-bot-api/1.0; +https://example.com)',
                    ])
                    ->timeout(20)
                    ->retry(2, 300, throw: false)
                    ->get($rateUrl);
            } catch (ConnectionException $e) {
                $lastError = sprintf('Fallo de conexión consultando %s: %s', $rateUrl, $e->getMessage());
                $this->warn($lastError);

                continue;
            }

            if ($currentResponse->successful()) {
                $response = $currentResponse;

                break;
            }

            $lastStatus = $currentResponse->status();
            $lastError = sprintf(
                'Respuesta no exitosa en %s (HTTP %s): %s',
                $rateUrl,
                $currentResponse->status(),
                substr(trim($currentResponse->body()), 0, 200)
            );
            $this->warn($lastError);
        }

        if (!$response instanceof Response) {
            throw new RuntimeException(
                'No se pudo consultar la tasa BCV. Status: '
                . ($lastStatus ?? 'N/A')
                . ($lastError ? ' | Detalle: ' . $lastError : '')
            );
        }

        $parsedRate = $this->extractRatePayload($response->json());

        if (!is_array($parsedRate)) {
            throw new RuntimeException('Respuesta inválida del API BCV.');
        }

        $date = $parsedRate['date'];
        $dollar = $parsedRate['dollar'];

        BcvRate::query()->updateOrCreate(
            ['date' => $date],
            ['dollar' => (float) $dollar],
        );

        $this->info(sprintf('Tasa BCV sincronizada para %s: %.4f', $date, (float) $dollar));

        return self::SUCCESS;
    }

    /**
     * @return array{date:string,dollar:float}|null
     */
    private function extractRatePayload(mixed $payload): ?array
    {
        if (!is_array($payload)) {
            return null;
        }

        $candidates = [
            $payload,
            $payload['data'] ?? null,
            $payload['rates'] ?? null,
            $payload['result'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $date = $candidate['date'] ?? $candidate['fecha'] ?? $payload['date'] ?? $payload['fecha'] ?? null;
            $dollar = $candidate['dollar']
                ?? $candidate['usd']
                ?? $candidate['USD']
                ?? $candidate['tasa']
                ?? $candidate['rate']
                ?? $candidate['valor']
                ?? ($candidate['usd']['value'] ?? null)
                ?? ($candidate['USD']['value'] ?? null)
                ?? ($candidate['dollar']['value'] ?? null)
                ?? ($candidate['tasa']['value'] ?? null)
                ?? ($candidate['rate']['value'] ?? null);

            if (is_string($date) && is_numeric($dollar)) {
                return [
                    'date' => $date,
                    'dollar' => (float) $dollar,
                ];
            }
        }

        return null;
    }
}
