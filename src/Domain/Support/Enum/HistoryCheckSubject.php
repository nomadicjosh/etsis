<?php

declare(strict_types=1);

namespace Domain\Support\Enum;

enum HistoryCheckSubject: string
{
    case Person = "person";
    case Student = "student";
    case Account = "account";

    public function permissions(): array
    {
        return match ($this) {
            self::Person => ["sis:person:manage"],
            self::Student => ["sis:student:read"],
            self::Account => ["sis:student:read", "sis:finance:read"],
        };
    }

    public function scope(): string
    {
        return match ($this) {
            self::Person => "person_profile",
            self::Student => "student_academic_inputs",
            self::Account => "financial_account",
        };
    }

    public function referenceKey(): string
    {
        return $this->value . "_reference";
    }
}
