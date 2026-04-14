<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Http;

final class JsonResponse
{
    public static function send(int $status, array $data, array $headers = []): never
    {
        if (defined('ACADEMYPROFI_TEST_MODE') && ACADEMYPROFI_TEST_MODE === true) {
            throw new ResponseException($status, $data, $headers);
        }

        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');

        foreach ($headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(array $data, array $headers = []): never
    {
        self::send(200, $data, $headers);
    }

    public static function badRequest(string $message, array $headers = []): never
    {
        self::send(400, ['error' => $message], $headers);
    }

    public static function badGateway(string $message, array $headers = []): never
    {
        self::send(502, ['error' => $message], $headers);
    }

    public static function serverError(string $message, array $headers = []): never
    {
        self::send(500, ['error' => $message], $headers);
    }
}

