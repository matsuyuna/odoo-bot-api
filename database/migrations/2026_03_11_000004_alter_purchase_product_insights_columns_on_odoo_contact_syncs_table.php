<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE `odoo_contact_syncs` MODIFY `ultimo_producto_comprado` TEXT NULL');
        DB::statement('ALTER TABLE `odoo_contact_syncs` MODIFY `producto_mas_comprado` TEXT NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE `odoo_contact_syncs` MODIFY `ultimo_producto_comprado` VARCHAR(255) NULL');
        DB::statement('ALTER TABLE `odoo_contact_syncs` MODIFY `producto_mas_comprado` VARCHAR(255) NULL');
    }
};
