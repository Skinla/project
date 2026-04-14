<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Bitrix;

use RuntimeException;

final class BitrixException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $bitrixError = null,
        public readonly ?string $bitrixErrorDescription = null,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}

