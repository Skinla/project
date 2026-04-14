<?php
declare(strict_types=1);

namespace App\Contracts;

use App\DTO\ScorePayloadDTO;

interface IblockScoreWriterInterface
{
    /**
     * @return array{element_id:int, verified:bool}
     */
    public function write(string $callId, ScorePayloadDTO $payload): array;
}
