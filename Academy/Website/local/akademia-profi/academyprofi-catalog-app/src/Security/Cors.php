<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Security;

final class Cors
{
    /**
     * @param string[] $allowedDomains
     */
    public function __construct(private readonly array $allowedDomains)
    {
    }

    /**
     * Applies CORS headers and handles OPTIONS preflight if needed.
     * Returns true if request processing should continue.
     */
    public function handle(): bool
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        $allowAll = count($this->allowedDomains) === 0;
        $originAllowed = $allowAll || $this->isAllowedOrigin($origin);

        if ($origin !== '' && $originAllowed) {
            header('Access-Control-Allow-Origin: ' . ($allowAll ? '*' : $origin));
            header('Vary: Origin');
            header('Access-Control-Allow-Credentials: false');
            header('Access-Control-Allow-Headers: Content-Type, Authorization');
            header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
            header('Access-Control-Max-Age: 600');
        }

        if ($method === 'OPTIONS') {
            http_response_code($originAllowed ? 204 : 403);
            exit;
        }

        return true;
    }

    private function isAllowedOrigin(string $origin): bool
    {
        $origin = trim($origin);
        if ($origin === '') {
            return false;
        }

        foreach ($this->allowedDomains as $allowed) {
            $allowed = trim((string) $allowed);
            if ($allowed === '') {
                continue;
            }

            // allow either full origin ("https://example.com") or bare host ("example.com")
            if (str_starts_with($allowed, 'http://') || str_starts_with($allowed, 'https://')) {
                if (strcasecmp($origin, $allowed) === 0) {
                    return true;
                }
                continue;
            }

            $host = (string) parse_url($origin, PHP_URL_HOST);
            if ($host !== '' && strcasecmp($host, $allowed) === 0) {
                return true;
            }
        }

        return false;
    }
}

