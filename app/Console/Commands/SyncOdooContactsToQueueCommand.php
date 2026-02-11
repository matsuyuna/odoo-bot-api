<?php

namespace App\Console\Commands;

use App\Models\OdooContactSync;
use App\Services\OdooXmlRpc;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SyncOdooContactsToQueueCommand extends Command
{
    protected $signature = 'odoo:contacts:pull {--limit=200 : Máximo de contactos a traer de Odoo}';

    protected $description = 'Trae contactos recientes de Odoo y los deja en cola local para sincronizar a WATI';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');

        try {
            $odoo = OdooXmlRpc::fromEnv();
            $rows = $odoo->fetchRecentContacts($limit);
        } catch (\Throwable $e) {
            $this->error('Error consultando Odoo: ' . $e->getMessage());
            return self::FAILURE;
        }

        $created = 0;
        $updated = 0;

        foreach ($rows as $r) {
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
                continue;
            }

            $existing->fill($payload);
            if ($existing->wati_status !== 'sent') {
                $existing->wati_status = 'pending';
            }
            $existing->save();
            $updated++;
        }

        $this->info("Contactos procesados desde Odoo. Nuevos: {$created}, actualizados: {$updated}");

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
