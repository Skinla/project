<?php
/**
 * Альтернативный обработчик для встраивания виджета прямо на страницу профиля
 * Используется через событие или кастомное поле
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';

/**
 * Примитивный логгер для отладки продакшен-конфига.
 */
$bonusWidgetLogPath = $_SERVER['DOCUMENT_ROOT'] . '/local/widgets/bonus/bonus_widget.log';
$bonusWidgetLog = static function (string $message, array $context = []) use ($bonusWidgetLogPath): void {
    $logLine = sprintf(
        "[%s] %s %s%s",
        date('Y-m-d H:i:s'),
        $message,
        $context ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '',
        PHP_EOL
    );

    @file_put_contents($bonusWidgetLogPath, $logLine, FILE_APPEND);
};

use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;

// Получаем ID пользователя из URL
$request = Application::getInstance()->getContext()->getRequest();
$userId = (int)($request->get('USER_ID') ?? 0);

if ($userId === 0) {
    // Пытаемся получить из URL
    $requestUri = $request->getRequestUri();
    if (preg_match('/user\/(\d+)/', $requestUri, $matches)) {
        $userId = (int)$matches[1];
    }
}

if ($userId === 0) {
    echo '<!-- Виджет бонусов: не удалось определить пользователя -->';
    return;
}

// Загружаем конфигурацию
$configPath = __DIR__ . '/config.php';
if (file_exists($configPath)) {
    $config = require $configPath;
    $bonusWidgetLog('Config loaded', [
        'path' => $configPath,
        'entity_type_id' => $config['entity_type_id'] ?? null,
        'bonus_field' => $config['bonus_field'] ?? null,
        'user_field' => $config['user_field'] ?? null,
    ]);
} else {
    $config = [
        'entity_type_id' => 'DYNAMIC_XXX',
        'bonus_field' => 'UF_BONUS_AMOUNT',
        'user_field' => 'ASSIGNED_BY_ID',
        'currency_name' => 'Дримы - валюта Worldvision',
    ];
    $bonusWidgetLog('Config file not found, fallback used', ['path' => $configPath]);
}

$entityTypeIdRaw = $config['entity_type_id'];

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
$bonusWidgetLog('Entity type normalized', [
    'raw' => $entityTypeIdRaw,
    'normalized' => $entityTypeId,
    'type' => gettype($entityTypeId),
]);
$bonusField = $config['bonus_field'];
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

// Получаем сумму бонусов
// null = нет данных / нет бонусов
$bonusAmount = null;

$hasValidEntityType = is_int($entityTypeId) && $entityTypeId > 0;

if ($hasValidEntityType && Loader::includeModule('crm')) {
    try {
        $container = Container::getInstance();
        $factory = $container->getFactory($entityTypeId);
        
        if ($factory) {
            $userField = $config['user_field'] ?? 'ASSIGNED_BY_ID';
            
            $items = $factory->getItems([
                'filter' => [
                    $userField => $userId
                ],
                'select' => [$bonusField]
            ]);
            
            $totalBonus = 0.0;
            $itemsCount = 0;
            
            foreach ($items as $item) {
                $bonusValue = $item->get($bonusField);
                if ($bonusValue !== null) {
                    $totalBonus += (float)$bonusValue;
                }
                $itemsCount++;
            }
            
            if ($itemsCount > 0 && $totalBonus > 0) {
                $bonusAmount = $totalBonus;
            } else {
                $bonusAmount = null;
            }

            $bonusWidgetLog('Bonus calculation completed', [
                'user_id' => $userId,
                'entity_type_id' => $entityTypeId,
                'entity_type_id_raw' => $entityTypeIdRaw,
                'bonus_field' => $bonusField,
                'user_field' => $userField,
                'items_count' => $itemsCount,
                'total_bonus' => $totalBonus,
                'result_bonus' => $bonusAmount,
            ]);
        } else {
            $bonusWidgetLog('Factory not found', [
                'entity_type_id' => $entityTypeId,
                'entity_type_id_raw' => $entityTypeIdRaw,
            ]);
        }
    } catch (\Throwable $e) {
        $bonusWidgetLog('Bonus calculation error, using null', [
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
        ]);
        $bonusAmount = null;
    }
} else {
    $bonusWidgetLog('CRM module not loaded or entity type placeholder', [
        'entity_type_id' => $entityTypeId,
        'entity_type_id_raw' => $entityTypeIdRaw,
        'crm_loaded' => Loader::includeModule('crm') ? 'yes' : 'no',
        'has_valid_entity_type' => $hasValidEntityType ? 'yes' : 'no',
    ]);
}

