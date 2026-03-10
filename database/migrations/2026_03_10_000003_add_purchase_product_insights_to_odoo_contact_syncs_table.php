<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('odoo_contact_syncs', function (Blueprint $table) {
            $table->string('ultimo_producto_comprado')->nullable()->after('odoo_write_date');
            $table->string('producto_mas_comprado')->nullable()->after('ultimo_producto_comprado');
        });
    }

    public function down(): void
    {
        Schema::table('odoo_contact_syncs', function (Blueprint $table) {
            $table->dropColumn(['ultimo_producto_comprado', 'producto_mas_comprado']);
        });
    }
};
