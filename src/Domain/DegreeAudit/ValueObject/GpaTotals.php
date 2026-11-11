<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use InvalidArgumentException;

/** Retained quality-point and attempted-credit thousandths; no float rounding at eligibility boundaries. */
final readonly class GpaTotals
{
    public function __construct(public int $qualityMilli, public int $creditsMilli)
    {
        if (
            $qualityMilli < 0 ||
            $creditsMilli < 0 ||
            ($creditsMilli === 0 && $qualityMilli !== 0) ||
            ($creditsMilli > 0 &&
                (intdiv($qualityMilli, $creditsMilli) > 10 ||
                    (intdiv($qualityMilli, $creditsMilli) === 10 && $qualityMilli % $creditsMilli !== 0)))
        ) {
            throw new InvalidArgumentException(
                "Use nonnegative GPA totals within the supported grade-point range.",
            );
        }
    }

    /** Exact floor at three decimals. No eligible GPA credits is undefined, not zero GPA. */
    public function milli(): ?int
    {
        if ($this->creditsMilli === 0) {
            return null;
        }
        $result = intdiv($this->qualityMilli, $this->creditsMilli);
        $remainder = $this->qualityMilli % $this->creditsMilli;
        for ($digit = 0; $digit < 3; $digit++) {
            $next = 0;
            $rest = 0;
            // Long division without multiplying a potentially large remainder by ten.
            for ($i = 0; $i < 10; $i++) {
                if ($rest >= $this->creditsMilli - $remainder) {
                    $rest -= $this->creditsMilli - $remainder;
                    $next++;
                } else {
                    $rest += $remainder;
                }
            }
            $result = $result * 10 + $next;
            $remainder = $rest;
        }
        return $result;
    }

    public function meetsMinimum(int $minimumMilli): ?bool
    {
        if ($minimumMilli < 0 || $minimumMilli > 10000) {
            throw new InvalidArgumentException("Use a supported minimum grade-point value.");
        }
        $actual = $this->milli();
        return $actual === null ? null : $actual >= $minimumMilli;
    }
}
