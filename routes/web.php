<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use App\Http\Controllers\WatiMonitorController;

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

Route::get('/wati/monitor', [WatiMonitorController::class, 'index'])->name('wati.monitor');
Route::post('/wati/monitor/contactos', [WatiMonitorController::class, 'storeContact'])->name('wati.monitor.store');
Route::get('/public/wati/monitor', [WatiMonitorController::class, 'index']);
Route::post('/public/wati/monitor/contactos', [WatiMonitorController::class, 'storeContact']);
