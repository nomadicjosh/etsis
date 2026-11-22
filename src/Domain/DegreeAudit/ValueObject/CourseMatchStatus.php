<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

/** Per-record candidacy only; never an allocation or completion decision. */
enum CourseMatchStatus: string
{
    case ComponentExcluded = "component_excluded";
    case OriginExcluded = "origin_excluded";
    case OriginUnknown = "origin_unknown";
    case TransferInstitutionExcluded = 'transfer_institution_excluded';
    case TransferInstitutionUnknown = 'transfer_institution_unknown';
    case CompletionDateUnknown = "completion_date_unknown";
    case CompletedBeforeWindow = "completed_before_window";
    case CompletedAfterWindow = "completed_after_window";
    case PursuitStartUnknown = 'pursuit_start_unknown';
    case CompletionTooOld = 'completion_too_old';
    case Candidate = "candidate";
    case PlacementRestricted = "placement_restricted";
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
            self::ComponentExcluded
            => "An approved course route reserves this course for another curriculum component.",
            self::PlacementRestricted
            => "An approved placement preference reserves this course for another group in this pursuit.",
            self::OriginExcluded => "This group requires a different recorded credit origin.",
            self::OriginUnknown
            => "The recorded credit origin is unavailable; this group's restriction needs review.",
            self::TransferInstitutionExcluded
            => "The recorded sending institution is not included in this group's transfer-source list.",
            self::TransferInstitutionUnknown
            => "The award has no retained sending-institution evidence; registrar review is required.",
            self::CompletionDateUnknown
            => "An actual completion date is required by this group. Posting or catalog dates cannot replace it.",
            self::CompletedBeforeWindow
            => "The actual completion date is before this group's permitted window.",
            self::CompletedAfterWindow
            => "The actual completion date is after this group's permitted window.",
            self::PursuitStartUnknown
            => "The recorded pursuit start is unavailable; this group's course-age rule needs review.",
            self::CompletionTooOld
            => "The actual completion date is too early for this group's age limit at the recorded pursuit start.",
            self::Candidate
            => "Candidate for allocation; this work has not been applied to the requirement.",
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
