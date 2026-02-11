<?php

namespace App\Http\Controllers;

use App\Services\OdooXmlRpc;
use Illuminate\Http\Request;

class BotProductoController extends Controller
{
    public function buscar(Request $request)
    {
        $nombre = trim($request->query('nombre', ''));

        if ($nombre === '') {
            return response()->json(['error' => 'Falta el parámetro "nombre"'], 400);
        }

        try {
            $odoo = OdooXmlRpc::fromEnv();
            $productos = $odoo->searchProductsSmart($nombre, 20);

            return response()->json($productos);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Error consultando Odoo',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
