<?php

declare(strict_types=1);

namespace Domain\Support\Enum;

enum HistoryCheckSubject: string
{
    case Person = "person";
    case Student = "student";
    case Credit = "credit";
    case Account = "account";
    case Application = "application";
    case Standing = "standing";
    case Load = "load";
    case Advisors = 'advisors';
    case Requirements = 'requirements';
    case Progress = 'progress';
    case PrimaryPosition = 'primary';
    case Position = 'position';
    case TimeEntry = 'time';

    public function permissions(): array
    {
        return match ($this) {
            self::Person => ["sis:person:manage"],
            self::Student, self::Credit => ["sis:student:read"],
            self::Account => ["sis:student:read", "sis:finance:read"],
            self::Application => ["sis:admissions:manage"],
            self::Standing => ["sis:student:read", "sis:standing:manage"],
            self::Load => ["sis:student:read", "sis:load:determine"],
            self::Advisors => ['sis:student:read', 'sis:advising:manage'],
            self::Requirements => ['sis:student:read', 'sis:degree-audit:assign'],
            self::Progress => ['sis:student:read', 'sis:degree-audit:record'],
            self::PrimaryPosition, self::Position, self::TimeEntry => ['sis:hr:manage'],
        };
    }

    public function scope(): string
    {
        return match ($this) {
            self::Person => "person_profile",
            self::Student => "student_academic_inputs",
            self::Credit => "academic_credit",
            self::Account => "financial_account",
            self::Application => "admissions_application",
            self::Standing => "student_standing",
            self::Load => "enrollment_load_determination",
            self::Advisors => 'student_advisors',
            self::Requirements => 'student_degree_requirements',
            self::Progress => 'recorded_degree_progress',
            self::PrimaryPosition => 'employment_primary_position',
            self::Position => 'employment_position',
            self::TimeEntry => 'timesheet_decision',
        };
    }

    public function referenceKey(): string
    {
        return match ($this) {
            self::Standing, self::Advisors, self::Requirements => "pursuit_reference",
            self::Load => "determination_reference",
            self::Progress => 'progress_record_reference',
            self::PrimaryPosition => 'primary_position_reference',
            self::TimeEntry => 'time_entry_reference',
            default => $this->value . "_reference",
        };
    }
}
