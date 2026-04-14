<?php
/**
 * Виджет "Начисление баллов" в попапе.
 * Работает только в одном режиме: отдельный endpoint /local/widgets/bonus/score_widget_handler.php.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

use Bitrix\Main\Application;
use Bitrix\Main\Loader;

/**
 * Примитивный логгер для отладки начисления бонусов.
 */
$bonusScoreWidgetLogPath = $_SERVER['DOCUMENT_ROOT'] . '/local/widgets/bonus/bonus_score_widget.log';
$bonusScoreWidgetLog = static function (string $message, array $context = []) use ($bonusScoreWidgetLogPath): void {
    $logLine = sprintf(
        '[%s] %s %s%s',
        date('Y-m-d H:i:s'),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        PHP_EOL
    );

    @file_put_contents($bonusScoreWidgetLogPath, $logLine, FILE_APPEND);
};

// Загружаем общий конфиг бонусов (тот же, что используется в profile_widget_handler.php)
$configPath = __DIR__ . '/config.php';
if (!file_exists($configPath)) {
    echo '<!-- Виджет начисления бонусов: не найден config.php -->';
    return;
}

$config = require $configPath;

// Настройки списка причин и БП для начисления
$reasonUfName = (string)($config['scoring_reason_uf_name'] ?? 'UF_CRM_29_1764154224');
$reasonValueForNonLeaders = (string)($config['scoring_reason_value_for_non_leaders'] ?? 'ПЕРЕВОД');
$leaderGroupIds = (array)($config['scoring_leader_group_ids'] ?? [1]);
$bpEnabled = (bool)($config['scoring_bp_enabled'] ?? false);
$bpTemplateId = (int)($config['scoring_bp_template_id'] ?? 0);
$bpDocumentType = (string)($config['scoring_bp_document_type'] ?? 'CRM_DYNAMIC_1156');
$bpDocumentId = (int)($config['scoring_bp_document_id'] ?? 0);
$bpWebhookUrl = (string)($config['scoring_bp_webhook_url'] ?? '');

/**
 * Запуск бизнес-процесса начисления бонусов.
 *
 * @param int      $recipientId  ID профиля сотрудника (получатель)
 * @param int      $initiatorId  ID текущего пользователя (инициатор)
 * @param int      $reasonEnumId ID значения списка причины (UF enum ID)
 * @param float    $amount       Количество баллов
 * @param bool     $bpEnabled    Включен ли запуск БП
 * @param int      $templateId   ID шаблона БП
 * @param string   $documentType Тип документа смарт-процесса (CRM_DYNAMIC_1156)
 * @param int      $documentId   ID элемента смарт-процесса
 * @param string   $webhookUrl   URL вебхука bizproc.workflow.start
 * @param callable $logger       Логгер
 *
 * @return array{success:bool,message:string}
 */
