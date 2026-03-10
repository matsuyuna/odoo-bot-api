<?php

namespace App\Console\Commands;

use App\Models\BcvRate;
use App\Services\OdooXmlRpc;
use Illuminate\Console\Command;
use RuntimeException;

class SyncBcvRateCommand extends Command
{
    protected $signature = 'bcv:rates:sync';

    protected $description = 'Sincroniza las tasas BCV desde Odoo (res.currency.rate y res.currency)';

    public function handle(): int
    {
        $odoo = OdooXmlRpc::fromEnv();
        $rates = $odoo->getLatestCurrencyRates();

        $date = (string) $rates['date'];
        $resCurrencyRate = (float) $rates['res_currency_rate'];
        $resCurrency = (float) $rates['res_currency'];

        if ($date === '') {
            throw new RuntimeException('La fecha de tasa recibida desde Odoo es inválida.');
        }

        BcvRate::query()->updateOrCreate(
            ['date' => $date],
            [
                'res_currency_rate' => $resCurrencyRate,
                'res_currency' => $resCurrency,
            ],
        );

        $this->info(sprintf(
            'Tasas BCV sincronizadas para %s: res.currency.rate=%.4f | res.currency=%.4f',
            $date,
            $resCurrencyRate,
            $resCurrency,
        ));

        return self::SUCCESS;
    }
}
