<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BcvRate extends Model
{
    protected $fillable = [
        'date',
        'dollar',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'dollar' => 'float',
        ];
    }
}
