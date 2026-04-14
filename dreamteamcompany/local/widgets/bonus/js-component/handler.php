<?php
/**
 * JS-обработчик для прямого встраивания виджета без iframe
 * Подключается через placement.bind и возвращает чистый JavaScript.
 */

// Включаем обработку ошибок для отладки (в продакшене можно отключить)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Устанавливаем правильный Content-Type сразу, чтобы даже при ошибке был правильный тип
header('Content-Type: application/javascript; charset=utf-8');

try {
    // Подключаем Bitrix, если еще не подключен
    if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) {
        $bitrixPath = $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/include/prolog_before.php';
        if (file_exists($bitrixPath)) {
            require_once $bitrixPath;
        } else {
            throw new Exception('Bitrix prolog file not found: ' . $bitrixPath);
        }
    }
} catch (Exception $e) {
    // Если ошибка подключения Bitrix, возвращаем пустой скрипт с ошибкой
    echo 'console.error("BonusWidget: Failed to load Bitrix - ' . addslashes($e->getMessage()) . '");';
    exit;
}

try {
    // Если пришел OAuth code (перенаправление от Bitrix), перенаправляем на get_access_token.php
    if (!empty($_GET['code']) && empty($_POST['PLACEMENT_OPTIONS'])) {
        $code = $_GET['code'];
        $state = $_GET['state'] ?? '';
        $domain = $_GET['domain'] ?? '';
        $memberId = $_GET['member_id'] ?? '';
        $scope = $_GET['scope'] ?? '';
        
        // Перенаправляем на get_access_token.php с параметрами
        $redirectUrl = '/get_access_token.php?' . http_build_query([
            'code' => $code,
            'state' => $state,
            'domain' => $domain,
            'member_id' => $memberId,
            'scope' => $scope,
        ]);
        
        header('Location: ' . $redirectUrl);
        exit;
    }
    
    // Получаем параметры placement (Bitrix отправляет через POST, но может быть и GET)
    $placementOptions = [];
    
    // Сначала пробуем POST (как в документации Bitrix)
    if (!empty($_POST['PLACEMENT_OPTIONS'])) {
        $decoded = json_decode($_POST['PLACEMENT_OPTIONS'], true);
        if (is_array($decoded)) {
            $placementOptions = $decoded;
        }
    }
    // Потом пробуем GET или REQUEST
    elseif (!empty($_REQUEST['PLACEMENT_OPTIONS'])) {
        $decoded = json_decode($_REQUEST['PLACEMENT_OPTIONS'], true);
        if (is_array($decoded)) {
            $placementOptions = $decoded;
        }
    }
    
    // Получаем USER_ID из разных источников (приоритет: PLACEMENT_OPTIONS > REQUEST > текущий пользователь > URL)
    $userId = 0;
    if (!empty($placementOptions['USER_ID'])) {
        $userId = (int)$placementOptions['USER_ID'];
    } elseif (!empty($_POST['USER_ID'])) {
        $userId = (int)$_POST['USER_ID'];
    } elseif (!empty($_REQUEST['USER_ID'])) {
        $userId = (int)$_REQUEST['USER_ID'];
    } elseif (isset($GLOBALS['USER']) && is_object($GLOBALS['USER']) && method_exists($GLOBALS['USER'], 'IsAuthorized') && $GLOBALS['USER']->IsAuthorized()) {
        $userId = (int)$GLOBALS['USER']->GetID();
    }
    
    // Если USER_ID все еще не определен, пробуем получить из URL
    if ($userId === 0) {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '';
        if (preg_match('/user\/(\d+)/', $requestUri, $matches)) {
            $userId = (int)$matches[1];
        }
    }
    
    // Проверяем, что userId определен
    if ($userId <= 0) {
        // Если userId не определен, возвращаем скрипт с ошибкой и отладочной информацией
        $debugInfo = [
            'placement_options' => $placementOptions,
            'post' => array_intersect_key($_POST, ['PLACEMENT_OPTIONS' => '', 'USER_ID' => '']),
            'get' => array_intersect_key($_GET, ['PLACEMENT_OPTIONS' => '', 'USER_ID' => '']),
            'uri' => $_SERVER['REQUEST_URI'] ?? 'unknown',
        ];
        echo 'console.error("BonusWidget: USER_ID not defined", ' . json_encode($debugInfo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ');';
        exit;
    }

    $ajaxUrl = '/local/widgets/bonus/profile_widget_handler.php';
    $ajaxUrlWithParams = $ajaxUrl . '?USER_ID=' . $userId;
    
    $config = [
        'userId' => $userId,
        'ajaxUrl' => $ajaxUrlWithParams,
    ];
    
    // Логируем полученные параметры для отладки
    $debugParams = [
        'placement_options' => $placementOptions,
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown',
        'has_post' => !empty($_POST),
        'has_get' => !empty($_GET),
    ];
    
    echo '(function(){';
    echo 'console.log("BonusWidget: Script loaded", {userId: ' . $userId . ', debug: ' . json_encode($debugParams, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '});';
    echo 'if(window.BonusWidgetInline){console.log("BonusWidget: Already initialized");return;}';
    echo 'var BonusWidgetInline=function(options){this.userId=options.userId;this.ajaxUrl=options.ajaxUrl;this.attempts=0;this.maxAttempts=20;console.log("BonusWidget: Created instance",options);this.init();};';
    echo 'BonusWidgetInline.prototype.init=function(){console.log("BonusWidget: init() called");this.tryAttach();};';
    echo 'BonusWidgetInline.prototype.tryAttach=function(){';
    echo 'this.attempts++;';
    echo 'console.log("BonusWidget: tryAttach attempt " + this.attempts + " of " + this.maxAttempts);';
    echo 'if(this.attempts>this.maxAttempts){console.warn("BonusWidget: Max attempts reached, giving up");return;}';
    echo 'if(document.getElementById("intranet-user-profile-bonus-result")){console.log("BonusWidget: Already attached, skipping");return;}';
    echo 'var target=null;';
    echo 'var insertAfter=null;';
    echo 'var stressWidget=document.getElementById("intranet-user-profile-stresslevel-result");';
    echo 'if(stressWidget){';
    echo 'target=stressWidget;';
    echo 'insertAfter=stressWidget;';
    echo 'console.log("BonusWidget: Found stress widget",stressWidget,stressWidget.parentNode);';
    echo '}else{';
    echo 'var selectors=[' .
        '"[id*=\"intranet-user-profile-stresslevel\"]",' .
        '"[id*=\"intranet-user-profile\"]",' .
        '".intranet-user-profile-column-block",' .
        '".user-profile-card",' .
        '".profile-user-main-info",' .
        '".profile-info",' .
        '".intranet-user-profile-section"' .
    '];';
    echo 'for(var i=0;i<selectors.length;i++){';
    echo 'var el=document.querySelector(selectors[i]);';
    echo 'if(el){';
    echo 'var isVisible=el.offsetParent!==null||el.offsetWidth>0||el.offsetHeight>0;';
    echo 'if(isVisible){target=el;insertAfter=el;console.log("BonusWidget: Found target",selectors[i],el,el.parentNode);break;}';
    echo '}';
    echo '}';
    echo '}';
    echo 'if(!target){console.log("BonusWidget: Target not found, will retry in 300ms...");setTimeout(this.tryAttach.bind(this),300);return;}';
    echo 'var container=document.createElement("div");';
    echo 'container.id="intranet-user-profile-bonus-result";';
    echo 'container.className="intranet-user-profile-column-block intranet-user-profile-column-block-inline";';
    echo 'container.style.display="block";';
    echo 'container.style.marginTop="20px";';
    echo 'container.style.marginBottom="20px";';
    echo 'container.innerHTML="<div style=\"padding:24px;border:1px solid #eef2f4;border-radius:16px;text-align:center;color:#6a737f;font-size:14px;\">Загружаем бонусы...</div>";';
    echo 'var inserted=false;';
    echo 'if(insertAfter&&insertAfter.parentNode){';
    echo 'try{';
    echo 'if(insertAfter.nextSibling){insertAfter.parentNode.insertBefore(container,insertAfter.nextSibling);}';
    echo 'else{insertAfter.parentNode.appendChild(container);}';
    echo 'inserted=true;';
    echo 'console.log("BonusWidget: Inserted after element",insertAfter,container.parentNode);';
    echo '}catch(e){console.error("BonusWidget: Error inserting after",e);}';
    echo '}';
    echo 'if(!inserted&&target.parentNode){';
    echo 'try{';
    echo 'target.parentNode.appendChild(container);';
    echo 'inserted=true;';
    echo 'console.log("BonusWidget: Appended to parent",container.parentNode);';
    echo '}catch(e){console.error("BonusWidget: Error appending to parent",e);}';
    echo '}';
    echo 'if(!inserted){';
    echo 'try{';
    echo 'target.appendChild(container);';
    echo 'inserted=true;';
    echo 'console.log("BonusWidget: Appended to target",container.parentNode);';
    echo '}catch(e){console.error("BonusWidget: Error appending to target",e);}';
    echo '}';
    echo 'if(!inserted){console.error("BonusWidget: Failed to insert container!");return;}';
    echo 'this.container=container;';
    echo 'var checkContainer=document.getElementById("intranet-user-profile-bonus-result");';
    echo 'if(checkContainer&&checkContainer.parentNode){';
    echo 'console.log("BonusWidget: Container verified in DOM",checkContainer.parentNode,checkContainer.offsetHeight,checkContainer.offsetWidth);';
    echo '}else{';
    echo 'console.error("BonusWidget: Container not found in DOM after insertion!");';
    echo '}';
    echo 'this.loadData();';
    echo '};';
    echo 'BonusWidgetInline.prototype.loadData=function(){';
    echo 'var self=this;';
    echo 'console.log("BonusWidget: loadData() called, url=" + this.ajaxUrl);';
    echo 'if(typeof BX!="undefined"&&BX.ajax){';
    echo 'BX.ajax({';
    echo 'url:this.ajaxUrl,';
    echo 'method:"GET",';
    echo 'dataType:"html",';
    echo 'onsuccess:function(result){';
    echo 'console.log("BonusWidget: Data loaded successfully");';
    echo 'if(self.container){self.container.innerHTML=result;}';
    echo '},';
    echo 'onfailure:function(error){';
    echo 'console.error("BonusWidget: Failed to load",error);';
    echo 'if(self.container){';
    echo 'self.container.innerHTML="<div style=\\"color:#d33;padding:16px;border:1px solid #f5c2c7;border-radius:12px;\\">Не удалось загрузить бонусы</div>";';
    echo '}';
    echo '}';
    echo '});';
    echo '}else{';
    echo 'var xhr=new XMLHttpRequest();';
    echo 'xhr.open("GET",this.ajaxUrl,true);';
    echo 'xhr.onload=function(){';
    echo 'if(xhr.status===200&&self.container){self.container.innerHTML=xhr.responseText;}';
    echo '};';
    echo 'xhr.onerror=function(){';
    echo 'if(self.container){';
    echo 'self.container.innerHTML="<div style=\\"color:#d33;padding:16px;border:1px solid #f5c2c7;border-radius:12px;\\">Ошибка загрузки</div>";';
    echo '}';
    echo '};';
    echo 'xhr.send();';
    echo '}';
    echo '};';
    echo 'if(typeof BX!="undefined"&&BX.ready){';
    echo 'console.log("BonusWidget: Using BX.ready");';
    echo 'BX.ready(function(){console.log("BonusWidget: BX.ready fired");window.BonusWidgetInline=new BonusWidgetInline(' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ');});';
    echo '}else{';
    echo 'console.log("BonusWidget: BX not available, using DOMContentLoaded or immediate");';
    echo 'if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",function(){console.log("BonusWidget: DOMContentLoaded fired");window.BonusWidgetInline=new BonusWidgetInline(' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ');});}';
    echo 'else{console.log("BonusWidget: Document ready, initializing immediately");window.BonusWidgetInline=new BonusWidgetInline(' . json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . ');}';
    echo '}';
    echo '})();';
    
} catch (Exception $e) {
    // Если произошла ошибка, возвращаем скрипт с сообщением об ошибке
    echo 'console.error("BonusWidget: Error - ' . addslashes($e->getMessage()) . '");';
} catch (Error $e) {
    // Обработка фатальных ошибок PHP 7+
    echo 'console.error("BonusWidget: Fatal error - ' . addslashes($e->getMessage()) . '");';
}
