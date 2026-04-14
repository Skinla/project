<?php
// cache_manager.php
// Кэширование результатов запросов к ИБ54

class CacheManager {
    private $cacheDir;
    private $config;
    private $cache = []; // In-memory cache
    private $cacheTTL = 3600; // 1 час
    
    public function __construct($config) {
        $this->config = $config;
        $this->cacheDir = $config['cache_dir'] ?? ($config['logs_dir'] . '/cache');
        $this->cacheTTL = $config['cache_ttl'] ?? 3600;
        
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0777, true);
        }
    }
    
    /**
     * Получает значение из кэша
     */
    public function get($key) {
        // Проверяем in-memory cache
        if (isset($this->cache[$key])) {
            $data = $this->cache[$key];
            if (time() < $data['expires']) {
                return $data['value'];
            }
            unset($this->cache[$key]);
        }
        
        // Проверяем file cache
        $cacheFile = $this->cacheDir . '/' . md5($key) . '.cache';
        if (file_exists($cacheFile)) {
            $fileContent = @file_get_contents($cacheFile);
            if ($fileContent !== false) {
                $data = json_decode($fileContent, true);
                if ($data && is_array($data) && isset($data['expires']) && isset($data['value']) && time() < $data['expires']) {
                    // Загружаем в memory cache
                    $this->cache[$key] = $data;
                    return $data['value'];
                } else {
                    // Файл поврежден или истек срок действия
                    @unlink($cacheFile);
                }
            } else {
                // Не удалось прочитать файл, удаляем его
                @unlink($cacheFile);
            }
        }
        
        return null;
    }
    
    /**
     * Сохраняет значение в кэш
     */
    public function set($key, $value, $ttl = null) {
        $ttl = $ttl ?? $this->cacheTTL;
        $expires = time() + $ttl;
        
        $data = [
            'value' => $value,
            'expires' => $expires,
            'created' => time()
        ];
        
        // Сохраняем в memory cache
        $this->cache[$key] = $data;
        
        // Сохраняем в file cache
        $cacheFile = $this->cacheDir . '/' . md5($key) . '.cache';
        @file_put_contents($cacheFile, json_encode($data, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Очищает кэш
     */
    public function clear($pattern = null) {
        if ($pattern === null) {
            $this->cache = [];
            $files = glob($this->cacheDir . '/*.cache');
            foreach ($files as $file) {
                @unlink($file);
            }
        } else {
            // Очистка по паттерну
            $files = glob($this->cacheDir . '/' . md5($pattern) . '*.cache');
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
    
    /**
     * Генерирует ключ кэша для запроса к ИБ54
     */
    public static function getIblock54Key($type, $value) {
        return "iblock54:{$type}:{$value}";
    }
}

