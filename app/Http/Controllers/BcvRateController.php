<?php

namespace App\Http\Controllers;

use App\Models\BcvRate;

class BcvRateController extends Controller
{
    public function latest()
    {
        $rate = BcvRate::query()->latest('date')->first();

        if (!$rate) {
            return response()->json([
                'message' => 'No hay tasas BCV sincronizadas.',
            ], 404);
        }

        return response()->json([
            'date' => $rate->date,
            'res_currency_rate' => (float) $rate->res_currency_rate,
            'res_currency' => (float) $rate->res_currency,
            'updated_at' => $rate->updated_at,
        ]);
    }
}
