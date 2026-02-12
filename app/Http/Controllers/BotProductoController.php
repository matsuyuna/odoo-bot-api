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
            $productos = $odoo->searchProductsSmart($nombre, 7);

            $respuesta = array_map(function (array $producto) {
                $qtyAvailable = (float) ($producto['qty_available'] ?? 0);

                return [
                    'id' => $producto['id'] ?? null,
                    'name' => $producto['name'] ?? null,
                    'default_code' => $producto['default_code'] ?? null,
                    'barcode' => $producto['barcode'] ?? null,
                    'qty_available' => $qtyAvailable,
                    'availability_text' => sprintf(
                        '%s - %s',
                        $producto['name'] ?? 'Producto sin nombre',
                        $qtyAvailable > 0 ? 'Si hay disponible' : 'no hay disponible'
                    ),
                ];
            }, array_slice($productos, 0, 7));

            return response()->json($respuesta);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Error consultando Odoo',
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}
