<?php
define("NO_KEEP_STATISTIC", true);
define("NOT_CHECK_PERMISSIONS", true);

require_once($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/include/prolog_before.php");

use Bitrix\Main\Context,
    Bitrix\Main\Loader,
    Bitrix\Main\Application,
    Bitrix\Main\Mail\Event,
    Bitrix\Main\Web\HttpClient;

$request = Context::getCurrent()->getRequest();

if ($request->isPost()&&$request->isAjaxRequest()&&check_bitrix_sessid()) {
  $answer = array();
  $answer['error'] = false;

  $name = $request->getPost("name");
  $mail = $request->getPost("mail");
  $message = $request->getPost("message");
  $employee_id = $request->getPost("person");
  $employee_mail = $request->getPost("mailto");
  $gCaptcha = $request->getPost("g-recaptcha-response");
  $formLang = $request->getPost("lang");

  if($formLang == 'en') {
      $siteID = 's2';
  } else {
      $siteID = 's1';
  }

  if (empty($gCaptcha)){
    $answer['error'] = true;
    if($formLang == 'en') {
        $answer['errorText'] = 'You have not passed the verification reCaptcha';
    } else {
        $answer['errorText'] = 'Вы не прошли проверку reCaptcha';
    }
  } else if (empty($mail) || empty($name)) {
    $answer['error'] = true;
    if($formLang == 'en') {
        $answer['errorText'] = "Please enter required fields";
    } else {
        $answer['errorText'] = "Введите обязательные поля";
    }
  } else {
    $httpClient = new HttpClient();

    $response = $httpClient->post(
        'https://www.google.com/recaptcha/api/siteverify',
        ["secret" => "6LcdpgIrAAAAAOxLOOk9LcY4Fnztlyaij06BdVow", "response" => $_REQUEST['g-recaptcha-response']]
    );

    if ($response) {
      $reCaptcha = json_decode($response);

      if (!empty($reCaptcha) && $reCaptcha->success) {
        Loader::includeModule("iblock");

        $event = new CEvent;
        $el = new CIBlockElement;

        $prop = array();

        if($formLang == 'en') {
            $prop[199] = $name;
            $prop[200] = $mail;
            $prop[201] = $message;
            $prop[202] = $employee_id;
            $prop[203] = $employee_mail;
        } else {
            $prop[36] = $name;
            $prop[37] = $mail;
            $prop[38] = $message;
            $prop[39] = $employee_id;
            $prop[40] = $employee_mail;
        }

        $arElement = Array(
            "IBLOCK_ID" => IB_FORM_MESSAGE[$formLang],
            "PROPERTY_VALUES" => $prop,
            "NAME" => "Сообщение от " . ConvertTimeStamp(time(), "FULL"),
            "DATE_ACTIVE_FROM" => ConvertTimeStamp(time(), "FULL"),
            "ACTIVE" => "Y",
        );
        if ($elID = $el->Add($arElement)) {
          $arEventFields = array(
              'DATE_SENT' => $arElement["DATE_ACTIVE_FROM"],
              'NAME' => $name,
              'EMAIL_FROM' => $mail,
              'EMAIL_TO' => $employee_mail,
              'MESSAGE' => $message
          );

          if (!CEvent::Send("MESSAGE_PERSONAL", $siteID, $arEventFields)){
            $answer['error'] = true;
            if($formLang == 'en') {
                $answer['errorText'] = 'Message not sent';
            } else {
                $answer['errorText'] = "Сообщение не отправлено!";
            }
          }
        } else {
          $answer['error'] = true;
          $answer['errorText'] = "Error: " . $el->LAST_ERROR;
        }
      } else {
        $answer['error'] = true;
        if($formLang == 'en') {
            $answer['errorText'] = 'You have not passed the verification reCaptcha';
        } else {
            $answer['errorText'] = 'Вы не прошли проверку reCaptcha';
        }
      }
    } else {
      $answer['error'] = true;
      if($formLang == 'en') {
          $answer['errorText'] = 'You have not passed the verification reCaptcha';
      } else {
          $answer['errorText'] = 'Вы не прошли проверку reCaptcha';
      }
    }
  }
  echo json_encode($answer);

  die();
}