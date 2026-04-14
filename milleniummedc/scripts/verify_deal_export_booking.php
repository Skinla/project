<?php
/**
 * Проверка выгрузки сделки: активности CRM_BOOKING с привязкой ресурса (SETTINGS.FIELDS.resources[].id).
 *
 *   php scripts/verify_deal_export_booking.php path/to/deals_batch.json
 *
 * Файл: массив элементов { activities: [...] } или один объект с activities.
 * Код выхода: 0 если ок, 1 если есть ошибки.
 */
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php scripts/verify_deal_export_booking.php <deals_json>\n");
    exit(1);
}

$path = $argv[1];
$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "Cannot read {$path}\n");
    exit(1);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON\n");
    exit(1);
}

$items = isset($data['activities']) ? [$data] : $data;
$errors = [];
$checked = 0;

foreach ($items as $idx => $item) {
    if (!is_array($item)) {
        continue;
    }
    $acts = $item['activities'] ?? [];
    if (!is_array($acts)) {
        continue;
    }
    foreach ($acts as $ai => $act) {
        if (!is_array($act) || ($act['PROVIDER_ID'] ?? '') !== 'CRM_BOOKING') {
            continue;
        }
        $checked++;
        $settings = $act['SETTINGS'] ?? null;
        if (is_string($settings)) {
            $settings = json_decode($settings, true);
        }
        $fields = is_array($settings) && isset($settings['FIELDS']) && is_array($settings['FIELDS'])
            ? $settings['FIELDS']
            : [];
        $resList = $fields['resources'] ?? null;
        if (!is_array($resList) || $resList === []) {
            $errors[] = "item#{$idx} act#{$ai}: FIELDS.resources missing or empty";
            continue;
        }
        foreach ($resList as $ri => $r) {
            if (!is_array($r)) {
                $errors[] = "item#{$idx} act#{$ai} res#{$ri}: not an object";
                continue;
            }
            $rid = isset($r['id']) ? (int)$r['id'] : 0;
            if ($rid <= 0) {
                $errors[] = "item#{$idx} act#{$ai} res#{$ri}: missing or invalid id (need box resource id)";
            }
        }
        $mb = $act['migration_booking'] ?? null;
        if (is_array($mb)) {
            $payRes = $mb['payload']['resources'] ?? null;
            if (is_array($payRes)) {
                foreach ($payRes as $pri => $pr) {
                    $pid = is_array($pr) ? (int)($pr['id'] ?? 0) : 0;
                    if ($pid <= 0) {
                        $errors[] = "item#{$idx} act#{$ai} migration_booking.payload.resources#{$pri}: invalid id";
                    }
                }
            }
        }
    }
}

echo "CRM_BOOKING activities checked: {$checked}\n";
if ($errors !== []) {
    echo "Errors:\n" . implode("\n", $errors) . "\n";
    exit(1);
}

echo "OK: all booking activities have resource id in FIELDS.resources\n";
exit(0);
