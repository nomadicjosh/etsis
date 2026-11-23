<?php

declare(strict_types=1);

namespace Domain\Support\Enum;

enum HistoryCheckSubject: string
{
    case Person = "person";
    case Student = "student";
    case Account = "account";
    case Application = "application";
    case Standing = "standing";
    case Load = "load";

    public function permissions(): array
    {
        return match ($this) {
            self::Person => ["sis:person:manage"],
            self::Student => ["sis:student:read"],
            self::Account => ["sis:student:read", "sis:finance:read"],
            self::Application => ["sis:admissions:manage"],
            self::Standing => ["sis:student:read", "sis:standing:manage"],
            self::Load => ["sis:student:read", "sis:load:determine"],
        };
    }

    public function scope(): string
    {
        return match ($this) {
            self::Person => "person_profile",
            self::Student => "student_academic_inputs",
            self::Account => "financial_account",
            self::Application => "admissions_application",
            self::Standing => "student_standing",
            self::Load => "enrollment_load_determination",
        };
    }

    public function referenceKey(): string
    {
        return match ($this) {
            self::Standing => "pursuit_reference",
            self::Load => "determination_reference",
            default => $this->value . "_reference",
        };
    }
}
