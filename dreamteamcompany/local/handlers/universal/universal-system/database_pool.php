<?php
// database_pool.php
// Пул соединений к БД для переиспользования

require_once __DIR__ . '/logger_and_queue.php';

class DatabaseConnectionPool {
    private static $connection = null;
    private static $config = null;
    
    /**
     * Получает переиспользуемое соединение к БД
     */
    public static function getConnection($config) {
        // Проверяем, нужно ли переподключение
        $needReconnect = false;
        if (self::$connection === null) {
            $needReconnect = true;
        } else {
            // Проверяем соединение через ping() (если доступен) или через простой запрос
            if (method_exists(self::$connection, 'ping')) {
                if (!self::$connection->ping()) {
                    $needReconnect = true;
                }
            } else {
                // Для старых версий PHP проверяем через простой запрос
                $testResult = @self::$connection->query("SELECT 1");
                if (!$testResult || self::$connection->errno) {
                    $needReconnect = true;
                }
            }
        }
        
        if ($needReconnect) {
            try {
                self::$config = $config;
                $documentRoot = $_SERVER["DOCUMENT_ROOT"] ?? __DIR__ . '/../..';
                $dbConfigFile = $documentRoot . "/bitrix/.settings.php";
                
                if (file_exists($dbConfigFile)) {
                    $dbConfig = include($dbConfigFile);
                    $host = $dbConfig['connections']['value']['default']['host'] ?? 'localhost';
                    $dbname = $dbConfig['connections']['value']['default']['database'] ?? 'sitemanager';
                    $username = $dbConfig['connections']['value']['default']['login'] ?? 'bitrix0';
                    $password = $dbConfig['connections']['value']['default']['password'] ?? '';
                } else {
                    $host = 'localhost';
                    $dbname = 'sitemanager';
                    $username = 'bitrix0';
                    $password = '';
                }
                
                // Закрываем старое соединение, если оно было
                if (self::$connection !== null) {
                    @self::$connection->close();
                }
                
                self::$connection = @new mysqli($host, $username, $password, $dbname);
                if (self::$connection->connect_error) {
                    @logMessage("DatabaseConnectionPool: ОШИБКА подключения к БД: " . self::$connection->connect_error, $config['global_log'] ?? 'global.log', $config);
                    self::$connection = null;
                    return null;
                }
                
                // Дополнительная проверка: пытаемся выполнить простой запрос для проверки работоспособности
                // Это нужно для случаев, когда соединение создается, но не работает (например, Access denied)
                $testQuery = @self::$connection->query("SELECT 1");
                if (!$testQuery || self::$connection->errno) {
                    $errorMsg = self::$connection->error ?? 'Unknown database error';
                    @logMessage("DatabaseConnectionPool: ОШИБКА проверки соединения к БД: " . $errorMsg, $config['global_log'] ?? 'global.log', $config);
                    @self::$connection->close();
                    self::$connection = null;
                    return null;
                }
                
                self::$connection->set_charset("utf8");
                // Устанавливаем таймауты для предотвращения зависаний
                $dbConnectTimeout = $config['db_connect_timeout'] ?? 5;
                $dbReadTimeout = $config['db_read_timeout'] ?? 5;
                self::$connection->options(MYSQLI_OPT_CONNECT_TIMEOUT, $dbConnectTimeout);
                self::$connection->options(MYSQLI_OPT_READ_TIMEOUT, $dbReadTimeout);
            } catch (Exception $e) {
                // Перехватываем любые исключения и возвращаем null
                @logMessage("DatabaseConnectionPool: ИСКЛЮЧЕНИЕ при подключении к БД: " . $e->getMessage(), $config['global_log'] ?? 'global.log', $config);
                if (self::$connection !== null) {
                    @self::$connection->close();
                    self::$connection = null;
                }
                return null;
            } catch (Throwable $e) {
                // Перехватываем любые ошибки и возвращаем null
                @logMessage("DatabaseConnectionPool: ОШИБКА при подключении к БД: " . $e->getMessage(), $config['global_log'] ?? 'global.log', $config);
                if (self::$connection !== null) {
                    @self::$connection->close();
                    self::$connection = null;
                }
                return null;
            }
        }
        
        return self::$connection;
    }
    
    /**
     * Закрывает соединение (вызывать только при завершении скрипта)
     */
    public static function closeConnection() {
        if (self::$connection !== null) {
            try {
                @self::$connection->close();
            } catch (Exception $e) {
                // Игнорируем ошибки при закрытии
            } catch (Throwable $e) {
                // Игнорируем ошибки при закрытии
            }
            self::$connection = null;
        }
    }
}