function bonusScoreStartWorkflow(
    int $recipientId,
    int $initiatorId,
    int $reasonEnumId,
    float $amount,
    bool $bpEnabled,
    int $templateId,
    string $documentType,
    int $documentId,
    string $webhookUrl,
    callable $logger
): array {
    if (!$bpEnabled) {
        return [
            'success' => true,
            'message' => 'Запуск бизнес-процесса отключен в конфигурации. Данные приняты, но БП не запускался.',
        ];
    }

    if ($templateId <= 0 || $documentId <= 0 || $documentType === '' || $webhookUrl === '') {
        return [
            'success' => false,
            'message' => 'Некорректная конфигурация БП (template/document/webhook).',
        ];
    }

    $typeId = (int)preg_replace('/^CRM_DYNAMIC_/', '', $documentType);
    if ($typeId <= 0) {
        return [
            'success' => false,
            'message' => 'Некорректный scoring_bp_document_type.',
        ];
    }

    $documentIdArr = [
        'crm',
        'Bitrix\Crm\Integration\BizProc\Document\Dynamic',
        'DYNAMIC_' . $typeId . '_' . $documentId,
    ];

    // Получаем текстовое значение причины из enum
    $reasonText = '';
    if ($reasonEnumId > 0 && Loader::includeModule('main')) {
        $enumRes = \CUserFieldEnum::GetList(
            [],
            ['ID' => $reasonEnumId]
        );
        if ($enum = $enumRes->Fetch()) {
            $reasonText = (string)($enum['VALUE'] ?? '');
        }
    }

    // Параметры БП согласно шаблону:
    // {=Template:Recipient_Credit} - получатель, строка, множ.
    // {=Template:Bonus_Scores_Sender} - отправитель, строка, ед.
    // {=Template:Bonus_Quantity} - количество, строка, ед.
    // {=Template:Charge_Reason} - причина, строка, ед.
    $params = [
        'Recipient_Credit' => (string)$recipientId,
        'Bonus_Scores_Sender' => (string)$initiatorId,
        'Bonus_Quantity' => (string)$amount,
        'Charge_Reason' => $reasonText !== '' ? $reasonText : (string)$reasonEnumId,
    ];

    $logger('Bonus BP start: request build', [
        'template_id' => $templateId,
        'document_id' => $documentIdArr,
        'params' => $params,
        'webhook' => $webhookUrl,
    ]);

    $requestData = [
        'TEMPLATE_ID' => $templateId,
        'DOCUMENT_ID' => $documentIdArr,
        'PARAMETERS' => $params,
    ];

    $ch = curl_init($webhookUrl);
    if ($ch === false) {
        return [
            'success' => false,
            'message' => 'Не удалось инициализировать cURL для вызова БП.',
        ];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($requestData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        $logger('Bonus BP start: cURL failed', [
            'error' => $curlError,
            'http_code' => $httpCode,
        ]);
        return [
            'success' => false,
            'message' => 'Ошибка HTTP-запроса при запуске БП: ' . $curlError,
        ];
    }

    $decoded = json_decode($response, true);
    if (!is_array($decoded)) {
        $logger('Bonus BP start: invalid JSON', [
            'response' => substr($response, 0, 500),
        ]);
        return [
            'success' => false,
            'message' => 'Некорректный JSON-ответ при запуске БП.',
        ];
    }

    if (isset($decoded['error'])) {
        $logger('Bonus BP start: error from REST', [
            'error' => $decoded['error'],
            'error_description' => $decoded['error_description'] ?? null,
            'response' => $decoded,
        ]);
        return [
            'success' => false,
            'message' => 'Ошибка запуска БП: ' . $decoded['error'] . ' - ' . ($decoded['error_description'] ?? 'без описания'),
        ];
    }

    $logger('Bonus BP start: success', [
        'http_code' => $httpCode,
        'response' => $decoded,
    ]);

    return [
        'success' => true,
        'message' => 'Бизнес-процесс успешно запущен.',
    ];
}

// AJAX-запрос начисления
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'bonus_score') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');

    /** @var \CUser|null $USER */
    global $USER;

    $recipientId = (int)($_POST['recipient_id'] ?? 0);
    $reasonEnumId = (int)($_POST['reason_enum_id'] ?? 0);
    $amount = (float)($_POST['amount'] ?? 0.0);
    $currentUserId = is_object($USER) ? (int)$USER->GetID() : 0;

    if ($recipientId <= 0 || $currentUserId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Не удалось определить получателя или текущего пользователя.',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($amount <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Сумма начисления должна быть больше нуля.',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    if ($reasonEnumId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Не выбрана причина начисления.',
        ], JSON_UNESCAPED_UNICODE);
        return;
    }

    $result = bonusScoreStartWorkflow(
        $recipientId,
        $currentUserId,
        $reasonEnumId,
        $amount,
        $bpEnabled,
        $bpTemplateId,
        $bpDocumentType,
        $bpDocumentId,
        $bpWebhookUrl,
        $bonusScoreWidgetLog
    );

    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    return;
}

// Дальше — отрисовка попап-виджета начисления
$request = Application::getInstance()->getContext()->getRequest();
$userId = (int)($request->get('USER_ID') ?? 0);

if ($userId === 0) {
    $requestUri = $request->getRequestUri();
    if (preg_match('/user\/(\d+)/', $requestUri, $matches)) {
        $userId = (int)$matches[1];
    }
}

if ($userId === 0) {
    echo '<!-- Виджет начисления бонусов: не удалось определить пользователя -->';
    return;
}

if (!Loader::includeModule('main')) {
    echo '<!-- Виджет начисления бонусов: модуль main не загружен -->';
    return;
}

/** @var \CUser|null $USER */
global $USER;

// Текущий авторизованный пользователь (тот, кто начисляет)
$currentUserId = (is_object($USER) && method_exists($USER, 'GetID'))
    ? (int)$USER->GetID()
    : 0;

