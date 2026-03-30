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
            $pairs = $this->nameSearchByTermVariants('product.product', $t, $limit * 3);
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
            $pairs = $this->nameSearchByTermVariants('product.product', $tokens[0], $limit * 2);
            $idsFinal = array_values(array_filter(array_map(fn($p) => $p[0] ?? null, $pairs), fn($x) => is_int($x)));
        }

        $idsFinal = array_slice($idsFinal, 0, max($limit * 4, $limit));

        if (!$idsFinal) return [];

        // read para traer campos
        $rows = $this->read('product.product', $idsFinal, [
            'id', 'name', 'default_code', 'qty_available', 'barcode', 'lst_price'
        ]);
        $rows = $this->sortProductsByRelevance($rows, $query);

        $qtyByProductId = [];
        $storeLocationIds = $this->getStoreLocationIds();

        if (!empty($storeLocationIds)) {
            $qtyByProductId = $this->readQtyAvailableByLocations($idsFinal, $storeLocationIds);
        }

        // Normaliza salida y excluye productos sin disponibilidad.
        $out = [];
        foreach ($rows as $r) {
            $qtyAvailable = (float) ($qtyByProductId[$r['id'] ?? 0] ?? ($r['qty_available'] ?? 0));
            if ($qtyAvailable <= 0) {
                continue;
            }

            $out[] = [
                'id' => $r['id'] ?? null,
                'name' => $r['name'] ?? null,
                'default_code' => $r['default_code'] ?? null,
                'barcode' => $r['barcode'] ?? null,
                'qty_available' => $qtyAvailable,
                'price' => $r['lst_price'] ?? 0,
            ];
        }

        return array_slice($out, 0, $limit);
    }

    /**
     * Ejecuta name_search con variantes de término y une resultados preservando orden.
     *
     * @return array<int,mixed>
     */
    private function nameSearchByTermVariants(string $model, string $term, int $limit): array
    {
        $variants = $this->expandSearchTermVariants($term);
        $merged = [];
        $seenIds = [];

        foreach ($variants as $variant) {
            $pairs = $this->nameSearch($model, $variant, $limit);
            foreach ($pairs as $pair) {
                $id = $pair[0] ?? null;
                if (!is_int($id)) {
                    continue;
                }

                if (isset($seenIds[$id])) {
                    continue;
                }

                $seenIds[$id] = true;
                $merged[] = $pair;

                if (count($merged) >= $limit) {
                    return $merged;
                }
            }
        }

        // Fallback adicional: si name_search no devolvió nada, prueba prefijos
        // sobre search_read para tolerar pequeñas variaciones ortográficas.
        if (empty($merged) && $model === 'product.product') {
            foreach ($this->expandPrefixFallbackTerms($term) as $prefix) {
                $rows = $this->searchRead(
                    'product.product',
                    ['&', ['active', '=', true], '|', ['name', 'ilike', $prefix], ['default_code', 'ilike', $prefix]],
                    ['id', 'name'],
                    $limit
                );

                foreach ($rows as $row) {
                    $id = $row['id'] ?? null;
                    if (!is_int($id) || isset($seenIds[$id])) {
                        continue;
                    }

                    $seenIds[$id] = true;
                    $merged[] = [$id, (string) ($row['name'] ?? '')];

                    if (count($merged) >= $limit) {
                        return $merged;
                    }
                }
            }
        }

        return $merged;
    }

    /**
     * Crea variantes de un token para tolerar cambios de idioma/escritura
     * comunes (ej: tirzepatide <-> tirzepatida).
     *
     * @return string[]
     */
    private function expandSearchTermVariants(string $term): array
    {
        $term = trim(mb_strtolower($term));
        if ($term === '') {
            return [];
        }

        $variants = [$term];

        if (preg_match('/ide$/', $term)) {
            $variants[] = preg_replace('/ide$/', 'ida', $term);
        }
        if (preg_match('/ida$/', $term)) {
            $variants[] = preg_replace('/ida$/', 'ide', $term);
        }

        $unique = [];
        foreach ($variants as $variant) {
            $variant = trim((string) $variant);
            if ($variant === '' || in_array($variant, $unique, true)) {
                continue;
            }
            $unique[] = $variant;
        }

        return $unique;
    }

    /**
     * Genera prefijos decrecientes para rescatar resultados cuando no hubo match.
     * Ej: "tirzepatide" -> ["tirzepatid","tirzepati","tirzepat","tirzepa"]
     *
     * @return string[]
     */
    private function expandPrefixFallbackTerms(string $term): array
    {
        $term = $this->normalizeSearchText($term);
        $term = str_replace(' ', '', $term);

        $length = mb_strlen($term);
        if ($length < 6) {
            return [];
        }

        $prefixes = [];
        for ($cut = 1; $cut <= 4; $cut++) {
            $prefixLength = $length - $cut;
            if ($prefixLength < 6) {
                break;
            }

            $prefix = mb_substr($term, 0, $prefixLength);
            if ($prefix !== '' && !in_array($prefix, $prefixes, true)) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    /**
     * Ordena productos por similitud textual con la query del usuario sin cambiar
     * el contrato del método. Esto permite priorizar coincidencias cercanas
     * (ej: "tirzepatide" -> "Tirzepatida").
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function sortProductsByRelevance(array $rows, string $query): array
    {
        if (count($rows) <= 1) {
            return $rows;
        }

        $normalizedQuery = $this->normalizeSearchText($query);
        if ($normalizedQuery === '') {
            return $rows;
        }

        $tokens = $this->tokenizeQuery($normalizedQuery);
        if (empty($tokens)) {
            $tokens = array_values(array_filter(explode(' ', $normalizedQuery)));
        }

        $indexedRows = [];
        foreach ($rows as $index => $row) {
            $indexedRows[] = [
                'index' => $index,
                'score' => $this->productRelevanceScore($row, $normalizedQuery, $tokens),
                'row' => $row,
            ];
        }

        usort($indexedRows, function (array $a, array $b): int {
            $scoreCompare = $b['score'] <=> $a['score'];
            if ($scoreCompare !== 0) {
                return $scoreCompare;
            }

            return $a['index'] <=> $b['index'];
        });

        return array_map(fn (array $item): array => $item['row'], $indexedRows);
    }

    /**
     * @param array<string,mixed> $product
     * @param string[] $queryTokens
     */
    private function productRelevanceScore(array $product, string $normalizedQuery, array $queryTokens): float
    {
        $name = $this->normalizeSearchText((string) ($product['name'] ?? ''));
        $code = $this->normalizeSearchText((string) ($product['default_code'] ?? ''));
        $haystack = trim($name . ' ' . $code);

        if ($haystack === '') {
            return 0.0;
        }

        $containsBonus = str_contains($name, $normalizedQuery) ? 1.0 : 0.0;
        $startsBonus = str_starts_with($name, $normalizedQuery) ? 1.0 : 0.0;

        $maxLen = max(mb_strlen($normalizedQuery), mb_strlen($haystack));
        $levScore = 0.0;
        if ($maxLen > 0) {
            $levDistance = levenshtein($normalizedQuery, $haystack);
            $levScore = max(0.0, 1 - ($levDistance / $maxLen));
        }

        $tokenCount = count($queryTokens);
        $weightedTokenScore = 0.0;
        $totalTokenWeight = 0.0;
        $tokensMatchedInName = 0;
        $tokensMatchedOnlyInCode = 0;
        foreach ($queryTokens as $idx => $queryToken) {
            $queryToken = trim($queryToken);
            if ($queryToken === '') {
                continue;
            }

            $matchesName = $this->tokenMatches($queryToken, $name);
            $matchesCode = $this->tokenMatches($queryToken, $code);
            if ($matchesName) {
                $tokensMatchedInName++;
            } elseif ($matchesCode) {
                $tokensMatchedOnlyInCode++;
            }

            $weight = 1.0 + ($idx * 0.75);
            $tokenScore = $this->tokenRelevanceScore($queryToken, $name, $code, $haystack, $tokenCount > 1);
            $weightedTokenScore += ($tokenScore * $weight);
            $totalTokenWeight += $weight;
        }

        $tokenAverage = $totalTokenWeight <= 0
            ? 0.0
            : ($weightedTokenScore / $totalTokenWeight);

        $coverageInName = $tokenCount > 0 ? ($tokensMatchedInName / $tokenCount) : 0.0;
        $codeOnlyRatio = $tokenCount > 0 ? ($tokensMatchedOnlyInCode / $tokenCount) : 0.0;
        $compactnessBonus = $this->compactnessBonus($name, $queryTokens);
        $codePenalty = $codeOnlyRatio * 0.25;

        // Pesos para priorizar coincidencia real, pero permitiendo typo/cambios mínimos.
        $score = ($tokenAverage * 0.60)
            + ($levScore * 0.25)
            + ($containsBonus * 0.10)
            + ($startsBonus * 0.05)
            + ($coverageInName * 0.15)
            + ($compactnessBonus * 0.05)
            - $codePenalty;

        return round($score, 6);
    }

    private function tokenRelevanceScore(string $token, string $name, string $code, string $haystack, bool $isMultiTokenQuery): float
    {
        $token = trim($token);
        if ($token === '') {
            return 0.0;
        }

        $escapedToken = preg_quote($token, '/');
        if (preg_match('/(?:^|\s)' . $escapedToken . '(?:\s|$)/u', $name) === 1) {
            return 1.0;
        }

        if (preg_match('/(?:^|\s)' . $escapedToken . '(?:\s|$)/u', $code) === 1) {
            return $isMultiTokenQuery ? 0.55 : 0.85;
        }

        if (str_contains($name, $token)) {
            return 0.8;
        }

        if (str_contains($code, $token)) {
            return $isMultiTokenQuery ? 0.4 : 0.65;
        }

        if (str_contains($haystack, $token)) {
            return 0.35;
        }

        similar_text($token, $haystack, $similarityPercent);

        return ($similarityPercent / 100) * 0.5;
    }

    private function tokenMatches(string $token, string $value): bool
    {
        $token = trim($token);
        $value = trim($value);

        if ($token === '' || $value === '') {
            return false;
        }

        $escapedToken = preg_quote($token, '/');
        if (preg_match('/(?:^|\s)' . $escapedToken . '(?:\s|$)/u', $value) === 1) {
            return true;
        }

        return str_contains($value, $token);
    }

    /**
     * Retorna mayor puntaje cuando el nombre contiene la frase buscada con pocos
     * términos extra (ej: "vitamina c" > "vitamina c con zinc").
     *
     * @param string[] $queryTokens
     */
    private function compactnessBonus(string $name, array $queryTokens): float
    {
        $queryTokens = array_values(array_filter(array_map('trim', $queryTokens), fn (string $t): bool => $t !== ''));
        if (empty($queryTokens) || $name === '') {
            return 0.0;
        }

        $queryPhrase = implode(' ', $queryTokens);
        if (!str_contains($name, $queryPhrase)) {
            return 0.0;
        }

        $nameWordCount = count(array_values(array_filter(explode(' ', $name), fn (string $t): bool => $t !== '')));
        $queryWordCount = count($queryTokens);
        $extraWords = max(0, $nameWordCount - $queryWordCount);

        return 1 / (1 + $extraWords);
    }

    private function normalizeSearchText(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'ä' => 'a', 'ë' => 'e', 'ï' => 'i', 'ö' => 'o', 'ü' => 'u',
            'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u',
            'â' => 'a', 'ê' => 'e', 'î' => 'i', 'ô' => 'o', 'û' => 'u',
            'ñ' => 'n',
        ]);
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $value);
        $value = preg_replace('/\s+/', ' ', (string) $value);

        return trim((string) $value);
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
            $phone = $r['phone'] ?? $r['mobile'] ?? null;
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
                'fields' => ['id', 'name', 'email', 'phone', 'mobile', 'vat', 'is_company', 'write_date', 'create_date', 'country_id'],
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


    /**
     * Obtiene insights de compras por cliente desde Odoo.
     *
     * Modelos usados:
     * - sale.order (órdenes de venta)
     * - sale.order.line (líneas/productos de la orden)
     *
     * @param int[] $partnerIds
     * @return array<int,array{ultimo_producto_comprado:string,producto_mas_comprado:string,tiene_compras:bool}>
     */
    public function getPartnerPurchaseInsights(array $partnerIds): array
    {
        $partnerIds = array_values(array_unique(array_map('intval', $partnerIds)));
        $partnerIds = array_values(array_filter($partnerIds, fn (int $id): bool => $id > 0));

        if (empty($partnerIds)) {
            return [];
        }

        $commercialByPartner = $this->getCommercialPartnerIds($partnerIds);
        $commercialIds = array_values(array_unique(array_map(
            fn (int $partnerId): int => $commercialByPartner[$partnerId] ?? $partnerId,
            $partnerIds,
        )));

        $partnersByCommercialId = [];
        foreach ($partnerIds as $partnerId) {
            $commercialId = $commercialByPartner[$partnerId] ?? $partnerId;
            $partnersByCommercialId[$commercialId][] = $partnerId;
        }

        $saleOrders = $this->searchReadRaw(
            'sale.order',
            [
                ['partner_id', 'in', $commercialIds],
                ['state', 'in', ['sale', 'done']],
            ],
            ['id', 'partner_id', 'date_order'],
            5000,
            'date_order desc, id desc'
        );

        $posOrders = $this->searchReadRaw(
            'pos.order',
            [
                ['partner_id', 'in', $commercialIds],
                ['state', 'in', ['paid', 'done', 'invoiced']],
            ],
            ['id', 'partner_id', 'date_order'],
            5000,
            'date_order desc, id desc'
        );

        $orders = [];
        foreach ($saleOrders as $order) {
            $order['_source_model'] = 'sale';
            $orders[] = $order;
        }
        foreach ($posOrders as $order) {
            $order['_source_model'] = 'pos';
            $orders[] = $order;
        }

        if (empty($orders)) {
            $emptyInsights = [];
            foreach ($partnerIds as $partnerId) {
                $emptyInsights[$partnerId] = [
                    'ultimo_producto_comprado' => '',
                    'producto_mas_comprado' => '',
                    'tiene_compras' => false,
                ];
            }

            return $emptyInsights;
        }

        usort($orders, function (array $a, array $b): int {
            $dateA = (string) ($a['date_order'] ?? '');
            $dateB = (string) ($b['date_order'] ?? '');
            if ($dateA !== $dateB) {
                return strcmp($dateB, $dateA);
            }

            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });

        $saleOrderIds = [];
        $posOrderIds = [];
        $latestOrderByPartner = [];

        foreach ($orders as $order) {
            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $sourceModel = (string) ($order['_source_model'] ?? 'sale');
            $orderKey = $sourceModel . ':' . $orderId;

            $partnerField = $order['partner_id'] ?? null;
            $commercialPartnerId = is_array($partnerField) ? (int) ($partnerField[0] ?? 0) : (int) $partnerField;
            if ($commercialPartnerId <= 0) {
                continue;
            }

            if ($sourceModel === 'pos') {
                $posOrderIds[] = $orderId;
            } else {
                $saleOrderIds[] = $orderId;
            }

            $targetPartnerIds = $partnersByCommercialId[$commercialPartnerId] ?? [$commercialPartnerId];
            foreach ($targetPartnerIds as $targetPartnerId) {
                if (!isset($latestOrderByPartner[$targetPartnerId])) {
                    $latestOrderByPartner[$targetPartnerId] = $orderKey;
                }
            }
        }

        $saleLines = [];
        if (!empty($saleOrderIds)) {
            try {
                $saleLines = $this->searchReadRaw(
                    'sale.order.line',
                    [
                        ['order_id', 'in', array_values(array_unique($saleOrderIds))],
                        ['display_type', '=', false],
                    ],
                    ['order_id', 'product_id', 'product_uom_qty'],
                    10000,
                    'id asc'
                );
            } catch (\Throwable) {
                $saleLines = $this->searchReadRaw(
                    'sale.order.line',
                    [
                        ['order_id', 'in', array_values(array_unique($saleOrderIds))],
                    ],
                    ['order_id', 'product_id', 'product_uom_qty'],
                    10000,
                    'id asc'
                );
            }
        }

        $posLines = [];
        if (!empty($posOrderIds)) {
            $posLines = $this->searchReadRaw(
                'pos.order.line',
                [
                    ['order_id', 'in', array_values(array_unique($posOrderIds))],
                ],
                ['order_id', 'product_id', 'qty'],
                10000,
                'id asc'
            );
        }

        $orderPartnerMap = [];
        foreach ($orders as $order) {
            $orderId = (int) ($order['id'] ?? 0);
            if ($orderId <= 0) {
                continue;
            }

            $sourceModel = (string) ($order['_source_model'] ?? 'sale');
            $orderKey = $sourceModel . ':' . $orderId;

            $partnerField = $order['partner_id'] ?? null;
            $commercialPartnerId = is_array($partnerField) ? (int) ($partnerField[0] ?? 0) : (int) $partnerField;
            if ($commercialPartnerId > 0) {
                $orderPartnerMap[$orderKey] = $partnersByCommercialId[$commercialPartnerId] ?? [$commercialPartnerId];
            }
        }

        $lastProductsByPartner = [];
        $lastProductIndexByPartner = [];
        $qtyByPartner = [];

        $appendLine = function (array $line, string $sourceModel, string $qtyField) use (&$orderPartnerMap, &$qtyByPartner, &$lastProductsByPartner, &$latestOrderByPartner): void {
            $orderField = $line['order_id'] ?? null;
            $orderId = is_array($orderField) ? (int) ($orderField[0] ?? 0) : (int) $orderField;
            $orderKey = $sourceModel . ':' . $orderId;

            if ($orderId <= 0 || !isset($orderPartnerMap[$orderKey])) {
                return;
            }

            $targetPartnerIds = $orderPartnerMap[$orderKey];
            $productField = $line['product_id'] ?? null;
            $productId = is_array($productField) ? (int) ($productField[0] ?? 0) : 0;
            $productName = is_array($productField) ? trim((string) ($productField[1] ?? '')) : '';
            if ($productId <= 0 || $productName === '') {
                return;
            }

            $qty = (float) ($line[$qtyField] ?? 0);

            foreach ($targetPartnerIds as $partnerId) {
                $qtyByPartner[$partnerId][$productName] = ($qtyByPartner[$partnerId][$productName] ?? 0.0) + $qty;

                if (($latestOrderByPartner[$partnerId] ?? null) === $orderKey) {
                    $normalizedProductName = mb_strtolower($productName);
                    if (!isset($lastProductIndexByPartner[$partnerId][$normalizedProductName])) {
                        $lastProductIndexByPartner[$partnerId][$normalizedProductName] = true;
                        $lastProductsByPartner[$partnerId][] = $productName;
                    }
                }
            }
        };

        foreach ($saleLines as $line) {
            $appendLine($line, 'sale', 'product_uom_qty');
        }

        foreach ($posLines as $line) {
            $appendLine($line, 'pos', 'qty');
        }

        $insights = [];

        foreach ($partnerIds as $partnerId) {
            $lastProducts = isset($lastProductsByPartner[$partnerId])
                ? implode(', ', $lastProductsByPartner[$partnerId])
                : '';

            $mostPurchased = '';
            if (!empty($qtyByPartner[$partnerId])) {
                arsort($qtyByPartner[$partnerId]);
                $mostPurchased = (string) array_key_first($qtyByPartner[$partnerId]);
            }

            $insights[$partnerId] = [
                'ultimo_producto_comprado' => $lastProducts,
                'producto_mas_comprado' => $mostPurchased,
                'tiene_compras' => ($lastProducts !== '') || ($mostPurchased !== ''),
            ];
        }

        return $insights;
    }

    /**
     * @param int[] $partnerIds
     * @return array<int,int>
     */
    private function getCommercialPartnerIds(array $partnerIds): array
    {
        $rows = $this->searchReadRaw(
            'res.partner',
            [['id', 'in', $partnerIds]],
            ['id', 'commercial_partner_id'],
            max(1, count($partnerIds)),
            'id asc',
        );

        $commercialByPartner = [];

        foreach ($rows as $row) {
            $partnerId = (int) ($row['id'] ?? 0);
            if ($partnerId <= 0) {
                continue;
            }

            $commercialField = $row['commercial_partner_id'] ?? null;
            $commercialId = is_array($commercialField)
                ? (int) ($commercialField[0] ?? 0)
                : (int) $commercialField;

            $commercialByPartner[$partnerId] = $commercialId > 0 ? $commercialId : $partnerId;
        }

        foreach ($partnerIds as $partnerId) {
            if (!isset($commercialByPartner[$partnerId])) {
                $commercialByPartner[$partnerId] = $partnerId;
            }
        }

        return $commercialByPartner;
    }


    /**
     * Inspecciona modelos relacionados a pedidos de venta/compra para validar dónde vive el historial.
     *
     * Si recibe partner IDs, replica la lógica de sincronización: filtra por `commercial_partner_id`
     * para evitar falsos vacíos cuando las órdenes están en la casa matriz.
     *
     * @param int[] $partnerIds
     * @return array<int,array{model:string,domain:array<int,mixed>,available_fields:array<int,string>,sample_rows:array<int,array<string,mixed>>,input_partner_ids:array<int,int>,commercial_partner_ids:array<int,int>,partner_to_commercial:array<int,int>,error?:string}>
     */
    public function inspectOrderRelatedModels(array $partnerIds = [], int $limit = 5): array
    {
        $limit = max(1, min($limit, 30));
        $partnerIds = array_values(array_unique(array_filter(array_map('intval', $partnerIds), fn (int $id): bool => $id > 0)));

        $partnerToCommercial = [];
        $commercialPartnerIds = [];

        if (!empty($partnerIds)) {
            $partnerToCommercial = $this->getCommercialPartnerIds($partnerIds);
            $commercialPartnerIds = array_values(array_unique(array_map(
                fn (int $partnerId): int => $partnerToCommercial[$partnerId] ?? $partnerId,
                $partnerIds,
            )));
        }

        $models = [
            ['model' => 'sale.order', 'fields' => ['id', 'name', 'partner_id', 'state', 'date_order', 'create_date']],
            ['model' => 'sale.order.line', 'fields' => ['id', 'order_id', 'product_id', 'product_uom_qty', 'price_unit']],
            ['model' => 'purchase.order', 'fields' => ['id', 'name', 'partner_id', 'state', 'date_order', 'create_date']],
            ['model' => 'purchase.order.line', 'fields' => ['id', 'order_id', 'product_id', 'product_qty', 'price_unit']],
            ['model' => 'pos.order', 'fields' => ['id', 'name', 'partner_id', 'state', 'date_order', 'create_date']],
            ['model' => 'pos.order.line', 'fields' => ['id', 'order_id', 'product_id', 'qty', 'price_unit']],
        ];

        $report = [];

        foreach ($models as $meta) {
            $model = $meta['model'];
            $domain = [];

            if (!empty($commercialPartnerIds)) {
                if (str_ends_with($model, '.line')) {
                    $domain[] = ['order_id.partner_id', 'in', $commercialPartnerIds];
                } else {
                    $domain[] = ['partner_id', 'in', $commercialPartnerIds];
                }
            }

            try {
                $availableFields = $this->filterExistingFields($model, $meta['fields']);
                $sampleRows = $this->searchReadRaw($model, $domain, $availableFields, $limit, 'id desc');

                $report[] = [
                    'model' => $model,
                    'domain' => $domain,
                    'available_fields' => $availableFields,
                    'sample_rows' => $sampleRows,
                    'input_partner_ids' => $partnerIds,
                    'commercial_partner_ids' => $commercialPartnerIds,
                    'partner_to_commercial' => $partnerToCommercial,
                ];
            } catch (\Throwable $e) {
                $report[] = [
                    'model' => $model,
                    'domain' => $domain,
                    'available_fields' => [],
                    'sample_rows' => [],
                    'input_partner_ids' => $partnerIds,
                    'commercial_partner_ids' => $commercialPartnerIds,
                    'partner_to_commercial' => $partnerToCommercial,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $report;
    }

    /**
     * Audita si en res.partner hay campos útiles para identificar país de origen del teléfono.
     *
     * @return array{fields_available: string[], inspected_contacts: array<int, array<string,mixed>>}
     */
    public function inspectPhoneCountryFields(int $limit = 10): array
    {
        $limit = max(1, min($limit, 100));
        $allFields = $this->fieldsGet('res.partner');

        $candidateFields = [
            'id',
            'name',
            'phone',
            'mobile',
            'country_id',
            'phone_country_code',
            'phone_sanitized',
            'mobile_sanitized',
        ];

        $availableFields = [];
        foreach ($candidateFields as $field) {
            if ($field === 'id' || array_key_exists($field, $allFields)) {
                $availableFields[] = $field;
            }
        }

        $uid = $this->getUid();
        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            'res.partner',
            'search_read',
            [[['active', '=', true]]],
            [
                'fields' => $availableFields,
                'limit' => $limit,
                'order' => 'id desc',
            ],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);
        $rows = is_array($parsed) ? $parsed : [];

        return [
            'fields_available' => $availableFields,
            'inspected_contacts' => $rows,
        ];
    }

    /**
     * Inspección ligera de modelos relacionados a tasa/currency para evitar sobrecarga.
     *
     * @return array<int, array{model:string, available_fields: array<int,string>, sample_rows: array<int,array<string,mixed>>, error?: string}>
     */
    public function inspectRateRelatedModels(int $limit = 10): array
    {
        $limit = max(1, min($limit, 30));
        $models = [
            'res.currency.rate',
            'res.currency',
            'ir.config_parameter',
        ];

        $report = [];

        foreach ($models as $model) {
            try {
                $fields = $this->fieldsGet($model);
                $candidateFields = ['id', 'name', 'display_name', 'currency_id', 'company_id', 'rate', 'inverse_company_rate', 'inverse_rate', 'write_date', 'create_date', 'key', 'value'];
                $availableFields = array_values(array_filter($candidateFields, fn ($f) => $f === 'id' || array_key_exists($f, $fields)));
                $sampleRows = $this->searchRead($model, [], $availableFields, $limit);

                $report[] = [
                    'model' => $model,
                    'available_fields' => $availableFields,
                    'sample_rows' => $sampleRows,
                ];
            } catch (\Throwable $e) {
                $report[] = [
                    'model' => $model,
                    'available_fields' => [],
                    'sample_rows' => [],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $report;
    }

    /**
     * Obtiene la tasa actual desde los modelos res.currency.rate y res.currency.
     *
     * @return array{date:string,res_currency_rate:float,res_currency:float,currency_code:string}
     */
    public function getLatestCurrencyRates(): array
    {
        $configuredCurrencyCode = strtoupper((string) config('services.bcv.currency_code', 'VEF'));
        $currencyFields = $this->filterExistingFields('res.currency', [
            'id',
            'name',
            'rate',
            'inverse_rate',
            'write_date',
        ]);
        $currencyRateFields = $this->filterExistingFields('res.currency.rate', [
            'id',
            'name',
            'currency_id',
            'rate',
            'inverse_company_rate',
            'inverse_rate',
            'write_date',
        ]);

        $preferredCurrencyCodes = array_values(array_unique(array_filter([
            'VEF',
            $configuredCurrencyCode,
            'USD',
        ], fn (string $code): bool => $code !== '')));

        $currencyRow = null;
        foreach ($preferredCurrencyCodes as $currencyCode) {
            $currencyRows = $this->searchReadWithOrder(
                'res.currency',
                [['name', '=', $currencyCode]],
                $currencyFields,
                1,
                'write_date desc, id desc',
            );

            if (!empty($currencyRows[0])) {
                $currencyRow = $currencyRows[0];
                break;
            }
        }

        if (!is_array($currencyRow)) {
            throw new RuntimeException(sprintf(
                'No se encontró la moneda esperada en res.currency (prioridad: %s).',
                implode(', ', $preferredCurrencyCodes),
            ));
        }

        $currencyCode = (string) ($currencyRow['name'] ?? $configuredCurrencyCode);
        $currencyId = (int) ($currencyRow['id'] ?? 0);
        $currencyRate = $this->normalizeRateValue($currencyRow);

        if ($currencyId <= 0 || $currencyRate === null) {
            throw new RuntimeException('No se pudo leer una tasa válida desde res.currency.');
        }

        $rateRows = $this->searchReadWithOrder(
            'res.currency.rate',
            [['currency_id', '=', $currencyId]],
            $currencyRateFields,
            1,
            'write_date desc, id desc',
        );

        if (empty($rateRows[0])) {
            $rateRows = $this->searchReadWithOrder(
                'res.currency.rate',
                [],
                $currencyRateFields,
                50,
                'write_date desc, id desc',
            );

            $rateRows = array_values(array_filter($rateRows, function (array $row) use ($currencyId): bool {
                $rawCurrencyId = $row['currency_id'] ?? null;

                if (is_array($rawCurrencyId)) {
                    return (int) ($rawCurrencyId[0] ?? 0) === $currencyId;
                }

                return (int) $rawCurrencyId === $currencyId;
            }));
        }

        $rateRow = $rateRows[0] ?? null;
        $resCurrencyRate = is_array($rateRow) ? $this->normalizeRateValue($rateRow) : null;

        if ($resCurrencyRate === null) {
            // Fallback defensivo para no romper el cron cuando no hay histórico en res.currency.rate.
            $resCurrencyRate = $currencyRate;
            $rateDate = (string) ($currencyRow['write_date'] ?? now()->toDateString());
        } else {
            $rateDate = (string) ($rateRow['name'] ?? $rateRow['write_date'] ?? now()->toDateString());
        }

        return [
            'date' => $rateDate,
            'res_currency_rate' => $resCurrencyRate,
            'res_currency' => $currencyRate,
            'currency_code' => $currencyCode,
        ];
    }

    /**
     * Inspecciona un producto buscando por nombre y devuelve todos sus campos.
     */
    public function inspectProductByName(string $query): array
    {
        $query = trim($query);

        if ($query === '') {
            return [];
        }

        $pairs = $this->nameSearch('product.product', $query, 1);
        $productId = $pairs[0][0] ?? null;

        if (!is_int($productId) || $productId <= 0) {
            return [];
        }

        $fields = $this->fieldsGet('product.product');
        $fieldNames = array_keys($fields);
        ['record' => $record, 'unreadable_fields' => $unreadableFields] = $this->readRecordSafely(
            'product.product',
            $productId,
            $fieldNames,
        );

        $priceCandidates = [];
        foreach ($fields as $fieldName => $meta) {
            $label = mb_strtolower((string) ($meta['string'] ?? ''));
            $name = mb_strtolower((string) $fieldName);
            $type = (string) ($meta['type'] ?? '');
            $looksLikePrice = str_contains($name, 'price')
                || str_contains($name, 'cost')
                || str_contains($label, 'precio')
                || str_contains($label, 'price')
                || str_contains($label, 'costo')
                || in_array($type, ['monetary', 'float'], true);

            if (!$looksLikePrice || !array_key_exists($fieldName, $record)) {
                continue;
            }

            $priceCandidates[$fieldName] = [
                'label' => $meta['string'] ?? $fieldName,
                'type' => $type,
                'value' => $record[$fieldName],
            ];
        }

        return [
            'id' => $productId,
            'display_name' => $pairs[0][1] ?? null,
            'fields' => $fields,
            'record' => $record,
            'unreadable_fields' => $unreadableFields,
            'price_candidates' => $priceCandidates,
        ];
    }

    /**
     * Lee un registro tolerando campos dañados/incompatibles de Odoo.
     * Devuelve los campos legibles y la lista de campos que fallaron al leer.
     *
     * @param string[] $fieldNames
     * @return array{record: array<string,mixed>, unreadable_fields: string[]}
     */
    private function readRecordSafely(string $model, int $id, array $fieldNames): array
    {
        $record = [];
        $unreadable = [];

        foreach (array_chunk($fieldNames, 25) as $chunk) {
            ['record' => $chunkRecord, 'unreadable_fields' => $chunkUnreadable] = $this->readFieldsChunkSafely($model, $id, $chunk);
            $record = array_merge($record, $chunkRecord);
            $unreadable = array_merge($unreadable, $chunkUnreadable);
        }

        $record['id'] = $id;

        return [
            'record' => $record,
            'unreadable_fields' => array_values(array_unique($unreadable)),
        ];
    }

    /**
     * @param string[] $fields
     * @return array{record: array<string,mixed>, unreadable_fields: string[]}
     */
    private function readFieldsChunkSafely(string $model, int $id, array $fields): array
    {
        if (empty($fields)) {
            return ['record' => [], 'unreadable_fields' => []];
        }

        try {
            $rows = $this->read($model, [$id], $fields);
            return [
                'record' => is_array($rows[0] ?? null) ? $rows[0] : [],
                'unreadable_fields' => [],
            ];
        } catch (RuntimeException $e) {
            if (count($fields) === 1) {
                return ['record' => [], 'unreadable_fields' => $fields];
            }

            $middle = (int) floor(count($fields) / 2);
            $left = array_slice($fields, 0, $middle);
            $right = array_slice($fields, $middle);

            ['record' => $leftRecord, 'unreadable_fields' => $leftUnreadable] = $this->readFieldsChunkSafely($model, $id, $left);
            ['record' => $rightRecord, 'unreadable_fields' => $rightUnreadable] = $this->readFieldsChunkSafely($model, $id, $right);

            return [
                'record' => array_merge($leftRecord, $rightRecord),
                'unreadable_fields' => array_merge($leftUnreadable, $rightUnreadable),
            ];
        }
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
        $args = [];

        if ($model === 'product.product') {
            $args = [['active', '=', true]];
        }

        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            $model,
            'name_search',
            [$term], // term
            [
                'args' => $args,
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

    private function fieldsGet(string $model): array
    {
        $uid = $this->getUid();

        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            $model,
            'fields_get',
            [],
            [
                'attributes' => ['string', 'type', 'help', 'currency_field'],
            ],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);

        return is_array($parsed) ? $parsed : [];
    }

    private function searchRead(string $model, array $domain, array $fields, int $limit = 10): array
    {
        return $this->searchReadWithOrder($model, $domain, $fields, $limit, 'id desc');
    }

    /**
     * @param string[] $fields
     * @return string[]
     */
    private function filterExistingFields(string $model, array $fields): array
    {
        $availableFields = $this->fieldsGet($model);

        return array_values(array_filter($fields, fn (string $field): bool => $field === 'id' || array_key_exists($field, $availableFields)));
    }

    private function searchReadWithOrder(string $model, array $domain, array $fields, int $limit, string $order): array
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
                'limit' => max(1, min($limit, 30)),
                'order' => $order,
            ],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);

        return is_array($parsed) ? $parsed : [];
    }

    private function searchReadRaw(string $model, array $domain, array $fields, int $limit, string $order): array
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
                'limit' => max(1, $limit),
                'order' => $order,
            ],
        ]);

        $raw = $this->postXml($this->baseUrl . '/xmlrpc/2/object', $xml);
        $parsed = $this->parseXmlRpc($raw);

        return is_array($parsed) ? $parsed : [];
    }

    private function normalizeRateValue(array $row): ?float
    {
        $candidates = [
            $row['rate'] ?? null,
            $row['inverse_company_rate'] ?? null,
            $row['inverse_rate'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (float) $candidate;
            }
        }

        return null;
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
     *
     * @param int[] $productIds
     * @param int[] $locationIds
     * @return array<int,float>
     */
    private function readQtyAvailableByLocations(array $productIds, array $locationIds): array
    {
        $totals = [];

        foreach ($locationIds as $locationId) {
            $rows = $this->readWithContext('product.product', $productIds, ['id', 'qty_available'], [
                'location' => $locationId,
            ]);

            foreach ($rows as $row) {
                $id = (int) ($row['id'] ?? 0);
                if ($id <= 0) {
                    continue;
                }

                $totals[$id] = ($totals[$id] ?? 0.0) + (float) ($row['qty_available'] ?? 0);
            }
        }

        return $totals;
    }

    /** read(model, ids, fields, context) -> array de dicts */
    private function readWithContext(string $model, array $ids, array $fields, array $context = []): array
    {
        $uid = $this->getUid();

        $xml = $this->buildMethodCall('execute_kw', [
            $this->db,
            $uid,
            $this->password,
            $model,
            'read',
            [$ids],
            [
                'fields' => $fields,
                'context' => $context,
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
