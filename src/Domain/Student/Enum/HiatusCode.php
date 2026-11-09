<?php

declare(strict_types=1);

namespace Domain\Student\Enum;

use Domain\Shared\Exception\ValidationException;

enum HiatusCode: string
{
    case Withdrawal = "W";
    case Leave = "LOA";
    case StudyAbroad = "SA";
    case Illness = "ILLN";
    case Dismissal = "DISM";

    public static function parse(string $code): self
    {
        return self::tryFrom(strtoupper(trim($code))) ??
        throw new ValidationException([["field" => "code", "message" => "Choose a recognized hiatus type."]]);
    }

    public function label(): string
    {
        return match ($this) {
            self::Withdrawal => "Withdrawal",
            self::Leave => "Leave of absence",
            self::StudyAbroad => "Study abroad",
            self::Illness => "Illness",
            self::Dismissal => "Dismissal",
        };
    }
}
