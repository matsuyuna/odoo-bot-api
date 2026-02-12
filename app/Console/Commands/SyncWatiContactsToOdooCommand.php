<?php

namespace App\Console\Commands;

use App\Models\OdooContactSync;
use App\Services\OdooXmlRpc;
use App\Services\WatiApi;
use Illuminate\Console\Command;

class SyncWatiContactsToOdooCommand extends Command
{
    protected $signature = 'wati:contacts:pull {--page-size=100 : Tamaño de página para getContacts} {--max-pages=1 : Máximo de páginas a consultar (0 = sin límite)}';

    protected $description = 'Importa contactos de WATI; si no existen localmente, los crea y los sincroniza a Odoo';

    public function handle(): int
    {
        $pageSize = max(1, min((int) $this->option('page-size'), 500));
        $maxPages = max(0, (int) $this->option('max-pages'));

        try {
            $wati = WatiApi::fromEnv();
            $odoo = OdooXmlRpc::fromEnv();
        } catch (\Throwable $e) {
            $this->error('Error de configuración: ' . $e->getMessage());
            return self::FAILURE;
        }

        $page = 1;
        $inserted = 0;
        $alreadyExists = 0;
        $odooCreated = 0;
        $odooFailures = 0;

        while (true) {
            if ($maxPages > 0 && $page > $maxPages) {
                break;
            }

            try {
                $result = $wati->getContacts($pageSize, $page);
            } catch (\Throwable $e) {
                $this->error('Error consultando WATI en página ' . $page . ': ' . $e->getMessage());
                return self::FAILURE;
            }

            $contacts = $result['contacts'];
            if (empty($contacts)) {
                break;
            }

            foreach ($contacts as $row) {
                $name = $this->pickString($row, ['name', 'fullName', 'contactName']) ?? 'Sin nombre';
                $email = $this->pickString($row, ['email']);
                $phone = $this->normalizePhone($this->pickString($row, ['whatsappNumber', 'phone', 'phone_number', 'mobile']));

                if (!$phone) {
                    continue;
                }

                $exists = OdooContactSync::query()
                    ->where('preferred_whatsapp', $phone)
                    ->orWhere('phone', $phone)
                    ->orWhere('mobile', $phone)
                    ->exists();

                if ($exists) {
                    $alreadyExists++;
                    continue;
                }

                $newRecord = new OdooContactSync([
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'mobile' => $phone,
                    'preferred_whatsapp' => $phone,
                    'wati_status' => 'sent',
                    'wati_response' => ['source' => 'wati_get_contacts', 'row' => $row],
                    'synced_to_wati_at' => now(),
                ]);

                try {
                    $odooId = $odoo->findContactIdByPhoneOrEmail($phone, $email);

                    if (!$odooId) {
                        $odooId = $odoo->createContact([
                            'name' => $name,
                            'email' => $email,
                            'phone' => $phone,
                            'mobile' => $phone,
                        ]);
                        $odooCreated++;
                    }

                    $newRecord->odoo_contact_id = $odooId;
                    $newRecord->last_error = null;
                } catch (\Throwable $e) {
                    $newRecord->last_error = 'No se pudo sincronizar a Odoo: ' . $e->getMessage();
                    $odooFailures++;
                }

                $newRecord->save();
                $inserted++;
            }

            $hasMore = (bool) ($result['has_more'] ?? false);
            if (!$hasMore || count($contacts) < $pageSize) {
                break;
            }

            $page++;
        }

        $this->info("Importación desde WATI finalizada. Insertados: {$inserted}, ya existentes: {$alreadyExists}, creados en Odoo: {$odooCreated}, fallos en Odoo: {$odooFailures}");

        return self::SUCCESS;
    }

    private function pickString(array $row, array $keys): ?string
    {
        foreach ($keys as $k) {
            $v = $row[$k] ?? null;
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return null;
    }

    private function normalizePhone(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        $normalized = preg_replace('/[^\d+]/', '', trim($value));

        return $normalized ?: null;
    }
}
