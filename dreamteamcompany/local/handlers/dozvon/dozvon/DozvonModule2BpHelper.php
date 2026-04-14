<?php
declare(strict_types=1);

require_once __DIR__ . '/DozvonModule2Schema.php';
require_once __DIR__ . '/DozvonUniversalListHelper.php';
require_once __DIR__ . '/DozvonModule2Schedule.php';
require_once __DIR__ . '/DozvonModule2Helper.php';

/**
 * Входная точка для BP PHP-блока модуля 2.
 *
 * Как использовать в БП:
 *
 * require_once($_SERVER['DOCUMENT_ROOT'] . '/local/handlers/dozvon/dozvon/DozvonModule2BpHelper.php');
 * $rootActivity = $this->GetRootActivity();
 * DozvonModule2BpHelper::execute($rootActivity);
 */
final class DozvonModule2BpHelper
{
    private const IN_MASTER_ELEMENT_ID = 'MODULE2_MASTER_ELEMENT_ID';

    private const OUT_MASTER_ID = 'MODULE2_MASTER_ID';
    private const OUT_QUEUE_CREATED = 'MODULE2_QUEUE_CREATED';
    private const OUT_ATTEMPTS_CREATED = 'MODULE2_ATTEMPTS_CREATED';
    private const OUT_ERROR = 'MODULE2_ERROR_MESSAGE';

    public static function execute(object $rootActivity): void
    {
        $masterId = (int)preg_replace('/\D+/', '', (string)self::getVar($rootActivity, self::IN_MASTER_ELEMENT_ID));
        if ($masterId <= 0) {
            self::setVar($rootActivity, self::OUT_QUEUE_CREATED, 'N');
            self::setVar($rootActivity, self::OUT_ATTEMPTS_CREATED, '0');
            self::setVar($rootActivity, self::OUT_ERROR, 'MODULE2_MASTER_ELEMENT_ID is empty');
            self::writeLog($rootActivity, 'MODULE2_MASTER_ELEMENT_ID is empty');
            return;
        }

        $config = require __DIR__ . '/../config.php';
        try {
            $helper = new DozvonModule2Helper($config);
            $result = $helper->ensureQueueForMaster($masterId);
        } catch (Throwable $e) {
            self::setVar($rootActivity, self::OUT_MASTER_ID, (string)$masterId);
            self::setVar($rootActivity, self::OUT_QUEUE_CREATED, 'N');
            self::setVar($rootActivity, self::OUT_ATTEMPTS_CREATED, '0');
            self::setVar($rootActivity, self::OUT_ERROR, $e->getMessage());
            self::writeLog($rootActivity, 'Module2 error: ' . $e->getMessage());
            return;
        }

        self::setVar($rootActivity, self::OUT_MASTER_ID, (string)$masterId);

        if (!empty($result['error'])) {
            self::setVar($rootActivity, self::OUT_QUEUE_CREATED, 'N');
            self::setVar($rootActivity, self::OUT_ATTEMPTS_CREATED, '0');
            self::setVar($rootActivity, self::OUT_ERROR, (string)$result['error']);
            self::writeLog($rootActivity, 'Module2 queue error: ' . (string)$result['error']);
            return;
        }

        self::setVar($rootActivity, self::OUT_QUEUE_CREATED, 'Y');
        self::setVar($rootActivity, self::OUT_ATTEMPTS_CREATED, (string)((int)($result['attempts_created'] ?? 0)));
        self::setVar($rootActivity, self::OUT_ERROR, '');
        self::writeLog($rootActivity, 'Module2 queue generated for master ' . $masterId . ', attempts: ' . (int)($result['attempts_created'] ?? 0));
    }

    private static function getVar(object $rootActivity, string $name)
    {
        if (method_exists($rootActivity, 'GetVariable')) {
            return $rootActivity->GetVariable($name);
        }
        return null;
    }

    private static function setVar(object $rootActivity, string $name, string $value): void
    {
        if (method_exists($rootActivity, 'SetVariable')) {
            $rootActivity->SetVariable($name, $value);
        }
    }

    private static function writeLog(object $rootActivity, string $message): void
    {
        if (method_exists($rootActivity, 'WriteToTrackingService')) {
            $rootActivity->WriteToTrackingService($message);
        }
    }
}
