<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BotProductoController;

Route::get('/buscar-producto', [BotProductoController::class, 'buscar']);