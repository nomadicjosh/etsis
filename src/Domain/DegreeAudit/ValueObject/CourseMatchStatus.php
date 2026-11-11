<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

/** Per-record candidacy only; never an allocation or completion decision. */
enum CourseMatchStatus: string
{
    case Candidate = "candidate";
    case Waived = "waived";
    case ApprovalConflict = "approval_conflict";
    case Voided = "voided";
    case Dropped = "dropped";
    case InProgress = "in_progress";
    case GradeUnknown = "grade_unknown";
    case NotPassing = "not_passing";
    case NoCredit = "no_credit";
    case CourseVersionUnknown = "course_version_unknown";
    case CourseVersionDiffers = "course_version_differs";
    case ScaleUnknown = "scale_unknown";
    case ScaleDiffers = "scale_differs";
    case InsufficientGrade = "insufficient_grade";

    public function explanation(): string
    {
        return match ($this) {
            self::Candidate => "Candidate for allocation; this work has not been applied to the requirement.",
            self::Waived
            => "This course requirement is waived for this pursuit. The waiver creates no earned credit.",
            self::ApprovalConflict
            => "The course approvals conflict. Registrar review is needed before applying work.",
            self::Voided => "A voided award cannot satisfy a course requirement.",
            self::Dropped => "A dropped course cannot satisfy a completed-course requirement.",
            self::InProgress => "No final grade or earned credit is recorded yet.",
            self::GradeUnknown
            => "The retained grade definition is unknown. Do not infer it from today’s grades.",
            self::NotPassing => "The retained grade definition does not mark this as a passing grade.",
            self::NoCredit => "This record has no earned credit to apply.",
            self::CourseVersionUnknown
            => "The earned work has no retained course publication. Review its equivalence.",
            self::CourseVersionDiffers
            => "The earned work uses a different course publication. Review its equivalence.",
            self::ScaleUnknown
            => "The retained grading policy is unknown; the grade-point minimum cannot be compared.",
            self::ScaleDiffers
            => "The grading publications differ. Review the grade-point comparison; no conversion is assumed.",
            self::InsufficientGrade
            => "The retained grade points are below this group’s per-course minimum.",
        };
    }
}
