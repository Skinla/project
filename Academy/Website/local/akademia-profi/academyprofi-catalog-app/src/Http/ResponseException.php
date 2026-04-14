<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Http;

use RuntimeException;

final class ResponseException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly array $data,
        public readonly array $headers = [],
    ) {
        parent::__construct('ResponseException: ' . $status);
    }
}

