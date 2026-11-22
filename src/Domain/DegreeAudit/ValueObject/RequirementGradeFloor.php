<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use InvalidArgumentException;
use Qubus\Exception\Exception;

use function Codefy\Framework\Helpers\trans_html;

/** A per-course point threshold, never a GPA, percentage or implicit scale conversion. */
final readonly class RequirementGradeFloor
{
    private function __construct(public ?int $pointsMilli)
    {
    }

    /**
     * @throws Exception
     */
    public static function fromRule(array $rule, int $maximumMilli = 10000): self
    {
        $points = $rule["min_course_grade_points_milli"] ?? null;
        if (
            $maximumMilli < 0 ||
            $maximumMilli > 10000 ||
            ($points !== null && (!is_int($points) || $points < 0 || $points > $maximumMilli))
        ) {
            throw new InvalidArgumentException(
                trans_html("Choose a card grade-point minimum within the selected grading maximum."),
            );
        }
        return new self($points);
    }
}
