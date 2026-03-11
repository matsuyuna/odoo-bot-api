<?php

namespace App\Console\Commands;

use App\Services\OdooXmlRpc;
use Illuminate\Console\Command;

class InspectOdooOrderModelsCommand extends Command
{
    protected $signature = 'odoo:orders:inspect
        {--partner-ids= : IDs de res.partner separados por coma (contactos/sucursales o empresa)}
        {--limit=5 : Máximo de filas por modelo para muestreo}';

    protected $description = 'Inspecciona modelos de pedidos/órdenes en Odoo para validar dónde hay historial de compra';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 30));
        $partnerIds = $this->parsePartnerIds((string) $this->option('partner-ids'));

        try {
            $odoo = OdooXmlRpc::fromEnv();
            $report = $odoo->inspectOrderRelatedModels($partnerIds, $limit);
        } catch (\Throwable $e) {
            $this->error('Error inspeccionando modelos de pedidos en Odoo: ' . $e->getMessage());

            return self::FAILURE;
        }

        foreach ($report as $item) {
            $this->newLine();
            $this->info('Modelo: ' . $item['model']);

            if (!empty($item['error'])) {
                $this->warn('No accesible: ' . $item['error']);
                continue;
            }

            if (!empty($item['input_partner_ids'])) {
                $this->line('Partner IDs recibidos: ' . implode(', ', $item['input_partner_ids']));
                $this->line('Commercial partner IDs usados: ' . implode(', ', $item['commercial_partner_ids']));
                $this->line('Mapa partner->commercial: ' . json_encode($item['partner_to_commercial'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            $this->line('Campos disponibles: ' . implode(', ', $item['available_fields']));
            $this->line('Dominio aplicado: ' . json_encode($item['domain'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            $rows = $item['sample_rows'] ?? [];
            if (empty($rows)) {
                $this->line('Sin registros de muestra.');
                continue;
            }

            $headers = array_keys((array) $rows[0]);
            $tableRows = array_map(function ($row) {
                return array_map(function ($cell) {
                    if (is_scalar($cell) || $cell === null) {
                        return $cell;
                    }

                    return json_encode($cell, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[unserializable]';
                }, array_values((array) $row));
            }, $rows);

            $this->table($headers, $tableRows);
        }

        return self::SUCCESS;
    }

    /** @return int[] */
    private function parsePartnerIds(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }

        $parts = preg_split('/\s*,\s*/', trim($raw)) ?: [];
        $ids = array_values(array_filter(array_map('intval', $parts), fn (int $id): bool => $id > 0));

        return array_values(array_unique($ids));
    }
}
