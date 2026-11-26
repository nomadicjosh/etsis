<?php

declare(strict_types=1);

namespace Domain\Support\Enum;

enum HistoryCheckSubject: string
{
    case GraduatePolicy = 'gradpolicy';
    case GraduateApprovals = 'graduate';
    case Person = "person";
    case Ssn = 'ssn';
    case Student = "student";
    case Credit = "credit";
    case Waitlist = 'waitlist';
    case Course = 'course';
    case Section = 'section';
    case Program = 'program';
    case CourseGroup = 'group';
    case Curriculum = 'curriculum';
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
            self::GraduatePolicy => ['sis:registration:manage', 'sis:registration:graduate-policy:publish'],
            self::GraduateApprovals => [
                'sis:student:read', 'sis:registration:manage', 'sis:registration:graduate-override',
            ],
            self::Person => ["sis:person:manage"],
            self::Ssn => ['sis:person:manage', 'sis:person:ssn:read'],
            self::Student, self::Credit => ["sis:student:read"],
            self::Waitlist => ['sis:student:read', 'sis:registration:waitlist', 'sis:registration:manage'],
            self::Course, self::Section, self::Program, self::CourseGroup, self::Curriculum => ['sis:catalog:manage'],
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
            self::GraduatePolicy => 'graduate_registration_policy',
            self::GraduateApprovals => 'graduate_course_approvals',
            self::Person => "person_profile",
            self::Ssn => 'person_ssn',
            self::Student => "student_academic_inputs",
            self::Credit => "academic_credit",
            self::Waitlist => 'waitlist_entry',
            self::Course => 'course_publication',
            self::Section => 'section_publication',
            self::Program => 'program_publication',
            self::CourseGroup => 'group_publication',
            self::Curriculum => 'curriculum_publication',
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
            self::GraduatePolicy => 'policy_reference',
            self::GraduateApprovals => 'pursuit_reference',
            self::Standing, self::Advisors, self::Requirements => "pursuit_reference",
            self::Load => "determination_reference",
            self::Progress => 'progress_record_reference',
            self::PrimaryPosition => 'primary_position_reference',
            self::TimeEntry => 'time_entry_reference',
            self::Ssn => 'person_reference',
            default => $this->value . "_reference",
        };
    }
}
