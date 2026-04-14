<?php
// normalizers/normalizer_factory.php
// Фабрика для создания нормализаторов

require_once __DIR__ . '/base_normalizer.php';
require_once __DIR__ . '/koltaсh_normalizer.php';
require_once __DIR__ . '/tilda_normalizer.php';
require_once __DIR__ . '/calltouch_normalizer.php';
require_once __DIR__ . '/generic_normalizer.php';
require_once __DIR__ . '/../logger_and_queue.php';

class NormalizerFactory {
    
    /**
     * Создает нормализатор по имени файла
     */
    private static $loadedNormalizers = [];
    
    public static function createNormalizerByFile($normalizerFile, $config) {
        // Убираем расширение .php если есть
        $normalizerFile = str_replace('.php', '', $normalizerFile);
        
        // Если указан base_normalizer (абстрактный класс), используем generic_normalizer
        if ($normalizerFile === 'base_normalizer') {
            logMessage("NormalizerFactory: base_normalizer - абстрактный класс, используем generic_normalizer", $config['global_log'], $config);
            return new GenericNormalizer($config);
        }
        
        // Определяем имя класса на основе имени файла
        $className = self::getClassNameFromFileName($normalizerFile);
        
        // Проверяем, не загружен ли уже класс
        if (class_exists($className)) {
            // Проверяем, не является ли класс абстрактным
            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract()) {
                logMessage("NormalizerFactory: класс '$className' абстрактный, используем generic_normalizer", $config['global_log'], $config);
                return new GenericNormalizer($config);
            }
            return new $className($config);
        }
        
        // Загружаем файл нормализатора только если он еще не загружен
        $normalizerPath = __DIR__ . '/' . $normalizerFile . '.php';
        
        if (!file_exists($normalizerPath)) {
            logMessage("NormalizerFactory: файл нормализатора не найден: $normalizerPath", $config['global_log'], $config);
            return new GenericNormalizer($config);
        }
        
        // Проверяем, не загружали ли мы уже этот файл
        if (!isset(self::$loadedNormalizers[$normalizerPath])) {
            require_once $normalizerPath;
            self::$loadedNormalizers[$normalizerPath] = true;
        }
        
        if (class_exists($className)) {
            // Проверяем, не является ли класс абстрактным
            $reflection = new ReflectionClass($className);
            if ($reflection->isAbstract()) {
                logMessage("NormalizerFactory: класс '$className' абстрактный, используем generic_normalizer", $config['global_log'], $config);
                return new GenericNormalizer($config);
            }
            return new $className($config);
        } else {
            logMessage("NormalizerFactory: класс '$className' не найден в файле $normalizerFile", $config['global_log'], $config);
            return new GenericNormalizer($config);
        }
    }
    
    /**
     * Создает нормализатор по типу данных (устаревший метод)
     */
    public static function createNormalizer($dataType, $config) {
        switch ($dataType) {
            case 'koltaсh':
                return new KoltaсhNormalizer($config);
                
            case 'tilda':
                return new TildaNormalizer($config);
                
            case 'calltouch':
                return new CallTouchNormalizer($config);
                
            case 'wordpress':
                // TODO: создать WordPressNormalizer
                return new GenericNormalizer($config);
                
            case 'bitrix':
                // TODO: создать BitrixNormalizer
                return new GenericNormalizer($config);
                
            case 'jivosite':
                // TODO: создать JivoSiteNormalizer
                return new GenericNormalizer($config);
                
            case 'calendly':
                // TODO: создать CalendlyNormalizer
                return new GenericNormalizer($config);
                
            case 'generic':
            default:
                return new GenericNormalizer($config);
        }
    }
    
    /**
     * Преобразует имя файла в имя класса
     */
    private static function getClassNameFromFileName($fileName) {
        // Убираем расширение
        $fileName = str_replace('.php', '', $fileName);
        
        // Разбиваем по подчеркиваниям
        $parts = explode('_', $fileName);
        
        // Преобразуем каждую часть в CamelCase
        $className = '';
        foreach ($parts as $part) {
            $className .= ucfirst($part);
        }
        
        // Добавляем суффикс Normalizer если его нет
        if (substr($className, -10) !== 'Normalizer') {
            $className .= 'Normalizer';
        }
        
        return $className;
    }
    
    /**
     * Получает список доступных нормализаторов
     */
    public static function getAvailableNormalizers() {
        $normalizers = [];
        
        // Сканируем папку normalizers
        $normalizerFiles = glob(__DIR__ . '/*_normalizer.php');
        
        foreach ($normalizerFiles as $file) {
            $fileName = basename($file, '.php');
            $className = self::getClassNameFromFileName($fileName);
            $normalizers[$fileName] = $className;
        }
        
        return $normalizers;
    }
    
    /**
     * Получает список файлов нормализаторов
     */
    public static function getAvailableNormalizerFiles() {
        $files = [];
        
        // Сканируем папку normalizers
        $normalizerFiles = glob(__DIR__ . '/*_normalizer.php');
        
        foreach ($normalizerFiles as $file) {
            $files[] = basename($file);
        }
        
        return $files;
    }
}
