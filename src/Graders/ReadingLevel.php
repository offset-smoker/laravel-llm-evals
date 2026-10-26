<?php

declare(strict_types=1);

namespace OffsetSmoker\LlmEvals\Graders;

use InvalidArgumentException;
use OffsetSmoker\LlmEvals\Dataset\EvalCase;
use OffsetSmoker\LlmEvals\Drivers\Response;
use OffsetSmoker\LlmEvals\Support\ReadabilityScore;

/**
 * Passes when the response's Flesch-Kincaid grade level is at or below
 * `maxGrade`. The syllable count is a heuristic tuned for English prose, so
 * treat the grade as an estimate and leave a margin in the threshold.
 */
final readonly class ReadingLevel implements Grader
{
    public function __construct(private float $maxGrade)
    {
        if ($maxGrade < 0 || $maxGrade > 30) {
            throw new InvalidArgumentException('max_grade must be between 0 and 30.');
        }
    }

    public function describe(): string
    {
        return 'reads at grade ' . self::format($this->maxGrade) . ' or below';
    }

    public function grade(Response $response, EvalCase $case): Grade
    {
        $grade = ReadabilityScore::fleschKincaidGrade($response->text);

        if ($grade === null) {
            return Grade::fail('no words to measure');
        }

        $summary = 'grade ' . self::format($grade);

        return $grade <= $this->maxGrade ? Grade::pass($summary) : Grade::fail($summary);
    }

    private static function format(float $grade): string
    {
        return number_format($grade, 1);
    }
}
