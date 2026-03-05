<?php

namespace App\Console\Commands;

use App\Services\OdooXmlRpc;
use Illuminate\Console\Command;

class InspectOdooContactPhoneCountryCommand extends Command
{
    protected $signature = 'odoo:contacts:inspect-phone-country {--limit=10 : Cantidad de contactos a revisar}';

    protected $description = 'Revisa en Odoo si existen campos de país de origen del teléfono en contactos';

    public function handle(): int
    {
        $limit = max(1, min((int) $this->option('limit'), 100));

        try {
            $odoo = OdooXmlRpc::fromEnv();
            $audit = $odoo->inspectPhoneCountryFields($limit);
        } catch (\Throwable $e) {
            $this->error('Error auditando contactos en Odoo: ' . $e->getMessage());

            return self::FAILURE;
        }

        $fields = $audit['fields_available'] ?? [];
        $rows = $audit['inspected_contacts'] ?? [];

        $this->info('Campos disponibles para auditoría: ' . implode(', ', $fields));
        $this->newLine();

        if (empty($rows)) {
            $this->warn('No se obtuvieron contactos para inspección.');

            return self::SUCCESS;
        }

        $tableRows = [];
        foreach ($rows as $row) {
            $country = $row['country_id'] ?? null;
            $countryLabel = is_array($country) ? (($country[1] ?? null) ?: (string) ($country[0] ?? '')) : (string) $country;

            $tableRows[] = [
                'id' => $row['id'] ?? null,
                'name' => $row['name'] ?? null,
                'phone' => $row['phone'] ?? null,
                'mobile' => $row['mobile'] ?? null,
                'country_id' => $countryLabel,
                'phone_country_code' => $row['phone_country_code'] ?? null,
            ];
        }

        $this->table(['id', 'name', 'phone', 'mobile', 'country_id', 'phone_country_code'], $tableRows);

        return self::SUCCESS;
    }
}
