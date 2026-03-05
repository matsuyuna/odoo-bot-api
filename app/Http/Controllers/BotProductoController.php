<?php

namespace App\Http\Controllers;

use App\Models\BcvRate;
use App\Services\OdooXmlRpc;
use App\Services\WatiApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

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
            $latestRate = $this->getLatestBcvRate();

            $respuesta = array_map(function (array $producto) use ($latestRate) {
                $qtyAvailable = (float) ($producto['qty_available'] ?? 0);
                $price = (float) ($producto['price'] ?? 0);
                $precioBcv = is_null($latestRate) ? null : round($price * $latestRate, 2);
                $precioEnTexto = is_null($precioBcv)
                    ? 'No disponible'
                    : number_format((float) round($precioBcv), 0, ',', '.') . ' Bs';

                return [
                    'id' => $producto['id'] ?? null,
                    'name' => $producto['name'] ?? null,
                    'default_code' => $producto['default_code'] ?? null,
                    'barcode' => $producto['barcode'] ?? null,
                    'qty_available' => $qtyAvailable,
                    'price' => $price,
                    'precio_bcv' => $precioBcv,
                    'availability_text' => sprintf(
                        '%s - %s - Precio: %s',
                        $producto['name'] ?? 'Producto sin nombre',
                        $qtyAvailable > 0 ? 'Si hay disponible' : 'No hay disponible',
                        $precioEnTexto,
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

    private function getLatestBcvRate(): ?float
    {
        try {
            $rate = BcvRate::query()->latest('date')->value('dollar');

            return is_null($rate) ? null : (float) $rate;
        } catch (Throwable $e) {
            Log::warning('No se pudo cargar la última tasa BCV.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function inspeccionar(Request $request)
    {
        $nombre = trim($request->query('nombre', ''));

        if ($nombre === '') {
            return response()->json(['error' => 'Falta el parámetro "nombre"'], 400);
        }

        try {
            $odoo = OdooXmlRpc::fromEnv();
            $inspeccion = $odoo->inspectProductByName($nombre);

            if (empty($inspeccion)) {
                return response()->json([
                    'message' => 'No se encontró producto para inspeccionar.',
                    'query' => $nombre,
                ], 404);
            }

            return response()->json($inspeccion);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Error inspeccionando producto en Odoo',
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
