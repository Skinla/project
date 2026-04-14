<?php

declare(strict_types=1);

/**
 * Создаёт тестовый контакт и сделку с заполнением типовых полей CRM.
 *
 * Запуск из корня проекта: php scripts/create-test-deal.php
 */

$root = dirname(__DIR__);

define('TILDA_SKIP_WEBHOOK_RUN', true);
require_once $root . '/webhook.php';

define('TILDA_CONFIG_INCLUDE', true);
/** @var array<string, mixed> $config */
$config = require $root . '/config.php';

$b24 = new B24Client((string) $config['bitrix_webhook_base']);
$resolver = new StatusResolver($config);
$ids = $resolver->resolveSourceAndStage();

$tag = '[TEST ' . gmdate('Y-m-d H:i') . ' UTC]';

$contactFields = [
    'NAME' => 'Тест',
    'LAST_NAME' => 'Tilda-Webhook',
    'SECOND_NAME' => 'Полные поля',
    'PHONE' => [
        ['VALUE' => '+79001234567', 'VALUE_TYPE' => 'WORK'],
        ['VALUE' => '+79007654321', 'VALUE_TYPE' => 'MOBILE'],
    ],
    'EMAIL' => [
        ['VALUE' => 'tilda-test+' . gmdate('YmdHis') . '@example.com', 'VALUE_TYPE' => 'WORK'],
    ],
    'POST' => '123456',
    'ADDRESS' => 'Тестовая ул., д. 1',
    'ADDRESS_CITY' => 'Тест-город',
    'ADDRESS_POSTAL_CODE' => '33602',
    'ADDRESS_COUNTRY' => 'DE',
    'OPENED' => 'Y',
    'SOURCE_ID' => $ids['source_id'],
    'ASSIGNED_BY_ID' => (int) $config['assigned_by_id'],
    'COMMENTS' => $tag . ' Тестовый контакт из scripts/create-test-deal.php',
];

$contactResp = $b24->call('crm.contact.add', ['fields' => $contactFields]);
$contactId = (int) ($contactResp['result'] ?? 0);
if ($contactId <= 0) {
    fwrite(STDERR, "crm.contact.add: не получен ID\n" . json_encode($contactResp, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

$currencies = $b24->call('crm.currency.list', []);
$currencyId = 'EUR';
if (isset($currencies['result']) && is_array($currencies['result'])) {
    foreach ($currencies['result'] as $row) {
        if (is_array($row) && (($row['CURRENCY'] ?? '') === 'EUR' || ($row['CURRENCY'] ?? '') === 'RUB')) {
            $currencyId = (string) $row['CURRENCY'];
            break;
        }
    }
}

$begin = gmdate('Y-m-d');
$close = gmdate('Y-m-d', strtotime('+14 days'));

$dealFields = [
    'TITLE' => $tag . ' Заявка: тест всех полей',
    'COMMENTS' => '[B]' . $tag . "[/B]\nСделка создана скриптом create-test-deal.php. Данные клиента — в привязанном контакте.",
    'SOURCE_DESCRIPTION' => 'CLI scripts/create-test-deal.php | tranid=test:' . bin2hex(random_bytes(4)),
    'CATEGORY_ID' => (int) $config['category_id'],
    'STAGE_ID' => $ids['stage_id'],
    'SOURCE_ID' => $ids['source_id'],
    'ASSIGNED_BY_ID' => (int) $config['assigned_by_id'],
    'CONTACT_IDS' => [$contactId],
    'OPPORTUNITY' => 15000.5,
    'CURRENCY_ID' => $currencyId,
    'IS_MANUAL_OPPORTUNITY' => 'Y',
    'BEGINDATE' => $begin,
    'CLOSEDATE' => $close,
    'OPENED' => 'Y',
    'CLOSED' => 'N',
    'PROBABILITY' => 25,
    'UTM_SOURCE' => 'tilda',
    'UTM_MEDIUM' => 'webhook',
    'UTM_CAMPAIGN' => 'test_full_fields',
    'UTM_CONTENT' => 'create-test-deal',
    'UTM_TERM' => 'cli',
];

$dealResp = $b24->call('crm.deal.add', ['fields' => $dealFields]);
$dealId = $dealResp['result'] ?? null;

if ($dealId === null) {
    fwrite(STDERR, "crm.deal.add: нет result\n" . json_encode($dealResp, JSON_UNESCAPED_UNICODE) . "\n");
    exit(1);
}

echo "OK\n";
echo "contact_id: {$contactId}\n";
echo "deal_id: {$dealId}\n";
echo "currency: {$currencyId}\n";
