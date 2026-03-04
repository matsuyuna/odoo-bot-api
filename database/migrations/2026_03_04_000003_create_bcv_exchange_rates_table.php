<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bcv_exchange_rates', function (Blueprint $table) {
            $table->id();
            $table->date('rate_date')->unique();
            $table->decimal('usd_rate', 14, 4);
            $table->string('source', 20)->default('export');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bcv_exchange_rates');
    }
};
