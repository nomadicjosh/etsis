<?php

declare(strict_types=1);

namespace Domain\Gradebook\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Zero base points with a positive bonus limit describe optional, bonus-only work. */
final readonly class AssignmentPoints
{
    public const int LIMIT = 1000000;
    public int $maximumHundredths;

    public function __construct(public int $possibleHundredths, public int $bonusHundredths = 0)
    {
        if ($possibleHundredths < 0 || $possibleHundredths > self::LIMIT) {
            throw new ValidationException([
                [
                    "field" => "possible_hundredths",
                    "message" => "Possible points must be between zero and 10,000; zero requires a bonus limit.",
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
        if ($possibleHundredths === 0 && $bonusHundredths === 0) {
            throw new ValidationException([
                ["field" => "bonus_hundredths", "message" => "Bonus-only work needs a positive bonus limit."],
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
