<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BcvRateController;
use App\Http\Controllers\BotContactoController;
use App\Http\Controllers\BotProductoController;

Route::get('/buscar-producto', [BotProductoController::class, 'buscar']);
Route::get('/buscar-producto-objcompleto', [BotProductoController::class, 'buscar_objcompleto']);
Route::get('/buscar-contacto', [BotContactoController::class, 'buscar']);

// Route deshabilitada temporalmente: inspección de producto
// Route::get('/inspeccionar-producto', [BotProductoController::class, 'inspeccionar']);

Route::get('/bcv-rates/latest', [BcvRateController::class, 'latest']);
