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
            $contacts = $result['contacts'] ?? [];
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
        ]);

        try {
            $wati = WatiApi::fromEnv();
            $response = $wati->addContact(trim($validated['phone']), trim($validated['name']));

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
}
