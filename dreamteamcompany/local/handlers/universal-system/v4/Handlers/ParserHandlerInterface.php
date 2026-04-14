<?php

declare(strict_types=1);

namespace UniversalSystem\V4\Handlers;

interface ParserHandlerInterface
{
    public function parse(array $request): array;
}
