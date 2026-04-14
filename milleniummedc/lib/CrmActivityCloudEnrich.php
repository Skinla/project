<?php
/**
 * Дополнение данных активности из облака: crm.activity.list часто отдаёт укороченный DESCRIPTION;
 * для писем полный текст часто лежит в SETTINGS / PROVIDER_DATA (crm.activity.get + разбор вложенных полей).
 */
class CrmActivityCloudEnrich
{
    private const ACTIVITY_GET_TIMEOUT = 120;

    private const NESTED_BODY_MIN_LEN = 120;

    private const REPLACE_MARGIN = 40;

    /**
     * Подмешивает полные поля из crm.activity.get (тело письма, настройки провайдера).
     *
     * @param array<string, mixed> $act элемент из crm.activity.list (должен содержать ID)
     * @return array<string, mixed>
     */
    public static function mergeFullFieldsFromGet(BitrixRestClient $client, array $act): array
    {
        $aid = (int)($act['ID'] ?? 0);
        if ($aid <= 0) {
            return $act;
        }

        $listDesc = (string)($act['DESCRIPTION'] ?? '');
        $listSettings = isset($act['SETTINGS']) && is_array($act['SETTINGS']) ? $act['SETTINGS'] : [];
        $listParams = isset($act['PROVIDER_PARAMS']) && is_array($act['PROVIDER_PARAMS']) ? $act['PROVIDER_PARAMS'] : [];

        $g = $client->call('crm.activity.get', ['id' => $aid], self::ACTIVITY_GET_TIMEOUT);
        if (!empty($g['error']) || !is_array($g['result'] ?? null)) {
            return $act;
        }
        $r = $g['result'];
        foreach (['DESCRIPTION', 'DESCRIPTION_TYPE', 'SETTINGS', 'PROVIDER_PARAMS', 'PROVIDER_DATA'] as $k) {
            if (array_key_exists($k, $r)) {
                $act[$k] = $r[$k];
            }
        }

        if (isset($act['SETTINGS']) && is_array($act['SETTINGS'])) {
            $act['SETTINGS'] = self::mergeListGetArrayPreferLongerStrings($listSettings, $act['SETTINGS']);
        } elseif ($listSettings !== []) {
            $act['SETTINGS'] = $listSettings;
        }

        if (isset($act['PROVIDER_PARAMS']) && is_array($act['PROVIDER_PARAMS'])) {
            $act['PROVIDER_PARAMS'] = self::mergeListGetArrayPreferLongerStrings($listParams, $act['PROVIDER_PARAMS']);
        } elseif ($listParams !== []) {
            $act['PROVIDER_PARAMS'] = $listParams;
        }

        $mergedDesc = (string)($act['DESCRIPTION'] ?? '');
        if (mb_strlen($listDesc) > mb_strlen($mergedDesc)) {
            $act['DESCRIPTION'] = $listDesc;
        }

        self::hydrateDescriptionFromNested($act);

        return $act;
    }

    /**
     * @param mixed $listBranch
     * @param mixed $getBranch
     * @return array<string, mixed>
     */
    private static function mergeListGetArrayPreferLongerStrings($listBranch, $getBranch): array
    {
        if (!is_array($listBranch)) {
            $listBranch = [];
        }
        if (!is_array($getBranch)) {
            $getBranch = [];
        }
        $out = $getBranch;
        foreach ($listBranch as $k => $v) {
            if (!array_key_exists($k, $out)) {
                $out[$k] = $v;
                continue;
            }
            $gv = $out[$k];
            if (is_string($v) && is_string($gv) && mb_strlen($v) > mb_strlen($gv)) {
                $out[$k] = $v;
            } elseif (is_array($v) && is_array($gv)) {
                $out[$k] = self::mergeListGetArrayPreferLongerStrings($v, $gv);
            }
        }

        return $out;
    }

    /**
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private static function normalizeProviderDataForScan($raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $j = json_decode($raw, true);

            return is_array($j) ? $j : [];
        }

        return [];
    }

    private static function isPlausibleEmailBody(string $s): bool
    {
        if (mb_strlen($s) < self::NESTED_BODY_MIN_LEN) {
            return false;
        }
        if (mb_strlen($s) >= 800) {
            return true;
        }
        if (preg_match('/[\r\n]/', $s)) {
            return true;
        }
        if (preg_match('/<[a-z][\s>\/]/i', $s)) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $node
     * @param list<string> $bucket
     */
    private static function collectSubstantialStrings($node, array &$bucket): void
    {
        if (is_string($node)) {
            if (!self::isPlausibleEmailBody($node)) {
                return;
            }
            if (preg_match('/^[A-Za-z0-9_\-]{1,240}$/', $node)) {
                return;
            }
            if (preg_match('!^https?://\S+$!', trim($node)) && mb_strlen($node) < 500) {
                return;
            }
            $bucket[] = $node;

            return;
        }
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $child) {
            self::collectSubstantialStrings($child, $bucket);
        }
    }

    /**
     * @param list<string> $strings
     */
    private static function pickLongest(array $strings): ?string
    {
        $best = null;
        $bestLen = 0;
        foreach ($strings as $s) {
            if (!is_string($s)) {
                continue;
            }
            $len = mb_strlen($s);
            if ($len > $bestLen) {
                $bestLen = $len;
                $best = $s;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $act
     */
    private static function hydrateDescriptionFromNested(array &$act): void
    {
        $desc = (string)($act['DESCRIPTION'] ?? '');
        $descLen = mb_strlen($desc);
        $bucket = [];
        $roots = [
            $act['SETTINGS'] ?? [],
            $act['PROVIDER_PARAMS'] ?? [],
            self::normalizeProviderDataForScan($act['PROVIDER_DATA'] ?? null),
        ];
        foreach ($roots as $root) {
            self::collectSubstantialStrings($root, $bucket);
        }
        $best = self::pickLongest($bucket);
        if ($best === null) {
            return;
        }
        if (mb_strlen($best) <= $descLen + self::REPLACE_MARGIN) {
            return;
        }
        $act['DESCRIPTION'] = $best;
        if (preg_match('/<[a-z][\s>\/]/i', $best)) {
            $act['DESCRIPTION_TYPE'] = 3;
        }
    }
}
