<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

use Qubus\Exception\Exception;

use function Codefy\Framework\Helpers\trans_html;

/** Per-record candidacy only; never an allocation or completion decision. */
enum CourseMatchStatus: string
{
    case ComponentExcluded = "component_excluded";
    case RequirementGradeInsufficient = 'requirement_grade_insufficient';
    case RequirementOriginExcluded = 'requirement_origin_excluded';
    case OriginExcluded = "origin_excluded";
    case OriginUnknown = "origin_unknown";
    case TransferInstitutionExcluded = 'transfer_institution_excluded';
    case TransferInstitutionUnknown = 'transfer_institution_unknown';
    case CompletionDateUnknown = "completion_date_unknown";
    case CompletedBeforeWindow = "completed_before_window";
    case CompletedBeforePursuitStart = 'completed_before_pursuit_start';
    case CompletedAfterWindow = "completed_after_window";
    case PursuitStartUnknown = 'pursuit_start_unknown';
    case EvaluationDateUnknown = "evaluation_date_unknown";
    case CompletionExpired = "completion_expired";
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

    /**
     * @throws Exception
     */
    public function explanation(): string
    {
        return match ($this) {
            self::ComponentExcluded
            => trans_html("An approved course route reserves this course for another curriculum component."),
            self::PlacementRestricted
            => trans_html("An approved placement preference reserves this course for another group in this pursuit."),
            self::RequirementGradeInsufficient => trans_html(
                "No requirement card accepts this work at its retained grade-point minimum."
            ),
            self::RequirementOriginExcluded => trans_html(
                "No requirement card in this group accepts this work's recorded origin."
            ),
            self::OriginExcluded => trans_html("This group requires a different recorded credit origin."),
            self::OriginUnknown
            => trans_html("The recorded credit origin is unavailable; this group's restriction needs review."),
            self::TransferInstitutionExcluded
            => trans_html("The recorded sending institution is not included in this group's transfer-source list."),
            self::TransferInstitutionUnknown
            => trans_html("The award has no retained sending-institution evidence; registrar review is required."),
            self::CompletionDateUnknown
            => trans_html(
                "An actual completion date is required by this group. Posting or catalog dates cannot replace it."
            ),
            self::CompletedBeforeWindow
            => trans_html("The actual completion date is before this group's permitted window."),
            self::CompletedBeforePursuitStart => trans_html(
                'The actual completion date is before the recorded pursuit start required by this group.'
            ),
            self::CompletedAfterWindow
            => trans_html("The actual completion date is after this group's permitted window."),
            self::PursuitStartUnknown
            => trans_html("The recorded pursuit start is unavailable; this group's completion rule needs review."),
            self::EvaluationDateUnknown => trans_html(
                "The review date required by this group's expiration rule is unavailable."
            ),
            self::CompletionExpired => trans_html("This work expired under the group's age limit at the review date."),
            self::CompletionTooOld
            => trans_html(
                "The actual completion date is too early for this group's age limit at the recorded pursuit start."
            ),
            self::Candidate
            => trans_html("Candidate for allocation; this work has not been applied to the requirement."),
            self::Waived
            => trans_html("This course requirement is waived for this pursuit. The waiver creates no earned credit."),
            self::ApprovalConflict
            => trans_html("The course approvals conflict. Registrar review is needed before applying work."),
            self::Voided => trans_html("A voided award cannot satisfy a course requirement."),
            self::Dropped => trans_html("A dropped course cannot satisfy a completed-course requirement."),
            self::InProgress => trans_html("No final grade or earned credit is recorded yet."),
            self::GradeUnknown
            => trans_html("The retained grade definition is unknown. Do not infer it from today’s grades."),
            self::NotPassing => trans_html("The retained grade definition does not mark this as a passing grade."),
            self::NoCredit => trans_html("This record has no earned credit to apply."),
            self::CourseVersionUnknown
            => trans_html("The earned work has no retained course publication. Review its equivalence."),
            self::CourseVersionDiffers
            => trans_html("The earned work uses a different course publication. Review its equivalence."),
            self::ScaleUnknown
            => trans_html("The retained grading policy is unknown; the grade-point minimum cannot be compared."),
            self::ScaleDiffers
            => trans_html(
                "The grading publications differ. Review the grade-point comparison; no conversion is assumed."
            ),
            self::InsufficientGrade
            => trans_html("The retained grade points are below this group’s per-course minimum."),
        };
    }
}