// Выводим виджет
if ($bonusAmount === null) {
    $formattedAmount = 'нет';
} else {
    $formattedAmount = number_format($bonusAmount, 0, ',', ' ');
}
$currencyName = htmlspecialchars($config['currency_name'] ?? 'Дримы - валюта Команды Мечты');
?>
<div class="intranet-user-profile-column-block intranet-user-profile-column-block-inline" id="intranet-user-profile-bonus-result" style="display: block;">
    
    <!-- Иконка: видимость блока для других пользователей (закрыто) -->
    <div id="intranet-user-profile-bonus-result-perms-close" class="intranet-user-profile-bonus-invisible" data-hint="Информация о ваших бонусах доступна для просмотра другим пользователям." data-hint-no-icon="" style="display: block;" data-hint-init="y"></div>
    
    <!-- Иконка: видимость блока для других пользователей (открыто) -->
    <div id="intranet-user-profile-bonus-result-perms-open" class="intranet-user-profile-bonus-visible" data-hint="Информация о ваших бонусах недоступна для просмотра другим пользователям." data-hint-no-icon="" style="display: none;" data-hint-init="y"></div>
    
    <!-- Общий контейнер контента виджета -->
    <div class="intranet-bonus-widget-content">
        <!-- Верхняя карточка с суммой и подписью валюты -->
        <div id="intranet-user-profile-bonus-widget" class="intranet-user-profile-bonus-widget">
            <div class="intranet-bonus-widget">
                <!-- Сумма бонусов -->
                <div class="intranet-bonus-widget-value"><?= $formattedAmount ?></div>
                <!-- Подпись валюты справа -->
                <div class="intranet-bonus-widget-meta">
                    <div class="intranet-bonus-widget-label">Дрим</div>
                </div>
            </div>
        </div>
        
        <!-- Нижний информационный блок -->
        <div class="intranet-user-profile-bonus-info">
            <!-- Название валюты -->
            <div class="intranet-user-profile-bonus-info-block">
                <div id="intranet-user-profile-bonus-status" class="intranet-user-profile-bonus-status" style="display: inline-block;">
                    <?= $currencyName ?>
                </div>
                <div id="intranet-user-profile-bonus-status-info" class="intranet-user-profile-bonus-status-info" data-hint="" data-hint-no-icon="" data-hint-init="y"></div>
            </div>
            <!-- Навигационные ссылки -->
            <div id="intranet-user-profile-bonus-comment" class="intranet-user-profile-bonus-status-text">
                <a href="#" class="intranet-bonus-link" onclick="openHowToSpend('<?= $howToSpendUrlAttr ?>'); return false;">Как потратить?</a>
                <a href="#" class="intranet-bonus-link" onclick="openHistory(event, '<?= $historyUrlAttr ?>'); return false;">История начислений</a>
                <a href="#" class="intranet-bonus-link" onclick="openBonusScorePopup(<?= (int)$userId ?>); return false;">Начислить</a>
            </div>
        </div>
    </div>
    
</div>

<style>
:root {
    /* Цветовая палитра виджета */
    --bonus-brand-lightest: #cbd3fd;
    --bonus-brand-lighter: #b5c0e9;
    --bonus-brand-light: #a5afd5;
    --bonus-brand-base: #8992b4;
    --bonus-brand-dark: #2c365e;
    --bonus-gray: #6f7380;
}

/* Базовая типографика для блоков виджета */
.intranet-user-profile-bonus-widget,
.intranet-user-profile-bonus-info,
.intranet-user-profile-bonus-status,
.intranet-user-profile-bonus-status-text {
    font-family: 'Gilroy', 'Inter', 'Segoe UI', -apple-system, BlinkMacSystemFont, sans-serif;
}

#intranet-user-profile-bonus-result .intranet-bonus-widget-content {
    max-width: 320px;
    margin: 0 auto;
}

/* Обертка верхней карточки */
#intranet-user-profile-bonus-result .intranet-user-profile-bonus-widget {
    margin: 0 0 12px;
    width: 100%;
}

/* Верхняя карточка: сумма + подпись */
#intranet-user-profile-bonus-result .intranet-bonus-widget {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 18px;
    padding: 10px 16px;
    border-radius: 20px;
    background: linear-gradient(130deg, var(--bonus-brand-lightest), var(--bonus-brand-dark));
    box-shadow: 0 10px 24px rgba(44, 54, 94, 0.25);
    color: #fff;
    min-height: 56px;
}

#intranet-user-profile-bonus-result .intranet-bonus-widget-value {
    font-size: 34px;
    line-height: 1;
    font-weight: 700;
    white-space: nowrap;
    text-shadow: 0 2px 8px rgba(44, 54, 94, 0.35);
    margin: 0;
}

/* Блок подписи валюты в верхней карточке */
#intranet-user-profile-bonus-result .intranet-bonus-widget-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    justify-content: center;
    margin-left: auto;
    margin-right: 8px;
    text-align: right;
    gap: 4px;
    text-transform: uppercase;
}

/* Текст подписи валюты в верхней карточке */
#intranet-user-profile-bonus-result .intranet-bonus-widget-label {
    font-size: 13px;
    letter-spacing: 0.08em;
    color: rgba(255, 255, 255, 0.9);
    line-height: 1.1;
    margin: 0;
}

/* Дополнительная подпись в верхней карточке (если используется) */
.intranet-bonus-widget-note {
    font-size: 11px;
    text-transform: none;
    color: rgba(255, 255, 255, 0.7);
}

