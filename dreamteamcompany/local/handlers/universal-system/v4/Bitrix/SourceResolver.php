<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Bitrix;

use mysqli;
use RuntimeException;
use UniversalSystem\V4\Support\Logger;

final class SourceResolver
{
    private array $config;
    private Logger $logger;
    private ?mysqli $connection = null;

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function resolve(array $parsed): array
    {
        try {
            $mode = (string)($parsed['source']['lookup_mode'] ?? '');
            if ($mode === 'calltouch_pair') {
                return $this->resolveCalltouch($parsed);
            }

            $lookupKey = trim((string)($parsed['source']['lookup_key'] ?? ''));
            if ($lookupKey === '') {
                return ['found' => false, 'retryable' => false, 'error_code' => 'empty_lookup_key', 'error_message' => 'Пустой ключ поиска источника'];
            }

            $element = $this->findElementByName($lookupKey);
            if ($element === null) {
                return ['found' => false, 'retryable' => false, 'error_code' => 'source_not_found', 'error_message' => "Источник '$lookupKey' не найден в ИБ54"];
            }

            return ['found' => true, 'config' => $this->buildConfig($element)];
        } catch (\Throwable $e) {
            $this->logger->error('SourceResolver failed', ['error' => $e->getMessage()]);
            return ['found' => false, 'retryable' => true, 'error_code' => 'resolver_exception', 'error_message' => $e->getMessage()];
        }
    }

    private function resolveCalltouch(array $parsed): array
    {
        $lookupKey = trim((string)($parsed['source']['lookup_key'] ?? ''));
        $siteId = trim((string)($parsed['source']['site_id'] ?? ''));
        $subPoolName = trim((string)($parsed['source']['sub_pool_name'] ?? ''));
        if ($lookupKey === '' || $siteId === '') {
            return ['found' => false, 'retryable' => false, 'error_code' => 'invalid_calltouch_lookup', 'error_message' => 'CallTouch lookup requires lookup_key and site_id'];
        }

        $element = $this->findElementByNameAndSiteId($lookupKey, $siteId);
        if ($element === null && $subPoolName !== '' && $subPoolName !== $lookupKey) {
            $element = $this->findElementByNameAndSiteId($subPoolName, $siteId);
        }

        if ($element === null) {
            return [
                'found' => false,
                'retryable' => false,
                'error_code' => 'calltouch_source_not_found',
                'error_message' => "CallTouch pair '$lookupKey' + '$siteId' не найден в ИБ54",
            ];
        }

        return ['found' => true, 'config' => $this->buildConfig($element)];
    }

    private function buildConfig(array $element): array
    {
        $properties = $this->fetchElementProperties((int)$element['ID']);

        $cityId = $this->firstValue($properties['PROPERTY_191']['VALUE'] ?? null);
        $sourceRaw = $this->firstValue($properties['PROPERTY_192']['VALUE'] ?? null);
        $ispolnitel = $this->firstValue($properties['PROPERTY_193']['VALUE'] ?? null);
        $infopovod = $this->firstValue($properties['PROPERTY_194']['VALUE'] ?? null);
        $observers = $this->normalizeObserverIds($properties['PROPERTY_195']['VALUE'] ?? []);

        return [
            'element_id' => (int)$element['ID'],
            'source_name' => (string)$element['NAME'],
            'city_id' => $cityId,
            'source_id' => $this->resolveSourceId($sourceRaw),
            'assigned_by_id' => $this->resolveAssignedById($cityId),
            'ispolnitel' => $ispolnitel,
            'infopovod' => $infopovod,
            'observer_ids' => $observers,
        ];
    }

    private function resolveSourceId(?string $rawValue): ?string
    {
        if ($rawValue === null || $rawValue === '') {
            return null;
        }

        if (!ctype_digit($rawValue)) {
            return $rawValue;
        }

        $listIblockId = (int)($this->config['iblock']['source_list_id'] ?? 19);
        $sql = "
            SELECT ep.VALUE
            FROM b_iblock_element_property ep
            JOIN b_iblock_property p ON p.ID = ep.IBLOCK_PROPERTY_ID
            WHERE ep.IBLOCK_ELEMENT_ID = ?
              AND p.IBLOCK_ID = ?
              AND p.CODE = 'PROPERTY_73'
            LIMIT 1
        ";
        $row = $this->fetchAssoc($sql, 'ii', [(int)$rawValue, $listIblockId]);
        return $row['VALUE'] ?? null;
    }

    private function resolveAssignedById(?string $cityId): ?int
    {
        if ($cityId === null || $cityId === '') {
            return null;
        }

        if (!ctype_digit($cityId)) {
            return null;
        }

        $listIblockId = (int)($this->config['iblock']['city_list_id'] ?? 22);
        $sql = "
            SELECT ep.VALUE
            FROM b_iblock_element_property ep
            JOIN b_iblock_property p ON p.ID = ep.IBLOCK_PROPERTY_ID
            WHERE ep.IBLOCK_ELEMENT_ID = ?
              AND p.IBLOCK_ID = ?
              AND p.CODE = 'PROPERTY_185'
            LIMIT 1
        ";
        $row = $this->fetchAssoc($sql, 'ii', [(int)$cityId, $listIblockId]);
        if (!isset($row['VALUE']) || !is_numeric($row['VALUE'])) {
            return null;
        }

        return (int)$row['VALUE'];
    }

