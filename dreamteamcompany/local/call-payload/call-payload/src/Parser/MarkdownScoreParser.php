<?php
declare(strict_types=1);

namespace App\Parser;

use App\Contracts\ScoreParserInterface;
use App\DTO\ScorePayloadDTO;

final class MarkdownScoreParser implements ScoreParserInterface
{
    private int $defaultBaseScore;
    /** @var array<string, int> */
    private array $criterionTitleMap = [
        'цель звонка' => 1,
        'название клиники' => 2,
        'представление сотрудника' => 3,
        'программирование' => 4,
        'выявление потребностей' => 5,
        'упаковка предложения' => 6,
        'запись' => 7,
        'противопоказания' => 8,
        'ответ на вопрос о цене' => 9,
        'уверенность в ответах' => 10,
        'следующее действие' => 11,
        'вопросы клиента' => 12,
        'длительность приёма' => 13,
        'длительность приема' => 13,
        'компетентность' => 14,
        'прощание' => 15,
        'возражения' => 16,
        'семейный прием' => 17,
        'семейный приём' => 17,
    ];

    public function __construct(int $defaultBaseScore = 27)
    {
        $this->defaultBaseScore = $defaultBaseScore;
    }

    public function parse(string $input): ScorePayloadDTO
    {
        $input = $this->normalizeInput($input);

        $warnings = [];
        $criteriaScores = [];
        $criteriaTotal = 0;
        $familyOfferStatusCode = $this->extractFamilyOfferStatusCode($input);
        $callDateTime = $this->extractCallDateTime($input);
        $isBookedValue = $this->extractIsBookedValue($input);

        $criteriaMatches = [];
        preg_match_all(
            '/(?:^|\\\\n|\R|[\s>•\-])(\d{1,2})\.\s*([^\[\r\n]+?)\s*\[\s*оценка\s*:\s*(\d+)\s*\/\s*(\d+)\s*\]/ui',
            $input,
            $criteriaMatches,
            PREG_SET_ORDER
        );

        foreach ($criteriaMatches as $match) {
            $criterionByNumber = (int) $match[1];
            $criterionTitle = trim((string) $match[2]);
            $score = (int) $match[3];
            $max = (int) $match[4];
            $criterion = $this->resolveCriterionId($criterionByNumber, $criterionTitle);

            if ($criterion < 1 || $criterion > 17) {
                continue;
            }

            $criteriaScores[$criterion] = $score;
            $criteriaTotal += $max;
        }

        $baseScore = $this->extractBaseScore($input);
        if ($baseScore === null) {
            $baseScore = $this->defaultBaseScore;
            $warnings[] = 'BASE_SCORE не найден, применен default_base_score.';
        }

        $finalScore = $this->extractFinalScore($input);
        $deductionFromLines = $this->extractDeductionsTotal($input);
        $deductionTotal = null;

        if ($finalScore !== null) {
            $deductionTotal = max(0, $baseScore - $finalScore);
        } elseif ($deductionFromLines !== null) {
            $deductionTotal = $deductionFromLines;
            $finalScore = max(0, $baseScore - $deductionFromLines);
            $warnings[] = 'FINAL_SCORE не найден, рассчитан через BASE_SCORE и Снижения.';
        } else {
            $warnings[] = 'FINAL_SCORE не найден и не удалось вычислить через Снижения.';
        }

        $finalScorePercent = null;
        if ($finalScore !== null && $criteriaTotal > 0) {
            $finalScorePercent = round(($finalScore / $criteriaTotal) * 100, 2);
        }

        return new ScorePayloadDTO(
            $criteriaScores,
            $baseScore,
            $finalScore,
            $deductionTotal,
            $criteriaTotal,
            $finalScorePercent,
            $familyOfferStatusCode,
            $warnings,
            $callDateTime,
            $isBookedValue
        );
    }

