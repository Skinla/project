<?php
declare(strict_types=1);

namespace App\Bitrix;

use RuntimeException;

final class BitrixBootstrap
{
    private ?string $prologBeforePath;

    public function __construct(?string $prologBeforePath = null)
    {
        $this->prologBeforePath = $prologBeforePath;
    }

    public function boot(): void
    {
        if (!defined('NO_KEEP_STATISTIC')) {
            define('NO_KEEP_STATISTIC', true);
        }
        if (!defined('NOT_CHECK_PERMISSIONS')) {
            define('NOT_CHECK_PERMISSIONS', true);
        }
        if (!defined('BX_CRONTAB')) {
            define('BX_CRONTAB', true);
        }

        $prologBeforePath = $this->resolvePrologBeforePath();
        if (!is_file($prologBeforePath)) {
            throw new RuntimeException('Bitrix prolog_before.php not found: ' . $prologBeforePath);
        }

        require_once $prologBeforePath;

        if (!class_exists(\Bitrix\Main\Loader::class)) {
            throw new RuntimeException('Bitrix Loader class is not available after bootstrap.');
        }

        if (!\Bitrix\Main\Loader::includeModule('iblock')) {
            throw new RuntimeException('Bitrix module "iblock" is not loaded.');
        }
    }

    private function resolvePrologBeforePath(): string
    {
        if (is_string($this->prologBeforePath) && trim($this->prologBeforePath) !== '') {
            return $this->prologBeforePath;
        }

        if (isset($_SERVER['DOCUMENT_ROOT']) && is_string($_SERVER['DOCUMENT_ROOT']) && $_SERVER['DOCUMENT_ROOT'] !== '') {
            return rtrim($_SERVER['DOCUMENT_ROOT'], '/\\') . '/bitrix/modules/main/include/prolog_before.php';
        }

        throw new RuntimeException('Cannot resolve DOCUMENT_ROOT for Bitrix bootstrap.');
    }
}