// Является ли текущий пользователь руководителем
$isLeader = false;
if ($currentUserId > 0) {
    try {
        // Администраторы портала всегда считаются руководителями
        if (is_object($USER) && method_exists($USER, 'IsAdmin') && $USER->IsAdmin()) {
            $isLeader = true;
            $bonusScoreWidgetLog('Leader check via IsAdmin()', [
                'current_user_id' => $currentUserId,
            ]);
        }

        // Сначала проверяем через группы из конфига (самый надёжный способ)
        if (!$isLeader && Loader::includeModule('main') && !empty($leaderGroupIds)) {
            $userGroups = \CUser::GetUserGroup($currentUserId);
            // Проверяем, есть ли пользователь в группах руководителей из конфига
            foreach ($leaderGroupIds as $leaderGroupId) {
                if (in_array((int)$leaderGroupId, $userGroups, true)) {
                    $isLeader = true;
                    $bonusScoreWidgetLog('Leader check via user groups (config)', [
                        'current_user_id' => $currentUserId,
                        'user_groups' => $userGroups,
                        'leader_group_id' => $leaderGroupId,
                    ]);
                    break;
                }
            }
        }
        
        // Если не определили через группы, пробуем через структуру компании
        if (!$isLeader && Loader::includeModule('intranet')) {
            try {
                $structureIblockId = \COption::GetOptionInt('intranet', 'iblock_structure');
                if ($structureIblockId > 0) {
                    $dbRes = \CIBlockSection::GetList(
                        [],
                        ['IBLOCK_ID' => $structureIblockId],
                        false,
                        ['ID', 'UF_HEAD']
                    );
                    while ($section = $dbRes->Fetch()) {
                        if (!empty($section['UF_HEAD']) && (int)$section['UF_HEAD'] === $currentUserId) {
                            $isLeader = true;
                            $bonusScoreWidgetLog('Leader check via structure sections', [
                                'current_user_id' => $currentUserId,
                                'section_id' => $section['ID'],
                            ]);
                            break;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Игнорируем ошибки проверки структуры
            }
        }
        
        if (!$isLeader) {
            $bonusScoreWidgetLog('Leader check - not a leader', [
                'current_user_id' => $currentUserId,
                'leader_group_ids' => $leaderGroupIds,
            ]);
        }
    } catch (\Throwable $e) {
        $isLeader = false;
        $bonusScoreWidgetLog('Failed to determine leader', [
            'current_user_id' => $currentUserId,
            'error' => $e->getMessage(),
        ]);
    }
}

$bonusScoreWidgetLog('Bonus scoring popup access', [
    'profile_user_id' => $userId,
    'current_user_id' => $currentUserId,
    'is_leader' => $isLeader ? 'Y' : 'N',
]);

// Расчёт текущей суммы бонусов — так же, как в profile_widget_handler.php
$entityTypeIdRaw = $config['entity_type_id'] ?? null;
$bonusField = (string)($config['bonus_field'] ?? '');

if ($entityTypeIdRaw === null || $bonusField === '') {
    echo '<!-- Виджет начисления бонусов: некорректная конфигурация бонусов -->';
    return;
}

$normalizeEntityTypeId = static function ($value) {
    if (is_numeric($value)) {
        return (int)$value;
    }

    if (is_string($value) && preg_match('/^DYNAMIC_(\d+)$/', $value, $matches)) {
        return (int)$matches[1];
    }

    return $value;
};

$entityTypeId = $normalizeEntityTypeId($entityTypeIdRaw);
$currentBonus = 0.0;

if (is_int($entityTypeId) && $entityTypeId > 0 && Loader::includeModule('crm')) {
    try {
        $container = \Bitrix\Crm\Service\Container::getInstance();
        $factory = $container->getFactory($entityTypeId);

        if ($factory) {
            $userField = (string)($config['user_field'] ?? 'ASSIGNED_BY_ID');

            $items = $factory->getItems([
                'filter' => [
                    $userField => $userId,
                ],
                'select' => [$bonusField],
            ]);

            $totalBonus = 0.0;

            foreach ($items as $item) {
                $bonusValue = $item->get($bonusField);
                if ($bonusValue !== null) {
                    $totalBonus += (float)$bonusValue;
                }
            }

            $currentBonus = $totalBonus;
        }
    } catch (\Throwable $e) {
        $bonusScoreWidgetLog('Bonus popup calculation error', [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
    }
}

$formattedCurrentBonus = $currentBonus > 0.0
    ? number_format($currentBonus, 0, ',', ' ')
    : 'нет';
$currencyName = htmlspecialchars($config['currency_name'] ?? 'Мечты - валюта Команды Мечты');

// Имена участников
function bonusGetUserName(int $userId): string
{
    if ($userId <= 0) {
        return '';
    }

    $res = \CUser::GetByID($userId);
    if ($user = $res->Fetch()) {
        $name = trim((string)($user['NAME'] ?? '') . ' ' . (string)($user['LAST_NAME'] ?? ''));
        if ($name === '') {
            $name = (string)($user['LOGIN'] ?? '');
        }
        return $name;
    }

    return '';
}

$initiatorName = bonusGetUserName($currentUserId);
$recipientName = bonusGetUserName($userId);

// Загружаем список значений пользовательского поля
$reasonOptions = [];
$transferOptionOnly = null;

$bonusScoreWidgetLog('Loading reason enum list', [
    'reason_uf_name' => $reasonUfName,
    'reason_value_for_non_leaders' => $reasonValueForNonLeaders,
    'is_leader' => $isLeader ? 'Y' : 'N',
    'current_user_id' => $currentUserId,
]);

if (Loader::includeModule('crm')) {
    // Сначала пытаемся найти поле по имени
    $userFieldEntity = new \CUserTypeEntity();
    $fieldRes = $userFieldEntity->GetList(
        [],
        ['FIELD_NAME' => $reasonUfName]
    );

    $fieldId = null;
    $entityId = null;
    if ($field = $fieldRes->Fetch()) {
        $fieldId = (int)$field['ID'];
        $entityId = (string)($field['ENTITY_ID'] ?? '');
        $bonusScoreWidgetLog('User field found', [
            'field_id' => $fieldId,
            'entity_id' => $entityId,
        ]);
    } else {
        // Пробуем найти через entity_id для смарт-процесса 1156
        $entityId = 'CRM_' . $reasonUfName;
        $fieldRes2 = $userFieldEntity->GetList(
            [],
            ['FIELD_NAME' => $reasonUfName, 'ENTITY_ID' => 'CRM_DYNAMIC_1156']
        );
        if ($field2 = $fieldRes2->Fetch()) {
            $fieldId = (int)$field2['ID'];
            $entityId = (string)($field2['ENTITY_ID'] ?? '');
            $bonusScoreWidgetLog('User field found via CRM_DYNAMIC_1156', [
                'field_id' => $fieldId,
                'entity_id' => $entityId,
            ]);
        } else {
            $bonusScoreWidgetLog('User field not found by name', [
                'reason_uf_name' => $reasonUfName,
            ]);
        }
    }

    // Загружаем значения enum
    $enumRes = \CUserFieldEnum::GetList(
        ['SORT' => 'ASC'],
        $fieldId ? ['USER_FIELD_ID' => $fieldId] : ['USER_FIELD_NAME' => $reasonUfName]
    );

    if ($enumRes) {
        $foundCount = 0;
        while ($enum = $enumRes->GetNext()) {
            $foundCount++;
            $option = [
                'ID' => (int)$enum['ID'],
                'VALUE' => (string)$enum['VALUE'],
                'XML_ID' => (string)($enum['XML_ID'] ?? ''),
            ];

            $reasonOptions[] = $option;

            if (mb_strtolower(trim($option['VALUE'])) === mb_strtolower(trim($reasonValueForNonLeaders))) {
                $transferOptionOnly = $option;
            }
        }

        $bonusScoreWidgetLog('Reason enum loaded', [
            'found_count' => $foundCount,
            'all_options' => $reasonOptions,
            'transfer_option_found' => $transferOptionOnly !== null ? 'Y' : 'N',
        ]);
    } else {
        $bonusScoreWidgetLog('CUserFieldEnum::GetList returned false/null', [
            'reason_uf_name' => $reasonUfName,
            'field_id' => $fieldId,
            'entity_id' => $entityId,
        ]);
    }
} else {
    $bonusScoreWidgetLog('CRM module not loaded', []);
}

// Для руководителя — все значения, для остальных — только "перевод"
if ($isLeader) {
    $visibleReasonOptions = $reasonOptions;
} else {
    $visibleReasonOptions = $transferOptionOnly ? [$transferOptionOnly] : [];
}

$bonusScoreWidgetLog('Final visible reasons', [
    'is_leader' => $isLeader ? 'Y' : 'N',
    'visible_count' => count($visibleReasonOptions),
    'visible_options' => $visibleReasonOptions,
]);

$hasVisibleReasons = !empty($visibleReasonOptions);

// URL'ы "Как потратить" и "История начислений"
$entityTypeIdRaw = $config['entity_type_id'] ?? '';
$urlTemplateReplacements = [
    '{USER_ID}' => (string)$userId,
    '{ENTITY_TYPE_ID}' => (string)$entityTypeIdRaw,
];
$howToSpendTemplate = $config['how_to_spend_url'] ?? 'https://bitrix.dreamteamcompany.ru/knowledge/bonus/';
$historyTemplate = $config['history_url'] ?? '/crm/type/{ENTITY_TYPE_ID}/';
$howToSpendUrl = strtr($howToSpendTemplate, $urlTemplateReplacements);
$historyUrl = strtr($historyTemplate, $urlTemplateReplacements);
$howToSpendUrlAttr = htmlspecialchars($howToSpendUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$historyUrlAttr = htmlspecialchars($historyUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

?>
<div class="intranet-bonus-score-popup" id="intranet-user-profile-bonus-score-widget">
    <div class="intranet-bonus-widget intranet-bonus-widget--scoring">
        <div class="intranet-bonus-widget-left">
            <div class="intranet-bonus-widget-value"><?= $formattedCurrentBonus ?></div>
            <div class="intranet-bonus-widget-meta">
                <div class="intranet-bonus-widget-label">бонусы</div>
                <div class="intranet-bonus-widget-note"><?= $currencyName ?></div>
            </div>
        </div>
        <div class="intranet-bonus-widget-right">
            <div class="intranet-bonus-score-form-row-inline">
                <span class="intranet-bonus-score-label-inline">Отправитель:</span>
                <span class="intranet-bonus-score-text-inline"><?= htmlspecialchars($initiatorName !== '' ? $initiatorName : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>
            <div class="intranet-bonus-score-form-row-inline">
                <span class="intranet-bonus-score-label-inline">Получатель:</span>
                <span class="intranet-bonus-score-text-inline"><?= htmlspecialchars($recipientName !== '' ? $recipientName : '—', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
            </div>

            <div class="intranet-bonus-score-form-row">
                <label class="intranet-bonus-score-label" for="bonus-score-amount">Количество баллов</label>
                <input
                    type="number"
                    min="1"
                    step="1"
                    id="bonus-score-amount"
                    class="intranet-bonus-score-input"
                    placeholder="Например, 100"
                />
            </div>
            <div class="intranet-bonus-score-form-row">
                <label class="intranet-bonus-score-label" for="bonus-score-reason">Причина начисления</label>
                <select
                    id="bonus-score-reason"
                    class="intranet-bonus-score-input intranet-bonus-score-select"
                >
                    <?php if (!$hasVisibleReasons): ?>
                        <option value="">Причины не найдены</option>
                    <?php else: ?>
                        <option value="">Выберите причину</option>
                        <?php foreach ($visibleReasonOptions as $option): ?>
                            <option value="<?= (int)$option['ID'] ?>">
                                <?= htmlspecialchars($option['VALUE'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="intranet-bonus-score-actions">
                <button
                    type="button"
                    id="bonus-score-submit"
                    class="intranet-bonus-score-button"
                    data-recipient-id="<?= (int)$userId ?>"
                >
                    Начислить
                </button>
                <div class="intranet-bonus-score-result" id="bonus-score-result" aria-live="polite"></div>
            </div>
            <div class="intranet-bonus-links-row">
                <a href="#" class="intranet-bonus-link" onclick="openHowToSpend('<?= $howToSpendUrlAttr ?>'); return false;">Как потратить?</a>
                <a href="#" class="intranet-bonus-link" onclick="openHistory(event, '<?= $historyUrlAttr ?>'); return false;">История начислений</a>
            </div>
        </div>
    </div>
</div>

<style>
    :root {
        --bonus-brand-lightest: #cbd3fd;
        --bonus-brand-lighter: #b5c0e9;
        --bonus-brand-light: #a5afd5;
        --bonus-brand-base: #8992b4;
        --bonus-brand-dark: #2c365e;
        --bonus-gray: #6f7380;
        --bonus-danger: #d9534f;
        --bonus-success: #2c9c5a;
    }

    .intranet-bonus-score-input,
    .intranet-bonus-score-label,
    .intranet-bonus-score-button,
    .intranet-bonus-score-result {
        font-family: 'Gilroy', 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
    }

    .intranet-bonus-score-popup {
        padding: 0;
    }

    .intranet-bonus-widget--scoring {
        display: flex;
        align-items: stretch;
        justify-content: space-between;
        gap: 24px;
        padding: 18px 22px;
        border-radius: 20px;
        background: linear-gradient(130deg, var(--bonus-brand-lightest), var(--bonus-brand-dark));
        box-shadow: 0 10px 24px rgba(44, 54, 94, 0.25);
        color: #fff;
    }

    .intranet-bonus-widget {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 18px;
        padding: 16px 22px;
        border-radius: 20px;
        background: linear-gradient(130deg, var(--bonus-brand-lightest), var(--bonus-brand-dark));
        box-shadow: 0 10px 24px rgba(44, 54, 94, 0.25);
        color: #fff;
        min-height: 78px;
    }

    .intranet-bonus-widget-left {
        display: flex;
        flex-direction: column;
        justify-content: center;
        gap: 8px;
        min-width: 180px;
    }

    .intranet-bonus-widget-right {
        flex: 1;
        background: rgba(10, 15, 40, 0.1);
        border-radius: 16px;
        padding: 12px 16px;
        display: flex;
        flex-direction: column;
        gap: 10px;
    }

    .intranet-bonus-widget-value {
        font-size: 32px;
        line-height: 1;
        font-weight: 700;
        white-space: nowrap;
        text-shadow: 0 2px 8px rgba(44, 54, 94, 0.35);
    }

    .intranet-bonus-widget-meta {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        gap: 4px;
        text-transform: uppercase;
    }

    .intranet-bonus-widget-label {
        font-size: 13px;
        letter-spacing: 0.08em;
        color: rgba(255, 255, 255, 0.9);
    }

    .intranet-bonus-widget-note {
        font-size: 11px;
        text-transform: none;
        color: rgba(255, 255, 255, 0.75);
    }

    .intranet-bonus-score-form-row {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .intranet-bonus-score-label {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: rgba(255, 255, 255, 0.8);
        font-weight: 600;
    }

    .intranet-bonus-score-input {
        border-radius: 10px;
        border: none;
        padding: 8px 10px;
        font-size: 13px;
        color: #1c2540;
        outline: none;
        background-color: #ffffff;
    }

    .intranet-bonus-score-input::placeholder {
        color: #a0a5b5;
    }

    .intranet-bonus-score-text {
        font-size: 13px;
        color: rgba(255, 255, 255, 0.95);
        opacity: 1;
    }

    .intranet-bonus-score-form-row-inline {
        display: flex;
        align-items: center;
        gap: 6px;
        margin-bottom: 4px;
    }

    .intranet-bonus-score-label-inline {
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: rgba(255, 255, 255, 0.8);
    }

    .intranet-bonus-score-text-inline {
        font-size: 13px;
        color: rgba(255, 255, 255, 0.95);
        opacity: 1;
    }

    .intranet-bonus-score-select {
        appearance: none;
        -webkit-appearance: none;
        -moz-appearance: none;
        background-color: #ffffff;
        background-image: linear-gradient(45deg, transparent 50%, #a0a5b5 50%), linear-gradient(135deg, #a0a5b5 50%, transparent 50%);
        background-position: calc(100% - 14px) calc(50% - 3px), calc(100% - 9px) calc(50% - 3px);
        background-size: 6px 6px, 6px 6px;
        background-repeat: no-repeat;
    }

    .intranet-bonus-score-select option {
        background-color: #ffffff;
        color: #1c2540;
    }

    .intranet-bonus-score-select option:checked,
    .intranet-bonus-score-select option:focus,
    .intranet-bonus-score-select option:hover {
        background-color: #2C365E !important;
        color: #fff !important;
    }

    .intranet-bonus-score-select:focus {
        outline: 2px solid #2C365E;
        outline-offset: 2px;
    }

    .intranet-bonus-score-form-row-recipients {
        align-items: flex-start;
        gap: 8px;
    }

    .intranet-bonus-score-recipients-container {
        display: flex;
        flex-direction: row;
        align-items: center;
        gap: 8px;
        flex: 1;
    }

    .intranet-bonus-score-recipients-list {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
        min-height: 32px;
        padding: 4px;
        background-color: rgba(255, 255, 255, 0.1);
        border-radius: 8px;
        flex: 1;
    }

    .intranet-bonus-score-recipient-tag {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 4px 8px;
        background-color: rgba(255, 255, 255, 0.2);
        border-radius: 16px;
        font-size: 12px;
        color: #fff;
    }

    .intranet-bonus-score-recipient-tag-avatar {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        background-color: rgba(255, 255, 255, 0.3);
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 10px;
        font-weight: 600;
        color: #fff;
        flex-shrink: 0;
        overflow: hidden;
    }

    .intranet-bonus-score-recipient-tag-avatar img {
        width: 100%;
        height: 100%;
        object-fit: cover;
    }

    .intranet-bonus-score-recipient-tag-remove {
        cursor: pointer;
        width: 16px;
        height: 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        background-color: rgba(255, 255, 255, 0.2);
        font-size: 12px;
        line-height: 1;
        transition: background-color 0.2s;
    }

    .intranet-bonus-score-recipient-tag-remove:hover {
        background-color: rgba(255, 255, 255, 0.4);
    }

    .intranet-bonus-score-add-button {
        border: 1px dashed rgba(255, 255, 255, 0.5);
        border-radius: 8px;
        padding: 6px 12px;
        font-size: 12px;
        color: rgba(255, 255, 255, 0.9);
        background-color: transparent;
        cursor: pointer;
        transition: all 0.2s;
        text-align: left;
    }

    .intranet-bonus-score-add-button:hover {
        border-color: rgba(255, 255, 255, 0.8);
        background-color: rgba(255, 255, 255, 0.1);
        color: #fff;
    }

    /* Стили для кнопки компонента bitrix:main.user.selector */
    #bonus_score_recipients_selector .main-user-selector-button,
    #bonus_score_recipients_selector .main-user-selector-button-link {
        border: 1px dashed rgba(255, 255, 255, 0.5) !important;
        border-radius: 8px !important;
        padding: 6px 12px !important;
        font-size: 12px !important;
        color: rgba(255, 255, 255, 0.9) !important;
        background-color: transparent !important;
        cursor: pointer !important;
        transition: all 0.2s !important;
        text-align: left !important;
        display: inline-block !important;
        text-decoration: none !important;
    }

    #bonus_score_recipients_selector .main-user-selector-button:hover,
    #bonus_score_recipients_selector .main-user-selector-button-link:hover {
        border-color: rgba(255, 255, 255, 0.8) !important;
        background-color: rgba(255, 255, 255, 0.1) !important;
        color: #fff !important;
    }

    .intranet-bonus-score-actions {
        display: flex;
        align-items: center;
        gap: 10px;
        margin-top: 4px;
        flex-wrap: wrap;
    }

    .intranet-bonus-score-button {
        border: none;
        border-radius: 999px;
        padding: 7px 18px;
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #fff;
        background-color: #2C365E;
        box-shadow: 0 6px 14px rgba(0, 0, 0, 0.2);
        cursor: pointer;
        transition: transform 0.08s ease-out, box-shadow 0.08s ease-out, opacity 0.08s ease-out;
    }

    .intranet-bonus-score-button:hover {
        transform: translateY(-1px);
        box-shadow: 0 8px 18px rgba(0, 0, 0, 0.35);
        opacity: 0.95;
        background-color: #263052;
    }

    .intranet-bonus-score-button:active {
        transform: translateY(0);
        box-shadow: 0 4px 10px rgba(0, 0, 0, 0.3);
        background-color: #222b48;
    }

    .intranet-bonus-score-result {
        font-size: 12px;
        color: rgba(255, 255, 255, 0.9);
        min-height: 16px;
    }

    .intranet-bonus-score-result--error {
        color: var(--bonus-danger);
        font-weight: 600;
    }

    .intranet-bonus-score-result--success {
        color: var(--bonus-success);
        font-weight: 600;
    }

    .intranet-bonus-links-row {
        margin-top: 8px;
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
    }

    .intranet-bonus-link {
        color: var(--bonus-brand-base);
        text-decoration: none;
        font-weight: 600;
        letter-spacing: 0.02em;
        font-size: 13px;
    }

    .intranet-bonus-link:hover {
        color: var(--bonus-brand-dark);
    }

    @media (max-width: 768px) {
        .intranet-bonus-widget--scoring {
            flex-direction: column;
        }

        .intranet-bonus-widget-right {
            width: 100%;
        }
    }

    /* Стили для модального окна успеха в корпоративном цвете */
    .bonus-score-success-popup .popup-window {
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 8px 32px rgba(44, 54, 94, 0.3);
    }

    .bonus-score-success-popup .popup-window-titlebar {
        display: none;
    }

    .bonus-score-success-popup .popup-window-content {
        background: #fff;
        padding: 40px 24px;
        text-align: center;
    }

    .bonus-score-success-popup .popup-window-content-text {
        color: #2C365E !important;
        font-size: 24px !important;
        font-weight: 600 !important;
        line-height: 1.4 !important;
    }

    .bonus-score-success-popup .popup-window-content {
        background: #fff !important;
        padding: 40px 24px !important;
        text-align: center !important;
    }

    .bonus-score-success-popup .popup-window-content * {
        color: #2C365E !important;
        font-size: 24px !important;
        font-weight: 600 !important;
    }

    .bonus-score-success-popup .popup-window-buttons {
        background: #f5f5f5;
        padding: 16px 24px;
        border-top: 1px solid #e0e0e0;
        display: flex;
        justify-content: center;
        gap: 10px;
    }

    .bonus-score-success-popup .popup-window-button {
        background-color: #2C365E !important;
        color: #fff !important;
        border: none !important;
        border-radius: 8px !important;
        padding: 10px 32px !important;
        font-size: 14px !important;
        font-weight: 600 !important;
        cursor: pointer !important;
        transition: background-color 0.2s ease !important;
        min-width: 100px !important;
    }

    .bonus-score-success-popup .popup-window-button:hover {
        background-color: #263052 !important;
    }

    .bonus-score-success-popup .popup-window-button:active {
        background-color: #222b48 !important;
    }
</style>

<script>
    (function () {
        var amountInput = document.getElementById('bonus-score-amount');
        var reasonInput = document.getElementById('bonus-score-reason');
        var submitButton = document.getElementById('bonus-score-submit');
        var resultEl = document.getElementById('bonus-score-result');

        if (!amountInput || !reasonInput || !submitButton || !resultEl) {
            console.warn('BonusScoringPopup: required DOM elements not found');
            return;
        }


        function setResult(message, isError) {
            resultEl.textContent = message;
            resultEl.classList.remove('intranet-bonus-score-result--error', 'intranet-bonus-score-result--success');
            if (isError === true) {
                resultEl.classList.add('intranet-bonus-score-result--error');
            } else if (isError === false) {
                resultEl.classList.add('intranet-bonus-score-result--success');
            }
        }

        submitButton.addEventListener('click', function () {
            var rawAmount = amountInput.value.trim();
            var reasonIdRaw = reasonInput.value.trim();

            if (!rawAmount) {
                setResult('Укажите количество баллов для начисления.', true);
                return;
            }

            var amount = parseFloat(rawAmount.replace(',', '.'));
            if (!Number.isFinite(amount) || amount <= 0) {
                setResult('Количество баллов должно быть положительным числом.', true);
                return;
            }

            if (!reasonIdRaw) {
                setResult('Выберите причину начисления.', true);
                return;
            }

            // Определяем получателя
            var recipientId = parseInt(submitButton.getAttribute('data-recipient-id') || '0', 10);

            if (!recipientId || recipientId <= 0) {
                setResult('Не удалось определить получателя начисления.', true);
                return;
            }

            setResult('Отправка данных на сервер…', false);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', '/local/widgets/bonus/score_widget_handler.php', true);
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');

            xhr.onreadystatechange = function () {
                if (xhr.readyState !== 4) {
                    return;
                }

                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp && resp.success) {
                        // Показываем модальное окно с сообщением
                        if (typeof BX !== 'undefined' && typeof BX.PopupWindowManager !== 'undefined') {
                            var successPopupId = 'bonus-score-success-popup';
                            var existingSuccessPopup = BX.PopupWindowManager.getPopupById(successPopupId);
                            
                            if (existingSuccessPopup) {
                                existingSuccessPopup.destroy();
                            }
                            
                            var successContent = BX.create('div', {
                                props: { className: 'bonus-score-success-popup-content-text' },
                                html: 'Заявка принята'
                            });
                            
                            var successPopup = BX.PopupWindowManager.create(successPopupId, null, {
                                titleBar: '',
                                autoHide: false,
                                closeByEsc: false,
                                closeIcon: false,
                                overlay: { backgroundColor: '#000', opacity: 50 },
                                lightShadow: true,
                                draggable: false,
                                resizable: false,
                                width: 400,
                                min_height: 180,
                                content: successContent,
                                className: 'bonus-score-success-popup',
                                buttons: [
                                    new BX.PopupWindowButton({
                                        text: 'ОК',
                                        className: 'bonus-score-success-ok-button',
                                        events: {
                                            click: function() {
                                                successPopup.close();
                                                
                                                // Закрываем попап начисления
                                                var popupId = 'bonus-score-popup-' + recipientId;
                                                var popup = BX.PopupWindowManager.getPopupById(popupId);
                                                if (popup) {
                                                    popup.close();
                                                }
                                                
                                                // Обновляем страницу профиля
                                                window.location.reload();
                                            }
                                        }
                                    })
                                ]
                            });
                            
                            successPopup.show();
                        } else {
                            // Фолбек, если BX.PopupWindowManager недоступен
                            if (confirm('Заявка принята. Обновить страницу?')) {
                                // Обновляем страницу профиля
                                window.location.reload();
                            }
                        }
                    } else {
                        setResult((resp && resp.message) || 'Ошибка при запуске бизнес-процесса.', true);
                    }
                } catch (e) {
                    setResult('Ошибка разбора ответа сервера.', true);
                }
            };

            var params = [
                'action=' + encodeURIComponent('bonus_score'),
                'recipient_id=' + encodeURIComponent(String(recipientId)),
                'reason_enum_id=' + encodeURIComponent(String(parseInt(reasonIdRaw, 10))),
                'amount=' + encodeURIComponent(String(amount))
            ].join('&');

            xhr.send(params);
        });
    })();
</script>


