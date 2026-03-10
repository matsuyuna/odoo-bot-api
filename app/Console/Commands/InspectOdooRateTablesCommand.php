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
            $tableRows = array_map(function ($row) {
                return array_map(fn ($cell) => $this->normalizeTableCell($cell), array_values((array) $row));
            }, $rows);
            $this->table($headers, $tableRows);
        }

        return self::SUCCESS;
    }

    private function normalizeTableCell(mixed $value): string|int|float|bool|null
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            ?: '[unserializable]';
    }
}
