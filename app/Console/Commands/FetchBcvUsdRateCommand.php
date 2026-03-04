<?php

namespace App\Console\Commands;

use App\Models\BcvExchangeRate;
use App\Services\BcvExchangeRateFetcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class FetchBcvUsdRateCommand extends Command
{
    protected $signature = 'bcv:usd-rate:fetch';

    protected $description = 'Consulta la tasa USD del BCV y guarda un registro diario en base de datos';

    public function handle(BcvExchangeRateFetcher $fetcher): int
    {
        $tempDisk = Storage::disk('local');
        $tempPath = 'tmp/bcv-usd-rate-' . now()->format('YmdHis') . '-' . uniqid() . '.txt';

        try {
            $result = $fetcher->fetchUsdRate();

            $tempDisk->put($tempPath, $result['raw_content']);

            BcvExchangeRate::query()->updateOrCreate(
                ['rate_date' => $result['rate_date']],
                [
                    'usd_rate' => $result['usd_rate'],
                    'source' => $result['source'],
                ]
            );
        } catch (\Throwable $e) {
            if ($tempDisk->exists($tempPath)) {
                $tempDisk->delete($tempPath);
            }

            $this->error('No se pudo actualizar tasa BCV: ' . $e->getMessage());

            return self::FAILURE;
        }

        $tempDisk->delete($tempPath);
        $this->info('Tasa BCV USD guardada para ' . $result['rate_date'] . ': ' . $result['usd_rate']);

        return self::SUCCESS;
    }
}
