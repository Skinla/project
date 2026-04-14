<?php
declare(strict_types=1);

namespace App\DTO;

final class ScorePayloadDTO
{
    /** @var array<int, int> */
    private array $criteriaScores;

    private ?int $baseScore;
    private ?int $finalScore;
    private ?int $deductionTotal;
    private int $criteriaTotal;
    private ?float $finalScorePercent;
    private ?string $familyOfferStatusCode;
    private ?string $callDateTime;
    private ?string $isBookedValue;
    /** @var array<string, int|float|string> */
    private array $metadata;

    /** @var list<string> */
    private array $warnings;

    /**
     * @param array<int, int> $criteriaScores
     * @param list<string> $warnings
     * @param array<string, int|float|string> $metadata
     */
    public function __construct(
        array $criteriaScores,
        ?int $baseScore,
        ?int $finalScore,
        ?int $deductionTotal,
        int $criteriaTotal,
        ?float $finalScorePercent,
        ?string $familyOfferStatusCode,
        array $warnings,
        ?string $callDateTime = null,
        ?string $isBookedValue = null,
        array $metadata = []
    ) {
        $this->criteriaScores = $criteriaScores;
        $this->baseScore = $baseScore;
        $this->finalScore = $finalScore;
        $this->deductionTotal = $deductionTotal;
        $this->criteriaTotal = $criteriaTotal;
        $this->finalScorePercent = $finalScorePercent;
        $this->familyOfferStatusCode = $familyOfferStatusCode;
        $this->warnings = $warnings;
        $this->callDateTime = $callDateTime;
        $this->isBookedValue = $isBookedValue;
        $this->metadata = $metadata;
    }

    /**
     * @return array<int, int>
     */
    public function criteriaScores(): array
    {
        return $this->criteriaScores;
    }

    public function baseScore(): ?int
    {
        return $this->baseScore;
    }

    public function finalScore(): ?int
    {
        return $this->finalScore;
    }

    public function deductionTotal(): ?int
    {
        return $this->deductionTotal;
    }

    public function criteriaTotal(): int
    {
        return $this->criteriaTotal;
    }

    public function finalScorePercent(): ?float
    {
        return $this->finalScorePercent;
    }

    public function familyOfferStatusCode(): ?string
    {
        return $this->familyOfferStatusCode;
    }

    public function callDateTime(): ?string
    {
        return $this->callDateTime;
    }

    public function isBookedValue(): ?string
    {
        return $this->isBookedValue;
    }

    public function withCallDateTime(?string $callDateTime): self
    {
        return new self(
            $this->criteriaScores,
            $this->baseScore,
            $this->finalScore,
            $this->deductionTotal,
            $this->criteriaTotal,
            $this->finalScorePercent,
            $this->familyOfferStatusCode,
            $this->warnings,
            $callDateTime,
            $this->isBookedValue,
            $this->metadata
        );
    }

    /**
     * @param array<string, int|float|string> $metadata
     */
    public function withMetadata(array $metadata): self
    {
        return new self(
            $this->criteriaScores,
            $this->baseScore,
            $this->finalScore,
            $this->deductionTotal,
            $this->criteriaTotal,
            $this->finalScorePercent,
            $this->familyOfferStatusCode,
            $this->warnings,
            $this->callDateTime,
            $this->isBookedValue,
            $metadata
        );
    }

    /**
     * @return array<string, int|float|string>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return list<string>
     */
    public function warnings(): array
    {
        return $this->warnings;
    }
}