    private function extractBaseScore(string $input): ?int
    {
        $match = [];
        if (preg_match('/BASE_SCORE\s*=\s*(\d+)/ui', $input, $match) === 1) {
            return (int) $match[1];
        }

        if (preg_match('/Базовый\s+балл\s*:\s*(\d+)/ui', $input, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    private function extractFinalScore(string $input): ?int
    {
        $match = [];
        if (preg_match('/ИТОГО\s*:\s*[^\r\n=]*=\s*\*{0,2}\s*(\d+)\s*балл/ui', $input, $match) === 1) {
            return (int) $match[1];
        }

        if (preg_match('/ИТОГО\s*:\s*\*{0,2}\s*(\d+)\s*балл/ui', $input, $match) === 1) {
            return (int) $match[1];
        }

        if (preg_match('/Итоговая\s+оценка[^\r\n]*?(\d+)\s*балл/ui', $input, $match) === 1) {
            return (int) $match[1];
        }

        return null;
    }

    private function extractDeductionsTotal(string $input): ?int
    {
        $matches = [];
        preg_match_all('/\bК\d+\s*:[^\r\n]*?-\s*(\d+)/ui', $input, $matches);
        if (!isset($matches[1]) || $matches[1] === []) {
            return null;
        }

        $sum = 0;
        foreach ($matches[1] as $value) {
            $sum += (int) $value;
        }

        return $sum;
    }

    private function resolveCriterionId(int $criterionByNumber, string $criterionTitle): int
    {
        $normalizedTitle = mb_strtolower($criterionTitle);
        foreach ($this->criterionTitleMap as $titleFragment => $criterionId) {
            if (mb_strpos($normalizedTitle, $titleFragment) !== false) {
                return $criterionId;
            }
        }

        return $criterionByNumber;
    }

    private function extractFamilyOfferStatusCode(string $input): ?string
    {
        $line = [];
        if (preg_match('/(?:^|\\\\n|\R)[^\r\n]*17\.\s*[^\r\n]*/ui', $input, $line) !== 1) {
            return null;
        }

        $value = mb_strtolower((string) $line[0]);
        if (mb_strpos($value, 'не оценивается') !== false || mb_strpos($value, 'не оценив') !== false) {
            return 'N_A';
        }

        return null;
    }

    private function extractCallDateTime(string $input): ?string
    {
        $match = [];
        if (preg_match('/Дата\s*и\s*время\s*:\s*([^\r\n]+)/ui', $input, $match) !== 1) {
            return null;
        }

        $raw = trim((string) $match[1], " \t\n\r\0\x0B*");
        if ($raw === '' || $raw === '-' || $raw === '—') {
            return null;
        }

        return $this->toBitrixDateTime($raw);
    }

    private function toBitrixDateTime(string $value): ?string
    {
        $formats = [
            'd.m.Y H:i:s',
            'd.m.Y H:i',
            'Y-m-d H:i:s',
            'Y-m-d H:i',
            \DateTimeInterface::ATOM,
            'Y-m-d\TH:i:sP',
            'Y-m-d\TH:i:s',
        ];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->format('d.m.Y H:i:s');
            }
        }

        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return null;
        }

        return gmdate('d.m.Y H:i:s', $timestamp);
    }

    private function extractIsBookedValue(string $input): ?string
    {
        // First pass: explicit "Статус записи: ...".
        if (preg_match('/Статус\s*записи\s*:\s*([^\r\n]+)/ui', $input, $match) === 1) {
            $status = mb_strtolower((string) $match[1]);
            $status = preg_replace('/\s+/u', ' ', $status);
            if (!is_string($status)) {
                $status = '';
            }

            if (preg_match('/\bне\s*запис(?:ан|ана|ано|аны)?\b/ui', $status) === 1) {
                return 'Нет';
            }
            if (preg_match('/\bзапис(?:ан|ана|ано|аны)?\b/ui', $status) === 1) {
                return 'Да';
            }
        }

        // Second pass: global markers in the full text.
        $normalized = mb_strtolower($input);
        $normalized = preg_replace('/\s+/u', ' ', $normalized);
        if (!is_string($normalized)) {
            $normalized = '';
        }

        if (
            preg_match('/\bклиент\s+не\s+запис(?:ан|ана|ано|аны)?\b/ui', $normalized) === 1
            || preg_match('/\bпричина\s+незаписи\b/ui', $normalized) === 1
            || preg_match('/\bне\s*запис(?:ан|ана|ано|аны)?\b/ui', $normalized) === 1
        ) {
            return 'Нет';
        }

        if (preg_match('/\bзапис(?:ан|ана|ано|аны)?\b/ui', $normalized) === 1) {
            return 'Да';
        }

        return null;
    }

    private function normalizeInput(string $input): string
    {
        // Some payloads are persisted with literal "\n" instead of real line breaks.
        $normalized = str_replace(["\\r\\n", "\\n", "\\r"], "\n", $input);
        $normalized = str_replace(["\r\n", "\r"], "\n", $normalized);

        return $normalized;
    }
}
