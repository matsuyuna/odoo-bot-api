<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/probar-odoo', function () {
    $response = Http::post(env('ODOO_URL'), [
        'jsonrpc' => '2.0',
        'method' => 'call',
        'params' => [
            'service' => 'common',
            'method' => 'login',
            'args' => [
                env('ODOO_DB'),
                env('ODOO_USER'),
                env('ODOO_PASSWORD'),
            ],
        ],
        'id' => null,
    ]);

    if ($response->successful()) {
        $result = $response->json();

        return [
            'UID' => $result['result'] ?? 'Error al obtener UID',
        ];
    }

    return [
        'error' => $response->status(),
        'body' => $response->body(),
    ];
});