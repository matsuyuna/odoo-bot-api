<?php

namespace App\Http\Controllers;

use App\Models\BcvRate;
use App\Services\OdooXmlRpc;
use App\Services\WatiApi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class BotProductoController extends Controller
{
    public function buscar_objcompleto(Request $request)
    {
        $nombre = trim($request->query('nombre', ''));

        if ($nombre === '') {
            return response()->json(['error' => 'Falta el parámetro "nombre"'], 400);
        }

        try {
            $odoo = OdooXmlRpc::fromEnv();
            $productos = $odoo->searchProductsSmart($nombre, 20);
            $latestRates = $this->getLatestBcvRates();

            $respuesta = array_map(function (array $producto) use ($latestRates) {
                $qtyAvailable = (float) ($producto['qty_available'] ?? 0);
                $price = (float) ($producto['price'] ?? 0);
                $priceWithTaxTodayRate = is_null($producto['price_with_tax_today_rate'] ?? null)
                    ? null
                    : (float) $producto['price_with_tax_today_rate'];

                $precioResCurrencyRate = is_null($latestRates['res_currency_rate'])
                    ? null
                    : round($price * (float) $latestRates['res_currency_rate'], 2);
                $precioResCurrency = is_null($latestRates['res_currency'])
                    ? null
                    : round($price * (float) $latestRates['res_currency'], 2);

                $precioResCurrencyRateTexto = is_null($precioResCurrencyRate)
                    ? 'No disponible'
                    : number_format($precioResCurrencyRate, 2, ',', '.') . ' bs';
                $precioResCurrencyTexto = is_null($precioResCurrency)
                    ? 'No disponible'
                    : number_format((float) round($precioResCurrency), 0, ',', '.') . ' bs';

                return [
                    'id' => $producto['id'] ?? null,
                    'name' => $producto['name'] ?? null,
                    'default_code' => $producto['default_code'] ?? null,
                    'barcode' => $producto['barcode'] ?? null,
                    'qty_available' => $qtyAvailable,
                    'price' => $price,
                    'price_with_tax_today_rate' => $priceWithTaxTodayRate,
                    'precio_res_currency_rate' => $precioResCurrencyRate,
                    'precio_res_currency' => $precioResCurrency,
                    'availability_text_res_currency_rate' => sprintf(
                        '%s - %s - Precio %s',
                        $producto['name'] ?? 'Producto sin nombre',
                        $qtyAvailable > 0 ? 'Sí hay disponible' : 'No hay disponible',
                        $precioResCurrencyRateTexto,
                    ),
                    'availability_text_res_currency' => sprintf(
                        '%s - %s - Precio %s',
                        $producto['name'] ?? 'Producto sin nombre',
                        $qtyAvailable > 0 ? 'Sí hay disponible' : 'No hay disponible',
                        $precioResCurrencyTexto,
                    ),
                ];
            }, array_slice($productos, 0, 10));

            $this->actualizarProductosEnWati($request, $respuesta);

            return response()->json($respuesta);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Error consultando Odoo',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function buscar(Request $request)
    {
        $nombre = trim($request->query('nombre', ''));

        if ($nombre === '') {
            return response()->json(['error' => 'Falta el parámetro "nombre"'], 400);
        }

        try {
            $odoo = OdooXmlRpc::fromEnv();
            $productos = $odoo->searchProductsSmart($nombre, 20);
            $latestRates = $this->getLatestBcvRates();

            $respuesta = array_map(function (array $producto) use ($latestRates) {
                $qtyAvailable = (float) ($producto['qty_available'] ?? 0);
                $price = (float) ($producto['price'] ?? 0);
                $priceWithTaxTodayRate = is_null($producto['price_with_tax_today_rate'] ?? null)
                    ? null
                    : (float) $producto['price_with_tax_today_rate'];

                $precioResCurrencyRate = is_null($latestRates['res_currency_rate'])
                    ? null
                    : round($price * (float) $latestRates['res_currency_rate'], 2);
                $precioResCurrency = is_null($latestRates['res_currency'])
                    ? null
                    : round($price * (float) $latestRates['res_currency'], 2);

                $precioResCurrencyRateTexto = is_null($priceWithTaxTodayRate)
                    ? 'No disponible'
                    : number_format($priceWithTaxTodayRate, 2, ',', '.') . ' bs';
                $precioResCurrencyTexto = is_null($precioResCurrency)
                    ? 'No disponible'
                    : number_format((float) round($precioResCurrency), 0, ',', '.') . ' bs';

                return [
                    'id' => $producto['id'] ?? null,
                    'name' => $producto['name'] ?? null,
                    'default_code' => $producto['default_code'] ?? null,
                    'barcode' => $producto['barcode'] ?? null,
                    'qty_available' => $qtyAvailable,
                    'price' => $price,
                    'price_with_tax_today_rate' => $priceWithTaxTodayRate,
                    'precio_res_currency_rate' => $precioResCurrencyRate,
                    'precio_res_currency' => $precioResCurrency,
                    'availability_text_res_currency_rate' => sprintf(
                        '%s - %s - Precio %s',
                        $producto['name'] ?? 'Producto sin nombre',
                        $qtyAvailable > 0 ? 'Sí hay disponible' : 'No hay disponible',
                        $precioResCurrencyRateTexto,
                    ),
                    'availability_text_res_currency' => sprintf(
                        '%s - %s - Precio %s',
                        $producto['name'] ?? 'Producto sin nombre',
                        $qtyAvailable > 0 ? 'Sí hay disponible' : 'No hay disponible',
                        $precioResCurrencyTexto,
                    ),
                ];
            }, array_slice($productos, 0, 10));

            $this->actualizarProductosEnWati($request, $respuesta);

            $availabilityTexts = array_values(array_filter(array_map(
                fn (array $producto) => str_replace(' - ', ' ', trim((string) ($producto['availability_text_res_currency_rate'] ?? ''))),
                $respuesta
            )));

            $availabilityText = empty($availabilityTexts)
                ? 'No encontramos ningún producto bajo esa descripción'
                : implode("\n\n", array_map(
                    fn (string $text) => '- ' . $text,
                    $availabilityTexts
                ));

            return response()->json([
                'availability_text' => $availabilityText,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'error' => 'Error consultando Odoo',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    private function getLatestBcvRates(): array
    {
        try {
            $rate = BcvRate::query()
                ->latest('date')
                ->first(['res_currency_rate', 'res_currency']);

            return [
                'res_currency_rate' => is_null($rate?->res_currency_rate) ? null : (float) $rate->res_currency_rate,
                'res_currency' => is_null($rate?->res_currency) ? null : (float) $rate->res_currency,
            ];
        } catch (Throwable $e) {
            Log::warning('No se pudieron cargar las últimas tasas BCV.', [
                'error' => $e->getMessage(),
            ]);

            return [
                'res_currency_rate' => null,
                'res_currency' => null,
            ];
        }
    }

    private function filterOutCopyProducts(array $productos): array
    {
        return array_values(array_filter(
            $productos,
            fn (array $producto) => ! $this->isCopyProductName((string) ($producto['name'] ?? ''))
        ));
    }

    private function isCopyProductName(string $name): bool
    {
        $normalizedName = preg_replace('/\s+/u', '', Str::lower(trim($name))) ?? '';

        return str_contains($normalizedName, '(copiar)');
    }

    public function inspeccionar(Request $request)
    {
        $input = [
            'product_template_id' => $request->query('product_template_id'),
            'product_product_id' => $request->query('product_product_id'),
            'product_name' => trim((string) ($request->query('product_name', $request->query('nombre', '')))),
            'lang' => trim((string) $request->query('lang', '')),
        ];

        $hasTemplateId = is_numeric($input['product_template_id']);
        $hasProductId = is_numeric($input['product_product_id']);
        $hasProductName = $input['product_name'] !== '';

        if (! $hasTemplateId && ! $hasProductId && ! $hasProductName) {
            return response()->json([
                'error' => 'Debes enviar al menos uno: product_template_id, product_product_id o product_name.',
            ], 400);
        }

        try {
            $odoo = OdooXmlRpc::fromEnv();
            $inspeccion = $odoo->inspectProductNameSources($input);

            if (empty($inspeccion)) {
                return response()->json([
                    'message' => 'No se encontró producto para inspeccionar.',
                    'input' => $input,
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
