<?php

namespace App\Console\Commands;

use App\Models\OdooContactSync;
use App\Services\WatiApi;
use Illuminate\Console\Command;

class PushPendingContactsToWatiCommand extends Command
{
    protected $signature = 'wati:contacts:push {--limit=100 : Máximo de contactos a intentar enviar} {--retry-errors : Incluye registros en error}';

    protected $description = 'Envía a WATI los contactos pendientes guardados en la base local';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));

        try {
            $wati = WatiApi::fromEnv();
        } catch (\Throwable $e) {
            $this->error('Error de configuración WATI: ' . $e->getMessage());
            return self::FAILURE;
        }

        $query = OdooContactSync::query()->orderBy('id');

        if ($this->option('retry-errors')) {
            $query->whereIn('wati_status', ['pending', 'error']);
        } else {
            $query->where('wati_status', 'pending');
        }

        $contacts = $query->limit($limit)->get();

        if ($contacts->isEmpty()) {
            $this->info('No hay contactos pendientes por enviar a WATI.');
            return self::SUCCESS;
        }

        $sent = 0;
        $failed = 0;

        foreach ($contacts as $contact) {
            $phone = $contact->preferred_whatsapp;
            if (!$phone) {
                $contact->wati_status = 'error';
                $contact->last_error = 'Contacto sin teléfono/móvil para enviar a WATI.';
                $contact->save();
                $failed++;
                continue;
            }

            try {
                $result = $wati->addContact(
                    $phone,
                    $contact->name ?: 'Sin nombre',
                    [
                        ['name' => 'email', 'value' => (string) ($contact->email ?? '')],
                        ['name' => 'vat', 'value' => (string) ($contact->vat ?? '')],
                        ['name' => 'odoo_contact_id', 'value' => (string) $contact->odoo_contact_id],
                    ]
                );

                $contact->wati_status = 'sent';
                $contact->wati_response = $result;
                $contact->last_error = null;
                $contact->synced_to_wati_at = now();
                $contact->save();

                $sent++;
            } catch (\Throwable $e) {
                $contact->wati_status = 'error';
                $contact->last_error = $e->getMessage();
                $contact->save();
                $failed++;
            }
        }

        $this->info("Sincronización WATI finalizada. Enviados: {$sent}, fallidos: {$failed}");

        return self::SUCCESS;
    }
}
