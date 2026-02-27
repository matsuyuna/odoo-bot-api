<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OdooXmlRpc
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $db,
        private readonly string $username,
        private readonly string $password,
    ) {}

    public static function fromEnv(): self
    {
        $baseUrl = rtrim((string) env('ODOO_URL', ''), '/');
        $db = (string) env('ODOO_DB', '');
        $username = (string) env('ODOO_USERNAME', '');
        $password = (string) env('ODOO_PASSWORD', '');

        if (!$baseUrl || !$db || !$username || !$password) {
            throw new RuntimeException('Faltan variables de entorno de Odoo (ODOO_URL, ODOO_DB, ODOO_USERNAME, ODOO_PASSWORD).');
        }

        return new self($baseUrl, $db, $username, $password);
    }

    /** UID cacheado para no autenticar en cada request */
    public function getUid(): int
    {
        $cacheKey = 'odoo_uid:' . sha1($this->baseUrl . '|' . $this->db . '|' . $this->username);

        return Cache::remember($cacheKey, now()->addMinutes(15), function () {
            $xml = $this->buildMethodCall('authenticate', [
                $this->db,
                $this->username,
                $this->password,
                [], // context
            ]);

            $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/common', $xml);
            $parsed = $this->parseXmlRpc($raw);

            if ($parsed === false || !is_int($parsed) || $parsed <= 0) {
                throw new RuntimeException('Credenciales inválidas o Odoo no retornó UID.');
            }

            return $parsed;
        });
    }

    /**
     * ✅ Mejor buscador:
     * - tokeniza query ("acetaminofen de 500" -> ["acetaminofen","500"])
     * - hace name_search por cada token
     * - intersecta resultados (AND)
     * - luego hace read() para traer campos + stock
     */
    public function searchProductsSmart(string $query, int $limit = 20): array
    {
        $tokens = $this->tokenizeQuery($query);

        if (!$tokens) {
            return [];
        }

        $idSets = [];
        foreach ($tokens as $t) {
            $pairs = $this->nameSearch('product.product', $t, $limit * 3); // más amplio para poder intersectar
            $ids = array_values(array_filter(array_map(fn($p) => $p[0] ?? null, $pairs), fn($x) => is_int($x)));
            $idSets[] = $ids;
        }

        // AND: ids comunes a todos los tokens
        $idsFinal = $idSets[0] ?? [];
        foreach (array_slice($idSets, 1) as $set) {
            $idsFinal = array_values(array_intersect($idsFinal, $set));
        }

        // Si no hubo intersección, fallback: usa solo el primer token (mejor UX)
        if (!$idsFinal) {
            $pairs = $this->nameSearch('product.product', $tokens[0], $limit * 2);
            $idsFinal = array_values(array_filter(array_map(fn($p) => $p[0] ?? null, $pairs), fn($x) => is_int($x)));
        }

        $idsFinal = array_slice($idsFinal, 0, $limit);

        if (!$idsFinal) return [];

        // read para traer campos
        $rows = $this->read('product.product', $idsFinal, [
            'id', 'name', 'default_code', 'qty_available', 'barcode', 'lst_price'
        ]);

        $qtyByProductId = [];
        $storeLocationIds = $this->getStoreLocationIds();

        if (!empty($storeLocationIds)) {
            $qtyByProductId = $this->readQtyAvailableByLocations($idsFinal, $storeLocationIds);
        }

        // Normaliza salida
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => $r['id'] ?? null,
                'name' => $r['name'] ?? null,
                'default_code' => $r['default_code'] ?? null,
                'barcode' => $r['barcode'] ?? null,
                'qty_available' => $qtyByProductId[$r['id'] ?? 0] ?? ($r['qty_available'] ?? 0),
                'price' => $r['lst_price'] ?? 0,
            ];
        }

        return $out;
    }

    /**
     * Busca contactos en Odoo (res.partner) por nombre/email/teléfono.
     * Si no hay query, retorna una lista reciente de contactos activos.
     */
    public function searchContactsSmart(string $query = '', int $limit = 50): array
    {
        $uid = $this->getUid();
        $limit = max(1, min($limit, 200));

        $domain = [['active', '=', true]];
        $query = trim($query);

        if ($query !== '') {
            $domain = [
                ['active', '=', true],
                '|', '|',
                ['name', 'ilike', $query],
                ['email', 'ilike', $query],
                ['phone', 'ilike', $query],
            ];
        }

        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            'res.partner',
            'search_read',
            [$domain],
            [
                'fields' => ['id', 'name', 'email', 'phone', 'mobile', 'vat', 'is_company'],
                'limit' => $limit,
                'order' => 'write_date desc',
            ],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);
        $rows = is_array($parsed) ? $parsed : [];

        $out = [];
        foreach ($rows as $r) {
            $phone = $r['mobile'] ?? $r['phone'] ?? null;
            $out[] = [
                'id' => $r['id'] ?? null,
                'name' => $r['name'] ?? null,
                'email' => $r['email'] ?? null,
                'phone' => $r['phone'] ?? null,
                'mobile' => $r['mobile'] ?? null,
                'vat' => $r['vat'] ?? null,
                'is_company' => $r['is_company'] ?? false,
                // campo útil para luego mapear a WATI
                'preferred_whatsapp' => $phone,
            ];
        }

        return $out;
    }

    /**
     * Trae un lote paginado de contactos recientes de Odoo para sincronización.
     */
    public function fetchRecentContacts(int $limit = 500, int $offset = 0): array
    {
        $uid = $this->getUid();
        $limit = max(1, min($limit, 1000));
        $offset = max(0, $offset);

        $domain = [
            ['active', '=', true],
            '|',
            ['phone', '!=', false],
            ['mobile', '!=', false],
        ];

        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            'res.partner',
            'search_read',
            [$domain],
            [
                'fields' => ['id', 'name', 'email', 'phone', 'mobile', 'vat', 'is_company', 'write_date', 'create_date'],
                'limit' => $limit,
                'offset' => $offset,
                // Orden estable para paginación con offset
                'order' => 'id asc',
            ],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);

        return is_array($parsed) ? $parsed : [];
    }

    

    public function findContactIdByPhoneOrEmail(?string $phone, ?string $email): ?int
    {
        $uid = $this->getUid();

        $phone = is_string($phone) ? trim($phone) : null;
        $email = is_string($email) ? trim($email) : null;

        if (!$phone && !$email) {
            return null;
        }

        $domain = [['active', '=', true]];

        if ($phone && $email) {
            $domain = [
                ['active', '=', true],
                '|', '|',
                ['phone', '=', $phone],
                ['mobile', '=', $phone],
                ['email', '=', $email],
            ];
        } elseif ($phone) {
            $domain = [
                ['active', '=', true],
                '|',
                ['phone', '=', $phone],
                ['mobile', '=', $phone],
            ];
        } elseif ($email) {
            $domain = [
                ['active', '=', true],
                ['email', '=', $email],
            ];
        }

        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            'res.partner',
            'search',
            [$domain],
            [
                'limit' => 1,
                'order' => 'id asc',
            ],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);

        if (is_array($parsed) && isset($parsed[0]) && is_int($parsed[0])) {
            return $parsed[0];
        }

        return null;
    }

    public function createContact(array $values): int
    {
        $uid = $this->getUid();

        $payload = [
            'name' => (string) ($values['name'] ?? 'Sin nombre'),
            'email' => $values['email'] ?? null,
            'phone' => $values['phone'] ?? null,
            'mobile' => $values['mobile'] ?? null,
            'customer_rank' => 1,
        ];

        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            'res.partner',
            'create',
            [$payload],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);

        if (!is_int($parsed) || $parsed <= 0) {
            throw new RuntimeException('Odoo no devolvió un ID válido al crear contacto.');
        }

        return $parsed;
    }

    /** name_search(model, name, operator=ilike, limit=N) -> [[id,"Display Name"], ...] */
    private function nameSearch(string $model, string $term, int $limit = 40): array
    {
        $uid = $this->getUid();

        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            $model,
            'name_search',
            [$term], // term
            [
                'operator' => 'ilike',
                'limit' => $limit,
            ],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);

        return is_array($parsed) ? $parsed : [];
    }

    /** read(model, ids, fields) -> array de dicts */
    private function read(string $model, array $ids, array $fields): array
    {
        $uid = $this->getUid();

        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            $model,
            'read',
            [$ids],
            ['fields' => $fields],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * @return int[]
     */
    private function getStoreLocationIds(): array
    {
        $raw = trim((string) env('ODOO_STORE_LOCATION_IDS', ''));

        if ($raw === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $raw));
        $ids = [];

        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                continue;
            }

            $id = (int) $part;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Suma qty_available por producto en los depósitos de tienda configurados.
     * Hace una sola consulta a stock.quant para reducir carga hacia Odoo.
     *
     * @param int[] $productIds
     * @param int[] $locationIds
     * @return array<int,float>
     */
    private function readQtyAvailableByLocations(array $productIds, array $locationIds): array
    {
        $rows = $this->searchRead('stock.quant', [
            ['product_id', 'in', $productIds],
            ['location_id', 'child_of', $locationIds],
        ], ['product_id', 'available_quantity'], 5000);

        $totals = [];

        foreach ($rows as $row) {
            $productField = $row['product_id'] ?? null;

            if (!is_array($productField) || !isset($productField[0]) || !is_int($productField[0])) {
                continue;
            }

            $productId = $productField[0];
            $totals[$productId] = ($totals[$productId] ?? 0.0) + (float) ($row['available_quantity'] ?? 0);
        }

        return $totals;
    }

    /** search_read(model, domain, fields, limit) -> array de dicts */
    private function searchRead(string $model, array $domain, array $fields, int $limit = 200): array
    {
        $uid = $this->getUid();

        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            $model,
            'search_read',
            [$domain],
            [
                'fields' => $fields,
                'limit' => $limit,
            ],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);

        return is_array($parsed) ? $parsed : [];
    }

    /** Tokeniza como buscador: quita stopwords y separa */
    private function tokenizeQuery(string $q): array
    {
        $q = mb_strtolower(trim($q));

        // quita palabras “ruido” comunes
        $stopWords = ['de', 'del', 'la', 'el', 'los', 'las', 'para', 'x', 'por'];
        $q = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $q); // deja letras/números/espacios
        $q = preg_replace('/\s+/', ' ', $q);
        $parts = array_values(array_filter(explode(' ', $q)));

        $tokens = [];
        foreach ($parts as $p) {
            if (in_array($p, $stopWords, true)) continue;
            if (mb_strlen($p) < 2) continue;
            $tokens[] = $p;
        }

        // si el usuario escribió "500mg" también ayuda a separar número/letra (opcional)
        // ejemplo: "500mg" -> "500" y "mg"
        $expanded = [];
        foreach ($tokens as $t) {
            if (preg_match('/^\d+[a-z]+$/i', $t)) {
                $expanded[] = preg_replace('/[a-z]+$/i', '', $t);
                $expanded[] = preg_replace('/^\d+/i', '', $t);
            } else {
                $expanded[] = $t;
            }
        }

        // únicos manteniendo orden
        $unique = [];
        foreach ($expanded as $t) {
            if ($t === '') continue;
            if (!in_array($t, $unique, true)) $unique[] = $t;
        }

        return $unique;
    }

    private function postXml(string $url, string $xml): string
    {
        $res = Http::timeout(12)
            ->retry(2, 200)
            ->withHeaders(['Content-Type' => 'text/xml'])
            ->withBody($xml, 'text/xml')
            ->post($url);

        if (!$res->successful()) {
            throw new RuntimeException("Error HTTP hacia Odoo ({$res->status()}): " . substr($res->body(), 0, 300));
        }

        return $res->body();
    }

    private function buildMethodCall(string $methodName, array $params): string
    {
        $methodNameXml = $this->xmlEscape($methodName);

        $paramsXml = '';
        foreach ($params as $p) {
            $paramsXml .= "<param><value>{$this->encodeValue($p)}</value></param>";
        }

        return <<<XML
<?xml version="1.0"?>
<methodCall>
  <methodName>{$methodNameXml}</methodName>
  <params>
    {$paramsXml}
  </params>
</methodCall>
XML;
    }

    private function xmlEscape(string $s): string
    {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function encodeValue(mixed $v): string
    {
        if (is_null($v)) return '<string></string>';
        if (is_bool($v)) return '<boolean>' . ($v ? '1' : '0') . '</boolean>';
        if (is_int($v)) return "<int>{$v}</int>";
        if (is_float($v)) return "<double>{$v}</double>";
        if (is_string($v)) return '<string>' . $this->xmlEscape($v) . '</string>';

        if (is_array($v)) {
            $isAssoc = array_keys($v) !== range(0, count($v) - 1);

            if ($isAssoc) {
                $members = '';
                foreach ($v as $k => $val) {
                    $kXml = $this->xmlEscape((string) $k);
                    $members .= "<member><name>{$kXml}</name><value>{$this->encodeValue($val)}</value></member>";
                }
                return "<struct>{$members}</struct>";
            }

            $items = '';
            foreach ($v as $item) {
                $items .= "<value>{$this->encodeValue($item)}</value>";
            }
            return "<array><data>{$items}</data></array>";
        }

        return '<string>' . $this->xmlEscape((string) $v) . '</string>';
    }

    private function parseXmlRpc(string $xml): mixed
    {
        $sx = simplexml_load_string($xml);
        if (!$sx) {
            throw new RuntimeException('No se pudo parsear XML de Odoo: ' . substr($xml, 0, 300));
        }

        if (isset($sx->fault)) {
            $faultVal = $this->decodeValue($sx->fault->value);
            $msg = is_array($faultVal) ? json_encode($faultVal, JSON_UNESCAPED_UNICODE) : (string) $faultVal;
            throw new RuntimeException('Fault XML-RPC Odoo: ' . $msg);
        }

        $valueNode = $sx->params->param->value ?? null;
        return $valueNode ? $this->decodeValue($valueNode) : null;
    }

    private function decodeValue(\SimpleXMLElement $valueNode): mixed
    {
        if (count($valueNode->children()) === 0) return (string) $valueNode;

        if (isset($valueNode->string)) return (string) $valueNode->string;
        if (isset($valueNode->int)) return (int) $valueNode->int;
        if (isset($valueNode->i4)) return (int) $valueNode->i4;
        if (isset($valueNode->double)) return (float) $valueNode->double;
        if (isset($valueNode->boolean)) return ((string) $valueNode->boolean) === '1';

        if (isset($valueNode->array)) {
            $out = [];
            foreach ($valueNode->array->data->value as $v) {
                $out[] = $this->decodeValue($v);
            }
            return $out;
        }

        if (isset($valueNode->struct)) {
            $out = [];
            foreach ($valueNode->struct->member as $m) {
                $name = (string) $m->name;
                $out[$name] = $this->decodeValue($m->value);
            }
            return $out;
        }

        return (string) $valueNode;
    }
}
