<?php

namespace App\Http\Controllers;

use App\Services\OdooXmlRpc;
use Illuminate\Http\Request;

class BotContactoController extends Controller
{
    public function buscar(Request $request)
    {
        $nombre = trim($request->query('nombre', ''));
        $limit = max(1, min((int) $request->query('limit', 50), 200));

        try {
            $odoo = OdooXmlRpc::fromEnv();
            $contactos = $odoo->searchContactsSmart($nombre, $limit);

            return response()->json($contactos);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Error consultando contactos en Odoo',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}

