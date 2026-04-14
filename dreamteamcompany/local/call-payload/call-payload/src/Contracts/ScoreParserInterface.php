<?php
declare(strict_types=1);

namespace App\Contracts;

use App\DTO\ScorePayloadDTO;

interface ScoreParserInterface
{
    public function parse(string $input): ScorePayloadDTO;
}
