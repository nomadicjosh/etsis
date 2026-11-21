<?php

declare(strict_types=1);

namespace Domain\Reporting\Enum;

/** Calendar periods retain their year; November in different years is never merged. */
enum ReportDateBucket: string
{
    case Day = "day";
    case Month = "month";
    case Year = "year";

    public function length(): int
    {
        return match ($this) {
            self::Day => 10,
            self::Month => 7,
            self::Year => 4,
        };
    }

    public function outputType(): string
    {
        return $this === self::Day ? "date" : "string";
    }
}
