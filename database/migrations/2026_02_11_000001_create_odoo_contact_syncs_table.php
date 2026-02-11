<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('odoo_contact_syncs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('odoo_contact_id')->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('mobile')->nullable();
            $table->string('preferred_whatsapp')->nullable();
            $table->string('vat')->nullable();
            $table->boolean('is_company')->default(false);
            $table->timestamp('odoo_write_date')->nullable();
            $table->string('wati_status')->default('pending'); // pending|sent|error
            $table->string('wati_external_id')->nullable();
            $table->json('wati_response')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('synced_to_wati_at')->nullable();
            $table->timestamps();

            $table->index(['wati_status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('odoo_contact_syncs');
    }
};
