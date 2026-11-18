<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Base points remain the denominator; an explicit bonus extends only the allowed earned score. */
final readonly class AssignmentPoints
{
    public const int LIMIT = 1000000;
    public int $maximumHundredths;

    public function __construct(public int $possibleHundredths, public int $bonusHundredths = 0)
    {
        if ($possibleHundredths < 1 || $possibleHundredths > self::LIMIT) {
            throw new ValidationException([
                [
                    "field" => "possible_hundredths",
                    "message" => "Possible points must be between 0.01 and 10,000.",
                ],
            ]);
        }
        if ($bonusHundredths < 0 || $bonusHundredths > self::LIMIT - $possibleHundredths) {
            throw new ValidationException([
                [
                    "field" => "bonus_hundredths",
                    "message" =>
                        "Enter a non-negative bonus limit; possible points plus bonus cannot exceed 10,000 points.",
                ],
            ]);
        }
        $this->maximumHundredths = $possibleHundredths + $bonusHundredths;
    }

    public function assertEarned(?int $earned): void
    {
        if ($earned !== null && ($earned < 0 || $earned > $this->maximumHundredths)) {
            throw new ValidationException([
                [
                    "field" => "earned_hundredths",
                    "message" => "Earned points must be between zero and this assignment’s score limit.",
                ],
            ]);
        }
    }
}
