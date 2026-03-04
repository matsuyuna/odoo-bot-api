<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class BcvExchangeRateFetcher
{
    private const EXPORT_URL = 'https://bcv.org.ve/cambiaria/export/tasas-informativas-sistema-bancario';

    private const PAGE_URL = 'https://bcv.org.ve/cambiaria/tasas-informativas-sistema-bancario';

    /**
     * @return array{rate_date: string, usd_rate: string, source: string, raw_content: string}
     */
    public function fetchUsdRate(): array
    {
        return Cache::remember('bcv.usd-rate.latest', now()->addMinutes(15), function (): array {
            $exportResponse = Http::timeout(20)
                ->retry(3, 500)
                ->accept('*/*')
                ->get(self::EXPORT_URL);

            if ($exportResponse->successful()) {
                $parsed = $this->parseResponse(
                    $exportResponse->body(),
                    (string) $exportResponse->header('Content-Type', '')
                );

                return $parsed + ['source' => 'export', 'raw_content' => $exportResponse->body()];
            }

            $fallbackResponse = Http::timeout(20)
                ->retry(2, 700)
                ->accept('text/html,application/xhtml+xml')
                ->get(self::PAGE_URL);

            if (!$fallbackResponse->successful()) {
                throw new RuntimeException('BCV no respondió correctamente (export y fallback fallaron).');
            }

            $parsed = $this->parseHtml($fallbackResponse->body());

            return $parsed + ['source' => 'html_fallback', 'raw_content' => $fallbackResponse->body()];
        });
    }

    /**
     * @return array{rate_date: string, usd_rate: string}
     */
    private function parseResponse(string $body, string $contentType): array
    {
        if (Str::contains(strtolower($contentType), 'html') || Str::contains($body, '<table')) {
            return $this->parseHtml($body);
        }

        return $this->parseText($body);
    }

    /**
     * @return array{rate_date: string, usd_rate: string}
     */
    private function parseText(string $body): array
    {
        $lines = preg_split('/\R/u', trim($body)) ?: [];

        $rate = null;
        $date = null;

        foreach ($lines as $line) {
            if (!is_string($line) || trim($line) === '') {
                continue;
            }

            $parts = preg_split('/[;\t|,]+/', $line) ?: [];
            $parts = array_values(array_filter(array_map(fn ($part) => trim((string) $part), $parts), fn ($part) => $part !== ''));

            if (count($parts) < 2) {
                continue;
            }

            if ($date === null) {
                foreach ($parts as $part) {
                    if ($this->tryParseDate($part)) {
                        $date = $this->tryParseDate($part);
                        break;
                    }
                }
            }

            $hasUsdMarker = collect($parts)->contains(fn ($part) => preg_match('/\bUSD\b|D[oó]lar/i', $part) === 1);
            if (!$hasUsdMarker) {
                continue;
            }

            foreach (array_reverse($parts) as $part) {
                $maybeRate = $this->normalizeDecimal($part);
                if ($maybeRate !== null) {
                    $rate = $maybeRate;
                    break;
                }
            }
        }

        if ($rate === null) {
            throw new RuntimeException('No fue posible extraer la tasa USD del archivo exportado por BCV.');
        }

        $rateDate = $date ?? CarbonImmutable::today()->toDateString();

        return [
            'rate_date' => $rateDate,
            'usd_rate' => $rate,
        ];
    }

    /**
     * @return array{rate_date: string, usd_rate: string}
     */
    private function parseHtml(string $html): array
    {
        libxml_use_internal_errors(true);

        $doc = new DOMDocument();
        $doc->loadHTML($html);
        $xpath = new DOMXPath($doc);

        $usdNode = $xpath->query("//*[contains(translate(normalize-space(text()), 'ÁÉÍÓÚ', 'AEIOU'), 'DOLAR') or contains(normalize-space(text()), 'USD')]")->item(0);
        if (!$usdNode) {
            throw new RuntimeException('No se encontró la fila de USD en el HTML del BCV.');
        }

        $containerText = $usdNode->parentNode?->textContent ?? $usdNode->textContent;
        $rate = $this->extractFirstDecimal($containerText ?? '');

        if ($rate === null) {
            $siblingsText = '';
            if ($usdNode->parentNode) {
                foreach ($usdNode->parentNode->childNodes as $child) {
                    $siblingsText .= ' ' . ($child->textContent ?? '');
                }
            }
            $rate = $this->extractFirstDecimal($siblingsText);
        }

        if ($rate === null) {
            throw new RuntimeException('No se encontró valor decimal para USD en el HTML del BCV.');
        }

        $date = $this->extractDateFromText($html) ?? CarbonImmutable::today()->toDateString();

        return [
            'rate_date' => $date,
            'usd_rate' => $rate,
        ];
    }

    private function extractDateFromText(string $text): ?string
    {
        if (preg_match('/\b(\d{2}[\/-]\d{2}[\/-]\d{4})\b/u', $text, $match) === 1) {
            return $this->tryParseDate($match[1]);
        }

        return null;
    }

    private function tryParseDate(string $value): ?string
    {
        $clean = trim($value);

        if (preg_match('/^\d{2}[\/-]\d{2}[\/-]\d{4}$/', $clean) !== 1) {
            return null;
        }

        [$d, $m, $y] = preg_split('/[\/-]/', $clean);

        if (!checkdate((int) $m, (int) $d, (int) $y)) {
            return null;
        }

        return CarbonImmutable::create((int) $y, (int) $m, (int) $d)->toDateString();
    }

    private function extractFirstDecimal(string $value): ?string
    {
        if (preg_match('/\d{1,3}(?:\.\d{3})*,\d+|\d+[\.,]\d+/u', $value, $match) === 1) {
            return $this->normalizeDecimal($match[0]);
        }

        return null;
    }

    private function normalizeDecimal(string $value): ?string
    {
        $clean = preg_replace('/[^\d,\.]/u', '', $value);
        if (!$clean) {
            return null;
        }

        $clean = str_replace('.', '', $clean);
        $clean = str_replace(',', '.', $clean);

        if (!is_numeric($clean)) {
            return null;
        }

        return number_format((float) $clean, 4, '.', '');
    }
}
