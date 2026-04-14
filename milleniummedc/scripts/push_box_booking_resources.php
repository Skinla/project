<?php
/**
 * Создаёт на коробке ресурсы «Онлайн-запись» по JSON из облака.
 * Запуск ТОЛЬКО на сервере коробки, от пользователя bitrix/root с доступом к Битрикс:
 *   php push_box_booking_resources.php /path/to/booking_resources_cloud.json
 *
 * В b_booking_resource.EXTERNAL_ID пишется id ресурса в облаке (идемпотентность).
 * В конец печатается JSON для data/booking_resource_mapping.json (ключ resources).
 */
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Usage: php push_box_booking_resources.php /path/to/booking_resources_cloud.json\n");
    exit(1);
}

$jsonPath = $argv[1];
$raw = file_get_contents($jsonPath);
if ($raw === false) {
    fwrite(STDERR, "Cannot read {$jsonPath}\n");
    exit(1);
}
$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "Invalid JSON\n");
    exit(1);
}
$cloudList = $data['resources'] ?? $data;
if (!is_array($cloudList)) {
    fwrite(STDERR, "No resources array in JSON\n");
    exit(1);
}

$_SERVER['DOCUMENT_ROOT'] = '/home/bitrix/www';
$_SERVER['SERVER_NAME'] = 'localhost';
define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

if (!\Bitrix\Main\Loader::includeModule('booking')) {
    fwrite(STDERR, "Module booking not available\n");
    exit(1);
}

use Bitrix\Booking\Command\Resource\AddResourceCommand;
use Bitrix\Booking\Entity\Resource\Resource;
use Bitrix\Booking\Internals\Model\ResourceTable;

global $USER;
if (is_object($USER) && method_exists($USER, 'Authorize')) {
    $USER->Authorize(1);
}

$typeRepo = \Bitrix\Booking\Internals\Container::getResourceTypeRepository();

$yn = static function ($v): bool {
    return $v === 'Y' || $v === true || $v === 1 || $v === '1';
};

$resolveBoxTypeId = static function (array $c) use ($typeRepo): int {
    $tid = (int)($c['typeId'] ?? 1);
    if ($tid > 0 && $typeRepo->isExists($tid)) {
        return $tid;
    }

    return 1;
};

$cloudToPayload = static function (array $c, int $boxTypeId) use ($yn): array {
    $payload = [
        'name' => (string)($c['name'] ?? ''),
        'type' => ['id' => $boxTypeId],
        'externalId' => (int)$c['id'],
        'isMain' => $yn($c['isMain'] ?? 'Y'),
    ];
    if ($payload['name'] === '') {
        throw new InvalidArgumentException('empty name for cloud id ' . ($c['id'] ?? '?'));
    }
    $desc = $c['description'] ?? null;
    if ($desc !== null && $desc !== '') {
        $payload['description'] = (string)$desc;
    }

    $optionalBool = [
        'isInfoNotificationOn',
        'isConfirmationNotificationOn',
        'isReminderNotificationOn',
        'isFeedbackNotificationOn',
        'isDelayedNotificationOn',
    ];
    foreach ($optionalBool as $k) {
        if (array_key_exists($k, $c) && $c[$k] !== null) {
            $payload[$k] = $yn($c[$k]);
        }
    }

    $optionalInt = [
        'infoNotificationDelay',
        'confirmationNotificationDelay',
        'confirmationNotificationRepetitions',
        'confirmationNotificationRepetitionsInterval',
        'confirmationCounterDelay',
        'reminderNotificationDelay',
        'delayedNotificationDelay',
        'delayedCounterDelay',
    ];
    foreach ($optionalInt as $k) {
        if (array_key_exists($k, $c) && $c[$k] !== null && $c[$k] !== '') {
            $payload[$k] = (int)$c[$k];
        }
    }

    $optionalString = [
        'templateTypeInfo',
        'templateTypeConfirmation',
        'templateTypeReminder',
        'templateTypeFeedback',
        'templateTypeDelayed',
    ];
    foreach ($optionalString as $k) {
        if (!empty($c[$k]) && is_string($c[$k])) {
            $payload[$k] = $c[$k];
        }
    }

    return $payload;
};

$mapping = [];
$created = 0;
$skipped = 0;
$errors = [];

foreach ($cloudList as $c) {
    if (!is_array($c) || empty($c['id'])) {
        continue;
    }
    $cloudId = (int)$c['id'];
    $existing = ResourceTable::getList([
        'filter' => ['=EXTERNAL_ID' => $cloudId],
        'select' => ['ID'],
        'limit' => 1,
    ])->fetch();
    if ($existing && (int)$existing['ID'] > 0) {
        $mapping[(string)$cloudId] = (int)$existing['ID'];
        $skipped++;
        continue;
    }

    try {
        $boxTypeId = $resolveBoxTypeId($c);
        $payload = $cloudToPayload($c, $boxTypeId);
        $entity = Resource::mapFromArray($payload);
        $cmd = new AddResourceCommand(createdBy: 1, resource: $entity);
        $result = $cmd->run();
        if (!$result->isSuccess()) {
            $msgs = array_map(static fn($e) => $e->getMessage(), $result->getErrors());
            $errors[] = "cloud {$cloudId}: " . implode('; ', $msgs);
            continue;
        }
        $newId = (int)$result->getResource()->getId();
        if ($newId > 0) {
            $mapping[(string)$cloudId] = $newId;
            $created++;
        }
    } catch (Throwable $e) {
        $errors[] = "cloud {$cloudId}: " . $e->getMessage();
    }
}

ksort($mapping, SORT_NUMERIC);

$out = [
    '_note' => 'Автогенерация: cloud resource id → id на коробке (EXTERNAL_ID в БД).',
    'resources' => $mapping,
];

echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

fwrite(STDERR, "Created: {$created}, skipped (already): {$skipped}, errors: " . count($errors) . "\n");
foreach ($errors as $line) {
    fwrite(STDERR, $line . "\n");
}

exit($errors !== [] ? 2 : 0);
