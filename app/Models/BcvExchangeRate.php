<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BcvExchangeRate extends Model
{
    protected $fillable = [
        'rate_date',
        'usd_rate',
        'source',
    ];

    protected $casts = [
        'rate_date' => 'date',
        'usd_rate' => 'decimal:4',
    ];
}
