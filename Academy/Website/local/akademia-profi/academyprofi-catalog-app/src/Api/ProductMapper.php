<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Api;

final class ProductMapper
{
    /**
     * @param array{amount:int,currency:string}|null $catalogPrice
     */
    public static function toProductDto(array $product, array $ui, ?array $catalogPrice = null): array
    {
        $id = self::toInt($product['id'] ?? null);
        $code = self::toStringOrNull($product['code'] ?? null);
        $name = self::toStringOrNull($product['name'] ?? null);
        $sectionId = self::toIntOrNull($product['iblockSectionId'] ?? null);

        $serviceType = self::toStringOrNull(self::getNested($product, ['property106', 'valueEnum']));

        $registry = self::normalizeNullableString(self::getNested($product, ['property122', 'value']));
        $periodicity = self::normalizeNullableString(self::getNested($product, ['property114', 'value']));

        $hours = self::toIntOrNull(self::normalizeNullableString(self::getNested($product, ['property112', 'value'])));
        $durationWorkDays = self::toIntOrNull(self::normalizeNullableString(self::getNested($product, ['property116', 'value'])));

        $format = self::normalizeNullableString(self::getNested($product, ['property124', 'value']));
        $educationLevel = self::normalizeNullableString(self::getNested($product, ['property128', 'value']));

        $requirementsRaw = self::normalizeNullableString(self::getNested($product, ['property126', 'value']));
        $requirements = self::requirementsToList($requirementsRaw);

        $priceDisplay = self::formatPriceDisplay($catalogPrice);

        return [
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'sectionId' => $sectionId,
            'serviceType' => $serviceType,
            'registry' => $registry,
            'periodicity' => $periodicity,
            'hours' => $hours,
            'durationWorkDays' => $durationWorkDays,
            'format' => $format,
            'educationLevel' => $educationLevel,
            'requirements' => $requirements,
            // For UI we use catalog prices (catalog.price.list), not property-based "money" fields.
            'priceRetail' => $catalogPrice,
            'priceDisplay' => $priceDisplay,
            'ui' => [
                'ctaLabel' => (string)($ui['ctaLabel'] ?? 'Оставить заявку'),
                'ctaAnchor' => (string)($ui['ctaAnchor'] ?? '#request'),
            ],
        ];
    }

    /**
     * @param array{amount:int,currency:string}|null $catalogPrice
     */
    public static function toCardItem(array $product, ?array $catalogPrice = null): array
    {
        $id = self::toInt($product['id'] ?? null);
        $name = (string)($product['name'] ?? '');

        $hours = self::toIntOrNull(self::normalizeNullableString(self::getNested($product, ['property112', 'value'])));
        $durationWorkDays = self::toIntOrNull(self::normalizeNullableString(self::getNested($product, ['property116', 'value'])));
        $priceDisplay = self::formatPriceDisplay($catalogPrice) ?? '';

        return [
            'id' => $id,
            'name' => $name,
            'hours' => $hours,
            'durationWorkDays' => $durationWorkDays,
            'priceDisplay' => $priceDisplay,
        ];
    }

    /**
     * @param array{amount:int,currency:string}|null $catalogPrice
     */
    public static function toSearchItem(array $product, ?array $catalogPrice = null): array
    {
        $item = self::toCardItem($product, $catalogPrice);
        return [
            'id' => $item['id'],
            'name' => $item['name'],
            'hours' => $item['hours'],
            'priceDisplay' => $item['priceDisplay'] !== '' ? $item['priceDisplay'] : null,
        ];
    }

    private static function requirementsToList(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $raw = trim($raw);
        if ($raw === '' || $raw === '-') {
            return [];
        }

        $parts = preg_split('/[\n;,]+/u', $raw) ?: [];
        $out = [];
        foreach ($parts as $p) {
            $p = trim((string)$p);
            if ($p === '' || $p === '-') {
                continue;
            }
            // strip leading list markers
            $p = preg_replace('/^\s*[-•]\s*/u', '', $p);
            $p = trim((string)$p);
            if ($p !== '') {
                $out[] = $p;
            }
        }

        // de-dup while preserving order
        $seen = [];
        $dedup = [];
        foreach ($out as $v) {
            $k = mb_strtolower($v);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $dedup[] = $v;
        }

        return $dedup;
    }

    /**
     * @param array{amount:int,currency:string}|null $price
     */
    private static function formatPriceDisplay(?array $price): ?string
    {
        if ($price === null) {
            return null;
        }

        $amount = $price['amount'] ?? null;
        $currency = $price['currency'] ?? null;
        if (!is_int($amount) || $amount <= 0 || !is_string($currency) || $currency === '') {
            return null;
        }

        $suffix = $currency === 'RUB' ? '₽' : $currency;
        return 'от ' . $amount . ' ' . $suffix;
    }

    private static function parseMoney(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '' || $raw === '-') {
            return null;
        }

        // Format: "5000|RUB"
        $parts = explode('|', $raw);
        if (count($parts) < 2) {
            return null;
        }

        $amount = self::toIntOrNull(trim($parts[0]));
        $currency = trim($parts[1]);
        if ($amount === null || $currency === '') {
            return null;
        }

        return ['amount' => $amount, 'currency' => $currency];
    }

    private static function getNested(array $arr, array $path): mixed
    {
        $cur = $arr;
        foreach ($path as $k) {
            if (!is_array($cur) || !array_key_exists($k, $cur)) {
                return null;
            }
            $cur = $cur[$k];
        }
        return $cur;
    }

    private static function normalizeNullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        if ($s === '' || $s === '-') {
            return null;
        }
        return $s;
    }

    private static function toStringOrNull(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private static function toInt(mixed $v): int
    {
        $n = self::toIntOrNull($v);
        return $n ?? 0;
    }

    private static function toIntOrNull(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }
        if (is_int($v)) {
            return $v;
        }
        $s = trim((string)$v);
        if ($s === '' || $s === '-') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $s)) {
            return null;
        }
        return (int)$s;
    }
}

