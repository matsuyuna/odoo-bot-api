<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OdooContactSync extends Model
{
    protected $table = 'odoo_contact_syncs';

    protected $fillable = [
        'odoo_contact_id',
        'name',
        'email',
        'phone',
        'mobile',
        'preferred_whatsapp',
        'vat',
        'is_company',
        'odoo_write_date',
        'wati_status',
        'wati_external_id',
        'wati_response',
        'last_error',
        'synced_to_wati_at',
    ];

    protected $casts = [
        'is_company' => 'boolean',
        'wati_response' => 'array',
        'odoo_write_date' => 'datetime',
        'synced_to_wati_at' => 'datetime',
    ];
}
