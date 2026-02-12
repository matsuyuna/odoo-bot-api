<?php

namespace App\Http\Controllers;

use App\Services\OdooXmlRpc;
use App\Services\WatiApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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

            $this->actualizarProductosEnWati($request, $respuesta);

            return response()->json($respuesta);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Error consultando Odoo',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function actualizarProductosEnWati(Request $request, array $productos): void
    {
        $whatsappNumber = trim((string) (
            $request->query('whatsapp_number')
            ?? $request->query('whatsappNumber')
            ?? $request->query('telefono')
            ?? $request->query('phone')
            ?? ''
        ));

        if ($whatsappNumber === '' || empty($productos)) {
            return;
        }

        $nombres = array_values(array_filter(array_map(
            fn (array $producto) => trim((string) ($producto['name'] ?? '')),
            $productos
        )));

        if (empty($nombres)) {
            return;
        }

        try {
            $wati = WatiApi::fromEnv();
            $wati->updateContactAttributes($whatsappNumber, [
                [
                    'name' => 'productos',
                    'value' => implode(', ', array_unique($nombres)),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::warning('No se pudieron actualizar los productos en WATI.', [
                'whatsapp_number' => $whatsappNumber,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
