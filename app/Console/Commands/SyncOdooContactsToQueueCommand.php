<?php

namespace App\Console\Commands;

use App\Models\OdooContactSync;
use App\Services\OdooXmlRpc;
use App\Support\VenezuelanPhoneFormatter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

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
        $failed = 0;
        $failureReasons = [];

        try {
            $odoo = OdooXmlRpc::fromEnv();
        } catch (\Throwable $e) {
            $this->error('Error consultando Odoo: ' . $e->getMessage());
            $this->sendFailureSummaryEmail([
                'No se pudo inicializar la conexión con Odoo: ' . $e->getMessage(),
            ]);

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
                $this->sendFailureSummaryEmail(array_merge($failureReasons, [
                    'Error consultando lote en Odoo (offset ' . $offset . '): ' . $e->getMessage(),
                ]));

                return self::FAILURE;
            }

            if (empty($rows)) {
                break;
            }

            $batchNumber++;
            $this->info("Procesando lote {$batchNumber} (" . count($rows) . ' contactos, offset ' . $offset . ')');

            $partnerIds = array_values(array_filter(array_map(
                fn (array $row): int => (int) ($row['id'] ?? 0),
                $rows
            )));
            $purchaseInsightsByPartner = $odoo->getPartnerPurchaseInsights($partnerIds);

            foreach ($rows as $r) {
                if ($maxTotal > 0 && $processed >= $maxTotal) {
                    break 2;
                }

                $odooId = $r['id'] ?? null;
                if (!is_int($odooId)) {
                    continue;
                }

                $insights = $purchaseInsightsByPartner[$odooId] ?? null;

                $payload = [
                    'name' => $r['name'] ?? null,
                    'email' => $r['email'] ?? null,
                    'phone' => $r['phone'] ?? null,
                    'mobile' => $r['mobile'] ?? null,
                    'preferred_whatsapp' => $this->normalizePhone(
                        $r['phone'] ?? $r['mobile'] ?? null,
                        $this->isVenezuelanContact($r)
                    ),
                    'vat' => $r['vat'] ?? null,
                    'is_company' => (bool) ($r['is_company'] ?? false),
                    'odoo_write_date' => $this->parseDate($r['write_date'] ?? null),
                    'ultimo_producto_comprado' => $insights['ultimo_producto_comprado'] ?? '',
                    'producto_mas_comprado' => $insights['producto_mas_comprado'] ?? '',
                ];

                if (!$payload['preferred_whatsapp']) {
                    $failed++;
                    $failureReasons[] = 'Contacto Odoo ID ' . $odooId . ' sin phone/mobile válido; no se sincronizó.';
                    continue;
                }

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

        if ($failed > 0) {
            $this->warn("Contactos omitidos por datos inválidos: {$failed}");
            $this->sendFailureSummaryEmail($failureReasons);
        }

        $this->info("Contactos procesados desde Odoo. Total: {$processed}, nuevos: {$created}, actualizados: {$updated}");

        return self::SUCCESS;
    }

    private function normalizePhone(?string $value, bool $isVenezuelan = false): ?string
    {
        if (!$value) {
            return null;
        }

        if (!$isVenezuelan) {
            $normalized = preg_replace('/[^\d+]/', '', trim($value));
            return $normalized;
        }

        return VenezuelanPhoneFormatter::toWati($value);
    }

    private function isVenezuelanContact(array $row): bool
    {
        $country = $row['country_id'] ?? null;

        if (!is_array($country) || !isset($country[1]) || !is_string($country[1])) {
            return false;
        }

        return str_contains(mb_strtolower($country[1]), 'venezuela');
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

    /** @param array<int, string> $failureReasons */
    private function sendFailureSummaryEmail(array $failureReasons): void
    {
        $recipient = trim((string) config('services.odoo.sync_failure_email'));

        if ($recipient === '' || empty($failureReasons)) {
            return;
        }

        $subject = '[' . config('app.name') . '] Fallos en cron odoo:contacts:pull';
        $body = "Se detectaron fallos durante la sincronización de contactos de Odoo hacia WATI.\n\n";
        $body .= "Resumen:\n- " . implode("\n- ", array_values(array_unique($failureReasons)));

        try {
            Mail::raw($body, function ($message) use ($recipient, $subject) {
                $message->to($recipient)->subject($subject);
            });
        } catch (\Throwable $e) {
            $this->error('No se pudo enviar el correo de fallos: ' . $e->getMessage());
        }
    }
}
