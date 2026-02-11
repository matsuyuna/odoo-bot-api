<?php

namespace App\Console\Commands;

use App\Models\OdooContactSync;
use App\Services\OdooXmlRpc;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncOdooContactsToQueueCommand extends Command
{
    protected $signature = 'odoo:contacts:pull \
        {--batch-size=500 : Tamaño de lote por petición a Odoo (recomendado 200-1000)} \
        {--max-total=0 : Máximo total de contactos a procesar. 0 = sin límite}';

    protected $description = 'Trae contactos recientes de Odoo y los deja en cola local para sincronizar a WATI';

    public function handle(): int
    {
        $batchSize = max(1, min((int) $this->option('batch-size'), 1000));
        $maxTotal = max(0, (int) $this->option('max-total'));

        try {
            $odoo = OdooXmlRpc::fromEnv();
        } catch (\Throwable $e) {
            $this->error('Error consultando Odoo: ' . $e->getMessage());
            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;
        $processed = 0;
        $offset = 0;
        $batchNumber = 0;

        while (true) {
            try {
                $rows = $odoo->fetchRecentContacts($batchSize, $offset);
            } catch (\Throwable $e) {
                $this->error('Error consultando lote en Odoo (offset ' . $offset . '): ' . $e->getMessage());
                return self::FAILURE;
            }

            if (empty($rows)) {
                break;
            }

            $batchNumber++;
            $this->info("Procesando lote {$batchNumber} (" . count($rows) . ' contactos, offset ' . $offset . ')');

            foreach ($rows as $r) {
                if ($maxTotal > 0 && $processed >= $maxTotal) {
                    break 2;
                }

                $odooId = $r['id'] ?? null;
                if (!is_int($odooId)) {
                    continue;
                }

                $payload = [
                    'name' => $r['name'] ?? null,
                    'email' => $r['email'] ?? null,
                    'phone' => $r['phone'] ?? null,
                    'mobile' => $r['mobile'] ?? null,
                    'preferred_whatsapp' => $this->normalizePhone($r['mobile'] ?? $r['phone'] ?? null),
                    'vat' => $r['vat'] ?? null,
                    'is_company' => (bool) ($r['is_company'] ?? false),
                    'odoo_write_date' => $this->parseDate($r['write_date'] ?? null),
                ];

                /** @var OdooContactSync|null $existing */
                $existing = OdooContactSync::query()->where('odoo_contact_id', $odooId)->first();

                if (!$existing) {
                    OdooContactSync::query()->create(array_merge($payload, [
                        'odoo_contact_id' => $odooId,
                        'wati_status' => 'pending',
                    ]));
                    $created++;
                    $processed++;
                    continue;
                }

                $existing->fill($payload);
                if ($existing->wati_status !== 'sent') {
                    $existing->wati_status = 'pending';
                }
                $existing->save();
                $updated++;
                $processed++;
            }

            $offset += $batchSize;

            // Si vino menos que batch-size ya alcanzamos el final.
            if (count($rows) < $batchSize) {
                break;
            }
        }

        $this->info("Contactos procesados desde Odoo. Total: {$processed}, nuevos: {$created}, actualizados: {$updated}");

        return self::SUCCESS;
    }

    private function normalizePhone(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', trim($value));

        return $normalized ?: null;
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
