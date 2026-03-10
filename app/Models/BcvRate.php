<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BcvRate extends Model
{
    protected $fillable = [
        'date',
        'res_currency_rate',
        'res_currency',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'res_currency_rate' => 'float',
            'res_currency' => 'float',
        ];
    }
}
