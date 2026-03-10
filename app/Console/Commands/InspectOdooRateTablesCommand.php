<?php

namespace App\Console\Commands;

use App\Services\OdooXmlRpc;
use Illuminate\Console\Command;

class InspectOdooRateTablesCommand extends Command
{
    protected $signature = 'odoo:rates:inspect {--limit=10 : Máximo de filas por modelo para muestreo}';

    protected $description = 'Inspecciona modelos de Odoo potencialmente útiles para la tasa BCV sin cargar excesivamente el servidor';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 30));

        try {
            $odoo = OdooXmlRpc::fromEnv();
            $report = $odoo->inspectRateRelatedModels($limit);
        } catch (\Throwable $e) {
            $this->error('Error inspeccionando modelos de tasa en Odoo: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($report as $item) {
            $this->newLine();
            $this->info('Modelo: ' . $item['model']);

            if (!empty($item['error'])) {
                $this->warn('No accesible: ' . $item['error']);
                continue;
            }

            $this->line('Campos disponibles: ' . implode(', ', $item['available_fields']));
            $rows = $item['sample_rows'] ?? [];

            if (empty($rows)) {
                $this->line('Sin registros de muestra.');
                continue;
            }

            $headers = array_keys((array) $rows[0]);
            $tableRows = array_map(fn ($row) => array_values((array) $row), $rows);
            $this->table($headers, $tableRows);
        }

        return self::SUCCESS;
    }
}