    private function normalizeObserverIds($value): array
    {
        $items = is_array($value) ? $value : [$value];
        $items = array_values(array_filter(array_map(static function ($item): ?int {
            if (!is_numeric($item)) {
                return null;
            }
            $id = (int)$item;
            return $id > 0 ? $id : null;
        }, $items)));

        return $items;
    }

    private function firstValue($value): ?string
    {
        if (is_array($value)) {
            $value = reset($value);
        }

        if ($value === null || $value === '') {
            return null;
        }

        return (string)$value;
    }

    private function findElementByName(string $name): ?array
    {
        $sql = "
            SELECT e.ID, e.NAME
            FROM b_iblock_element e
            WHERE e.IBLOCK_ID = ?
              AND e.NAME = ?
              AND e.ACTIVE = 'Y'
            LIMIT 1
        ";

        return $this->fetchAssoc(
            $sql,
            'is',
            [(int)($this->config['iblock']['source_config_id'] ?? 54), $name]
        );
    }

    private function findElementByNameAndSiteId(string $name, string $siteId): ?array
    {
        $sql = "
            SELECT e.ID, e.NAME
            FROM b_iblock_element e
            JOIN b_iblock_element_property ep ON ep.IBLOCK_ELEMENT_ID = e.ID
            JOIN b_iblock_property p ON p.ID = ep.IBLOCK_PROPERTY_ID
            WHERE e.IBLOCK_ID = ?
              AND e.NAME = ?
              AND e.ACTIVE = 'Y'
              AND p.CODE = 'PROPERTY_199'
              AND ep.VALUE = ?
            LIMIT 1
        ";

        return $this->fetchAssoc(
            $sql,
            'iss',
            [(int)($this->config['iblock']['source_config_id'] ?? 54), $name, $siteId]
        );
    }

    private function fetchElementProperties(int $elementId): array
    {
        $sql = "
            SELECT p.CODE, ep.VALUE, ep.VALUE_NUM
            FROM b_iblock_element_property ep
            JOIN b_iblock_property p ON p.ID = ep.IBLOCK_PROPERTY_ID
            WHERE ep.IBLOCK_ELEMENT_ID = ?
              AND p.IBLOCK_ID = ?
        ";
        $statement = $this->prepare($sql, 'ii', [$elementId, (int)($this->config['iblock']['source_config_id'] ?? 54)]);
        $statement->execute();
        $result = $statement->get_result();
        $properties = [];

        while ($row = $result->fetch_assoc()) {
            $code = (string)$row['CODE'];
            $value = $row['VALUE_NUM'] !== null && $row['VALUE_NUM'] !== '' ? $row['VALUE_NUM'] : $row['VALUE'];
            if (!isset($properties[$code])) {
                $properties[$code] = ['VALUE' => $value];
                continue;
            }

            if (!is_array($properties[$code]['VALUE'])) {
                $properties[$code]['VALUE'] = [$properties[$code]['VALUE']];
            }
            $properties[$code]['VALUE'][] = $value;
        }

        $statement->close();
        return $properties;
    }

    private function fetchAssoc(string $sql, string $types, array $params): ?array
    {
        $statement = $this->prepare($sql, $types, $params);
        $statement->execute();
        $result = $statement->get_result();
        $row = $result->fetch_assoc() ?: null;
        $statement->close();
        return $row;
    }

    private function prepare(string $sql, string $types, array $params)
    {
        $statement = $this->connection()->prepare($sql);
        if ($statement === false) {
            throw new RuntimeException('SQL prepare failed: ' . $this->connection()->error);
        }

        if ($params !== []) {
            $statement->bind_param($types, ...$params);
        }

        return $statement;
    }

    private function connection(): mysqli
    {
        if ($this->connection instanceof mysqli) {
            return $this->connection;
        }

        $settingsPath = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/.settings.php';
        $host = 'localhost';
        $database = 'sitemanager';
        $login = 'bitrix0';
        $password = '_V5to0nMt5kzN_pR2[HT';

        if (is_readable($settingsPath)) {
            $settings = include $settingsPath;
            if (is_array($settings)) {
                $default = $settings['connections']['value']['default'] ?? [];
                $host = (string)($default['host'] ?? $host);
                $database = (string)($default['database'] ?? $database);
                $login = (string)($default['login'] ?? $login);
                $password = (string)($default['password'] ?? $password);
            }
        }

        $connection = new mysqli($host, $login, $password, $database);
        if ($connection->connect_error) {
            throw new RuntimeException('DB connect error: ' . $connection->connect_error);
        }

        $connection->set_charset('utf8');
        $this->connection = $connection;
        return $this->connection;
    }
}
