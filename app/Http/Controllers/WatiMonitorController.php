<?php

namespace App\Http\Controllers;

use App\Services\WatiApi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WatiMonitorController extends Controller
{
    public function index(Request $request): View
    {
        $limit = max(1, min((int) $request->query('limit', 20), 100));
        $page = max(1, (int) $request->query('page', 1));

        $contacts = [];
        $error = null;
        $raw = null;

        try {
            $wati = WatiApi::fromEnv();
            $result = $wati->getContacts($limit, $page);
            $contacts = array_map(fn (array $contact) => $this->normalizeContactForMonitor($contact), $result['contacts'] ?? []);
            $raw = $result['raw'] ?? null;
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        return view('wati.monitor', [
            'contacts' => $contacts,
            'limit' => $limit,
            'page' => $page,
            'error' => $error,
            'raw' => $raw,
        ]);
    }

    public function storeContact(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'phone' => ['required', 'string', 'max:30'],
            'name' => ['required', 'string', 'max:120'],
            'producto_mas_comprado' => ['nullable', 'string', 'max:255'],
            'ultimo_producto_comprado' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $wati = WatiApi::fromEnv();
            $response = $wati->addContact(
                trim($validated['phone']),
                trim($validated['name']),
                $this->buildCustomParams($validated)
            );

            return redirect()
                ->route('wati.monitor')
                ->with('status', 'Contacto enviado a WATI correctamente.')
                ->with('wati_response', $response['body'] ?? null);
        } catch (\Throwable $e) {
            return redirect()
                ->route('wati.monitor')
                ->with('error', 'No se pudo enviar el contacto a WATI: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $validated
     * @return array<int,array{name:string,value:string}>
     */
    private function buildCustomParams(array $validated): array
    {
        return [
            ['name' => 'producto_mas_comprado', 'value' => trim((string) ($validated['producto_mas_comprado'] ?? ''))],
            ['name' => 'ultimo_producto_comprado', 'value' => trim((string) ($validated['ultimo_producto_comprado'] ?? ''))],
        ];
    }

    /**
     * @param array<string,mixed> $contact
     * @return array<string,mixed>
     */
    private function normalizeContactForMonitor(array $contact): array
    {
        $contact['producto_mas_comprado'] = $this->resolveCustomParam(
            $contact,
            ['producto_mas_comprado', 'productomascomprado']
        );
        $contact['ultimo_producto_comprado'] = $this->resolveCustomParam(
            $contact,
            ['ultimo_producto_comprado', 'ultimoproductocomprado']
        );

        return $contact;
    }

    /**
     * @param array<string,mixed> $contact
     * @param array<int,string> $keys
     */
    private function resolveCustomParam(array $contact, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($contact[$key]) && is_scalar($contact[$key])) {
                return trim((string) $contact[$key]);
            }
        }

        $customParams = $contact['customParams'] ?? $contact['custom_parameters'] ?? [];
        if (!is_array($customParams)) {
            return '';
        }

        foreach ($customParams as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = strtolower((string) ($item['name'] ?? ''));
            $value = trim((string) ($item['value'] ?? ''));

            if (in_array($name, array_map('strtolower', $keys), true)) {
                return $value;
            }
        }

        return '';
    }
}
