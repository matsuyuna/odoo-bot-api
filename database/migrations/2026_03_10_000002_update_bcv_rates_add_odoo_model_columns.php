<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bcv_rates', function (Blueprint $table) {
            $table->renameColumn('dollar', 'res_currency_rate');
            $table->decimal('res_currency', 12, 4)->nullable()->after('res_currency_rate');
        });
    }

    public function down(): void
    {
        Schema::table('bcv_rates', function (Blueprint $table) {
            $table->dropColumn('res_currency');
            $table->renameColumn('res_currency_rate', 'dollar');
        });
    }
};