/* Нижний информационный блок */
.intranet-user-profile-bonus-info {
    width: 100%;
    text-align: center;
}

/* Контейнер названия валюты */
.intranet-user-profile-bonus-info-block {
    margin-bottom: 6px;
}

/* Текст названия валюты */
.intranet-user-profile-bonus-status {
    font-size: 15px;
    color: var(--bonus-brand-dark);
    font-weight: 600;
}

/* Базовые стили строки ссылок */
.intranet-user-profile-bonus-status-text {
    font-size: 13px;
    color: var(--bonus-gray);
    line-height: 1.4;
}

/* Раскладка ссылки в несколько строк при необходимости */
.intranet-user-profile-bonus-status-text {
    display: flex;
    gap: 16px;
    justify-content: center;
    flex-wrap: wrap;
}

/* Ссылка действия в нижнем блоке */
.intranet-bonus-link {
    color: var(--bonus-brand-base);
    text-decoration: none;
    font-weight: 600;
    letter-spacing: 0.02em;
}

.intranet-bonus-link:hover {
    color: var(--bonus-brand-dark);
}

/* Иконки переключения видимости блока */
.intranet-user-profile-bonus-invisible,
.intranet-user-profile-bonus-visible {
    position: absolute;
    top: 0;
    right: 0;
    width: 20px;
    height: 20px;
    cursor: pointer;
    opacity: 0.6;
}

.intranet-user-profile-bonus-invisible:hover,
.intranet-user-profile-bonus-visible:hover {
    opacity: 1;
}
</style>

<script>
function openHowToSpend(url) {
    if (typeof BX24 !== 'undefined') {
        BX24.openPath(url);
    } else if (typeof BX !== 'undefined' && BX.SidePanel) {
        BX.SidePanel.Instance.open(url);
    } else {
        window.open(url, '_blank');
    }
}

function openHistory(event, url) {
    event.preventDefault();
    if (typeof BX24 !== 'undefined') {
        BX24.openPath(url);
    } else if (typeof BX !== 'undefined' && BX.SidePanel) {
        BX.SidePanel.Instance.open(url);
    } else {
        window.open(url, '_blank');
    }
}

function openBonusScorePopup(userId) {
    if (!userId) {
        console.error('BonusWidget: USER_ID is empty for scoring popup');
        return;
    }

    if (typeof BX === 'undefined' || typeof BX.PopupWindowManager === 'undefined') {
        // Фолбек: открываем отдельным окном, если BX недоступен
        var fallbackUrl = '/local/widgets/bonus/score_widget_handler.php?USER_ID=' + encodeURIComponent(userId);
        window.open(fallbackUrl, '_blank');
        return;
    }

    var popupId = 'bonus-score-popup-' + userId;
    var existing = BX.PopupWindowManager.getPopupById(popupId);

    if (existing) {
        existing.show();
        return;
    }

    var contentNode = BX.create('div', {
        attrs: { className: 'bonus-score-popup-content' },
        html: '<div style="padding: 24px; text-align: center; color: #6f7380;">Загружаем форму начисления...</div>'
    });

    var popup = BX.PopupWindowManager.create(popupId, null, {
        titleBar: 'Начисление баллов',
        autoHide: true,
        closeByEsc: true,
        closeIcon: { right: '12px', top: '10px' },
        overlay: { backgroundColor: '#000', opacity: 30 },
        lightShadow: true,
        draggable: true,
        resizable: false,
        width: 720,
        min_height: 240,
        content: contentNode,
        buttons: []
    });

    popup.show();
    // Немного поднимаем попап вверх относительно центра
    if (typeof popup.setOffset === 'function') {
        popup.setOffset({
            offsetTop: -80,
            offsetLeft: 0
        });
    }

    var url = '/local/widgets/bonus/score_widget_handler.php?USER_ID=' + encodeURIComponent(userId);

    if (BX.ajax) {
        BX.ajax({
            url: url,
            method: 'GET',
            dataType: 'html',
            onsuccess: function (html) {
                popup.setContent(
                    BX.create('div', {
                        attrs: { className: 'bonus-score-popup-content-inner' },
                        html: html
                    })
                );
            },
            onfailure: function () {
                popup.setContent(
                    BX.create('div', {
                        style: {
                            padding: '24px',
                            color: '#d33'
                        },
                        html: 'Не удалось загрузить форму начисления. Попробуйте обновить страницу или обратитесь к администратору.'
                    })
                );
            }
        });
    } else {
        var xhr = new XMLHttpRequest();
        xhr.open('GET', url, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }

            if (xhr.status === 200) {
                popup.setContent(
                    BX.create('div', {
                        attrs: { className: 'bonus-score-popup-content-inner' },
                        html: xhr.responseText
                    })
                );
            } else {
                popup.setContent(
                    BX.create('div', {
                        style: {
                            padding: '24px',
                            color: '#d33'
                        },
                        html: 'Ошибка загрузки формы начисления (' + xhr.status + ').'
                    })
                );
            }
        };
        xhr.send();
    }
}
</script>

