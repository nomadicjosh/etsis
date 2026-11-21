<?php

declare(strict_types=1);

namespace Infrastructure\EventSourcing;

use Domain\Gradebook\ValueObject\CourseworkGradeEvidence;
use Codefy\Domain\Aggregate\AggregateType;
use Codefy\Domain\Aggregate\AggregateId;
use Codefy\Domain\EventSourcing\AggregateChanged;
use Codefy\Domain\EventSourcing\DomainEvent;
use Codefy\Domain\EventSourcing\EventId;
use Codefy\Domain\Metadata;
use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StreamReference;
use Infrastructure\Persistence\EventClassMap;
use Qubus\Exception\Data\TypeException;

/** Explicit schema allowlist; unsupported event/version combinations fail closed. */
final readonly class HistoricalSchemaRegistry
{
    /** @return class-string<AggregateChanged> */
    private function resolve(string $class, string $type, int $version): string
    {
        if (
            $version === 1 ||
            ($version === 2 &&
                in_array(
                    $class,
                    [
                        \Domain\DegreeAudit\Event\CourseGroupWasPublished::class,
                        \Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted::class,
                        \Domain\DegreeAudit\Event\RequirementsWerePublished::class,
                    ],
                    true,
                )) ||
            (in_array($version, [3, 4], true) &&
                $class === \Domain\DegreeAudit\Event\RequirementsWerePublished::class)
        ) {
            foreach (EventClassMap::registered() as $registered) {
                if ($class === $registered && $type === AggregateType::fromClassName($registered)) {
                    return $registered;
                }
            }
        }
        throw new CorruptHistoryException();
    }

    /** @return array{type: string, root: class-string, fields: list<string>} */
    private function definition(string $class): array
    {
        return match ($class) {
            \Domain\Registration\Event\EnrollmentWasReinstated::class => [
                'type' => 'enrollment', 'root' => \Domain\Registration\EnrollmentHistory::class,
                'fields' => \Domain\Registration\ValueObject\ReinstatementEventSchema::ENROLLMENT,
            ],
            \Domain\AcademicRecord\Event\InstitutionalCreditWasReinstated::class => [
                'type' => 'academic_credit', 'root' => \Domain\AcademicRecord\AcademicCredit::class,
                'fields' => \Domain\Registration\ValueObject\ReinstatementEventSchema::ACADEMIC,
            ],
            \Domain\Registration\Waitlist\WaitlistPolicyWasSet::class => [
                'type' => 'section_waitlist',
                'root' => \Domain\Registration\Waitlist\SectionWaitlist::class,
                'fields' => \Domain\Registration\Waitlist\SectionWaitlist::POLICY_FIELDS,
            ],
            \Domain\Registration\Waitlist\WaitlistPlaceWasChanged::class => [
                'type' => 'section_waitlist',
                'root' => \Domain\Registration\Waitlist\SectionWaitlist::class,
                'fields' => \Domain\Registration\Waitlist\SectionWaitlist::PLACE_FIELDS,
            ],
            \Domain\HumanResources\Event\TimeApprovalPolicyWasPublished::class => [
                'type' => 'timesheet_policy',
                'root' => \Domain\HumanResources\TimeApprovalPolicy::class,
                'fields' => \Domain\HumanResources\TimeApprovalPolicy::FIELDS,
            ],
            \Domain\HumanResources\Event\TimeEntryWasDecided::class => [
                'type' => 'timesheet_decision',
                'root' => \Domain\HumanResources\TimeDecision::class,
                'fields' => \Domain\HumanResources\TimeDecision::FIELDS,
            ],
            \Domain\Gradebook\Event\ClassGradesWereCertified::class => [
                'type' => 'class_grade_certifications',
                'root' => \Domain\Gradebook\GradeCertificationHistory::class,
                'fields' => \Domain\Gradebook\GradeCertificationHistory::FIELDS,
            ],
            \Domain\Student\Event\EnrollmentLoadWasDetermined::class => [
                'type' => 'enrollment_load_determination', 'root' => \Domain\Student\EnrollmentLoadDetermination::class,
                'fields' => \Domain\Student\EnrollmentLoadDetermination::FIELDS,
            ],
            \Domain\Registration\Event\PrerequisitePermissionWasRecorded::class => [
                'type' => 'prerequisite_permission', 'root' => \Domain\Registration\PrerequisitePermission::class,
                'fields' => \Domain\Registration\PrerequisitePermission::FIELDS,
            ],
            \Domain\Student\Event\EnrollmentLoadPolicyWasPublished::class => [
                'type' => 'enrollment_load_policy', 'root' => \Domain\Student\EnrollmentLoadPolicy::class,
                'fields' => \Domain\Student\EnrollmentLoadPolicy::FIELDS,
            ],
            \Domain\Student\Event\StudentStandingWasCorrected::class => [
                'type' => 'student_standing', 'root' => \Domain\Student\StudentStanding::class,
                'fields' => \Domain\Student\StudentStanding::CORRECTION_FIELDS,
            ],
            \Domain\Student\Event\StudentStandingWasRecorded::class => [
                'type' => 'student_standing', 'root' => \Domain\Student\StudentStanding::class,
                'fields' => \Domain\Student\StudentStanding::FIELDS,
            ],
            \Domain\Advising\Event\StudentAdvisorsWereAssigned::class => [
                'type' => 'student_advisors', 'root' => \Domain\Advising\AdvisorHistory::class,
                'fields' => \Domain\Advising\AdvisorHistory::FIELDS,
            ],
            \Domain\Student\Event\HiatusCaseWasRecorded::class => [
                'type' => 'hiatus_case', 'root' => \Domain\Student\HiatusCase::class,
                'fields' => \Domain\Student\ValueObject\HiatusCaseSchema::FIELDS,
            ],
            \Domain\Registration\Event\SectionDropPolicyWasPublished::class => [
                'type' => 'section_drop_policy', 'root' => \Domain\Registration\DropPolicyPublication::class,
                'fields' => \Domain\Registration\DropPolicyPublication::FIELDS,
            ],
            \Domain\Registration\Event\RegistrationWindowWasPublished::class => [
                'type' => 'term_registration_window', 'root' => \Domain\Registration\RegistrationWindowHistory::class,
                'fields' => \Domain\Registration\RegistrationWindowHistory::FIELDS,
            ],
            \Domain\Registration\Event\DropPolicyWasPublished::class => [
                'type' => 'term_drop_policy', 'root' => \Domain\Registration\DropPolicyPublication::class,
                'fields' => \Domain\Registration\DropPolicyPublication::FIELDS,
            ],
            \Domain\Student\Event\RestrictionOfficeWasChanged::class => [
                'type' => 'person_restrictions', 'root' => \Domain\Student\PersonRestrictions::class,
                'fields' => ['operation_id', 'previous_operation_id', 'restriction_id',
                    'from_authority', 'to_authority', 'intent'],
            ],
            \Domain\Student\Event\PersonRestrictionsWereRecorded::class => [
                'type' => 'person_restrictions', 'root' => \Domain\Student\PersonRestrictions::class,
                'fields' => ['operation_id', 'previous_operation_id', 'action', 'facts', 'intent', 'authority'],
            ],
            \Domain\HumanResources\Event\EmploymentDatesWereRecorded::class => [
                'type' => 'employment_lifecycle', 'root' => \Domain\HumanResources\EmploymentTimeline::class,
                'fields' => ['operation_id', 'previous_operation_id', 'period_id', 'facts', 'intent'],
            ],
            \Domain\HumanResources\Event\EmployeePositionWasRecorded::class => [
                'type' => 'employment_position', 'root' => \Domain\HumanResources\PositionHistory::class,
                'fields' => ['operation_id', 'previous_operation_id', 'action', 'facts', 'intent'],
            ],
            \Domain\HumanResources\Event\EmploymentProfileWasRecorded::class => [
                'type' => 'employment_profile', 'root' => \Domain\HumanResources\EmploymentHistory::class,
                'fields' => ['operation_id', 'previous_operation_id', 'provenance', 'facts', 'intent'],
            ],
            \Domain\User\Event\UserSignInWasChanged::class => [
                'type' => 'user', 'root' => \Domain\User\User::class,
                'fields' => ['sign_in_enabled', 'token', 'operation_id', 'intent'],
            ],
            \Domain\Person\Event\PersonAddressesWereRecorded::class => [
                'type' => 'person_profile', 'root' => \Domain\Person\ProfileHistory::class,
                'fields' => ['operation_id', 'previous_operation_id', 'facts', 'intent'],
            ],
            \Domain\Person\Event\PersonEmergencyContactsWereRecorded::class => [
                'type' => 'person_profile', 'root' => \Domain\Person\ProfileHistory::class,
                'fields' => ['operation_id', 'previous_operation_id', 'facts', 'intent'],
            ],
            \Domain\Person\Event\PersonEducationWasRecorded::class => [
                'type' => 'person_profile', 'root' => \Domain\Person\ProfileHistory::class,
                'fields' => ['operation_id', 'previous_operation_id', 'facts', 'intent',
                    'action', 'entry_key', 'reason'],
            ],
            \Domain\Person\Event\PersonProfileWasRecorded::class,
            \Domain\Person\Event\PersonContactWasRecorded::class => [
                'type' => 'person_profile', 'root' => \Domain\Person\ProfileHistory::class,
                'fields' => ['operation_id', 'previous_operation_id', 'provenance', 'facts', 'intent'],
            ],
            \Domain\Student\Event\StudentSpecializationRequirementsWereAssigned::class => [
                'type' => 'student_specializations', 'root' => \Domain\Student\StudentSpecializations::class,
                'fields' => \Domain\Student\ValueObject\StudentSpecializationRequirementsSchema::FIELDS,
            ],
            \Domain\Student\Event\StudentSpecializationsWereSelected::class => [
                'type' => 'student_specializations', 'root' => \Domain\Student\StudentSpecializations::class,
                'fields' => \Domain\Student\ValueObject\StudentSpecializationSchema::FIELDS,
            ],
            \Domain\Catalog\Event\ProgramDefinitionWasPublished::class => [
                "type" => "program_publication", "root" => \Domain\Catalog\ProgramPublication::class,
                "fields" => \Domain\Catalog\ValueObject\ProgramPublicationSchema::FIELDS,
            ],
            \Domain\Registration\Event\SectionOfferingVersionWasAmended::class => [
                "type" => "section_offering_binding", "root" => \Domain\Registration\SectionOfferingBinding::class,
                "fields" => ["publication_id", "previous_publication_id", "definition_hash"],
            ],
            \Domain\Registration\Event\SectionOfferingVersionWasSelected::class => [
                "type" => "section_offering_binding", "root" => \Domain\Registration\SectionOfferingBinding::class,
                "fields" => ["publication_id", "definition_hash"],
            ],
            \Domain\Registration\Event\SectionMeetingPlanWasPublished::class => [
                'type' => 'section_meeting_plan', 'root' => \Domain\Registration\SectionMeetingPlan::class,
                'fields' => ['version_id', 'previous_version_id', 'definition'],
            ],
            \Domain\Registration\Event\SectionDefinitionWasPublished::class => [
                "type" => "section_publication",
                "root" => \Domain\Registration\SectionPublication::class,
                "fields" => ["version_id", "previous_version_id", "definition"],
            ],
            \Domain\Admissions\Event\ApplicationAnswersWereResubmitted::class => [
                'type' => 'admission_decision', 'root' => \Domain\Admissions\AdmissionDecision::class,
                'fields' => \Domain\Admissions\ValueObject\ApplicationRevisionSchema::FIELDS,
            ],
            \Domain\Admissions\Event\AdmissionEntryTermWasApproved::class => [
                'type' => 'admission_decision', 'root' => \Domain\Admissions\AdmissionDecision::class,
                'fields' => \Domain\Admissions\ValueObject\AdmissionDeferralSchema::FIELDS,
            ],
            \Domain\Admissions\Event\AdmissionOfferWasRecorded::class => [
                'type' => 'admission_decision', 'root' => \Domain\Admissions\AdmissionDecision::class,
                'fields' => \Domain\Admissions\ValueObject\AdmissionOfferSchema::FIELDS,
            ],
            \Domain\Admissions\Event\AdmissionReviewStateWasChanged::class => [
                'type' => 'admission_decision', 'root' => \Domain\Admissions\AdmissionDecision::class,
                'fields' => \Domain\Admissions\ValueObject\AdmissionReviewStateSchema::FIELDS,
            ],
            \Domain\Admissions\Event\AdmissionDecisionWasObserved::class => [
                "type" => "admission_decision",
                "root" => \Domain\Admissions\AdmissionDecision::class,
                "fields" => \Domain\Admissions\ValueObject\AdmissionCorrectionSchema::OBSERVED,
            ],
            \Domain\Admissions\Event\AdmissionDecisionWasCorrected::class => [
                "type" => "admission_decision",
                "root" => \Domain\Admissions\AdmissionDecision::class,
                "fields" => \Domain\Admissions\ValueObject\AdmissionCorrectionSchema::CORRECTED,
            ],
            \Domain\Admissions\Event\AdmissionApplicationWasSubmitted::class => [
                "type" => "admission_decision",
                "root" => \Domain\Admissions\AdmissionDecision::class,
                "fields" => \Domain\Admissions\ValueObject\ApplicationSubmissionSchema::FIELDS,
            ],
            \Domain\Admissions\Event\AdmissionApplicationWasObserved::class => [
                "type" => "admission_decision",
                "root" => \Domain\Admissions\AdmissionDecision::class,
                "fields" => \Domain\Admissions\ValueObject\AdmissionDecisionSchema::OBSERVED,
            ],
            \Domain\Admissions\Event\AdmissionWasDecided::class => [
                "type" => "admission_decision",
                "root" => \Domain\Admissions\AdmissionDecision::class,
                "fields" => \Domain\Admissions\ValueObject\AdmissionDecisionSchema::DECIDED,
            ],
            \Domain\Admissions\Event\StudentWasMatriculated::class => [
                "type" => "matriculation",
                "root" => \Domain\Admissions\Matriculation::class,
                "fields" => \Domain\Admissions\ValueObject\MatriculationSchema::FIELDS,
            ],
            \Domain\Student\Event\StudentActivityWasStarted::class => [
                "type" => "student_activity",
                "root" => \Domain\Student\StudentActivity::class,
                "fields" => [
                    "decision_id",
                    "student_id",
                    "person_id",
                    "active",
                    "effective_on",
                    "application_id",
                ],
            ],
            \Domain\Student\Event\PrimaryProgramWasEstablished::class => [
                "type" => "student_primary_program",
                "root" => \Domain\Student\StudentPrimaryProgram::class,
                "fields" => [
                    "decision_id",
                    "student_id",
                    "person_id",
                    "institutional_entry_term_id",
                    "selection",
                    "application_id",
                ],
            ],
            \Domain\Student\Event\StudentProgramWasAssigned::class => [
                "type" => "student_program_status",
                "root" => \Domain\Student\StudentProgramStatus::class,
                "fields" => ["decision_id", "assignment", "source"],
            ],
            \Domain\Student\Event\PrimaryProgramWasObserved::class => [
                "type" => "student_primary_program",
                "root" => \Domain\Student\StudentPrimaryProgram::class,
                "fields" => [
                    "decision_id",
                    "student_id",
                    "person_id",
                    "institutional_entry_term_id",
                    "selection",
                ],
            ],
            \Domain\Student\Event\PrimaryProgramWasSelected::class => [
                "type" => "student_primary_program",
                "root" => \Domain\Student\StudentPrimaryProgram::class,
                "fields" => ["decision_id", "previous_decision_id", "selection"],
            ],
            \Domain\Student\Event\StudentProgramWasObserved::class => [
                "type" => "student_program_status",
                "root" => \Domain\Student\StudentProgramStatus::class,
                "fields" => ["decision_id", "assignment"],
            ],
            \Domain\Student\Event\StudentProgramWasReplaced::class => [
                'type' => 'student_program_status', 'root' => \Domain\Student\StudentProgramStatus::class,
                'fields' => ['decision_id', 'previous_decision_id', 'status', 'replacement_assignment_id'],
            ],
            \Domain\Student\Event\StudentCohortWasChanged::class => [
                'type' => 'student_program_status', 'root' => \Domain\Student\StudentProgramStatus::class,
                'fields' => ['decision_id', 'previous_decision_id', 'previous_catalog', 'catalog',
                    'previous_binding_version', 'earlier_history_unknown'],
            ],
            \Domain\Student\Event\StudentProgramStatusWasChanged::class => [
                "type" => "student_program_status",
                "root" => \Domain\Student\StudentProgramStatus::class,
                "fields" => ["decision_id", "previous_decision_id", "status"],
            ],
            \Domain\Student\Event\StudentActivityWasObserved::class => [
                "type" => "student_activity",
                "root" => \Domain\Student\StudentActivity::class,
                "fields" => ["decision_id", "student_id", "person_id", "active", "effective_on"],
            ],
            \Domain\Student\Event\StudentActivityWasDecided::class => [
                "type" => "student_activity",
                "root" => \Domain\Student\StudentActivity::class,
                "fields" => [
                    "decision_id",
                    "previous_decision_id",
                    "student_id",
                    "active",
                    "effective_on",
                    "source",
                    "reference_id",
                ],
            ],
            \Domain\AcademicRecord\Event\AcademicWorkPursuitWasRecorded::class => [
                'type' => 'work_pursuit', 'root' => \Domain\AcademicRecord\WorkPursuit::class,
                'fields' => ['decision_id', 'previous_decision_id', 'action', 'source', 'assignment_id',
                    'academic_version', 'work_hash'],
            ],
            \Domain\Registration\Event\EnrollmentWasEstablished::class => [
                "type" => "enrollment",
                "root" => \Domain\Registration\EnrollmentHistory::class,
                "fields" => ["decision_id", "provenance", "enrollment", "source", "eligibility", "seat"],
            ],
            \Domain\Registration\Event\EnrollmentWasClosed::class => [
                "type" => "enrollment",
                "root" => \Domain\Registration\EnrollmentHistory::class,
                "fields" => ["decision_id", "previous_decision_id", "status", "status_at", "policy", "seat"],
            ],
            \Domain\Transfer\Event\TransferCreditWasEstablished::class => [
                "type" => "transfer_credit",
                "root" => \Domain\Transfer\TransferCredit::class,
                "fields" => ["decision_id", "provenance", "source", "credit", "grade"],
            ],
            \Domain\Transfer\Event\TransferCourseWasAccepted::class => [
                "type" => "transfer_credit",
                "root" => \Domain\Transfer\TransferCredit::class,
                "fields" => ["decision_id", "previous_decision_id", "previous_course_version_id", "course_version_id",
                    "course_id", "course_code", "course_title"],
            ],
            \Domain\Transfer\Event\TransferCourseWasReplaced::class => [
                "type" => "transfer_credit",
                "root" => \Domain\Transfer\TransferCredit::class,
                "fields" => [
                    "decision_id", "previous_decision_id", "previous_course_version_id", "course_version_id",
                    "previous_course_id", "course_id", "previous_course_code", "previous_course_title",
                    "course_code", "course_title",
                ],
            ],
            \Domain\Transfer\Event\TransferAcademicPeriodWasRecorded::class => [
                "type" => "transfer_credit",
                "root" => \Domain\Transfer\TransferCredit::class,
                "fields" => ["decision_id", "previous_decision_id", "previous_academic_on", "academic_on"],
            ],
            \Domain\Transfer\Event\TransferAcademicDatesWereRecorded::class => [
                "type" => "transfer_credit",
                "root" => \Domain\Transfer\TransferCredit::class,
                "fields" => [
                    "decision_id",
                    "previous_decision_id",
                    "previous_academic_on",
                    "academic_on",
                    "previous_completed_on",
                    "completed_on",
                ],
            ],
            \Domain\Transfer\Event\TransferPostingTermWasChanged::class => [
                "type" => "transfer_credit",
                "root" => \Domain\Transfer\TransferCredit::class,
                "fields" => [
                    "decision_id", "previous_decision_id", "previous_academic_on", "academic_on",
                    "previous_completed_on", "completed_on", "previous_term_id", "term_id",
                    "previous_term_code", "term_code",
                ],
            ],
            \Domain\Transfer\Event\TransferCreditWasChanged::class => [
                "type" => "transfer_credit",
                "root" => \Domain\Transfer\TransferCredit::class,
                "fields" => ["decision_id", "previous_decision_id", "action", "credits_milli", "grade"],
            ],
            \Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished::class => [
                "type" => "academic_credit",
                "root" => \Domain\AcademicRecord\AcademicCredit::class,
                "fields" => ["decision_id", "provenance", "credit", "section_id", "policies", "grade"],
            ],
            \Domain\AcademicRecord\Event\GradeChangeWasRequested::class => [
                'type' => 'academic_credit', 'root' => \Domain\AcademicRecord\AcademicCredit::class,
                'fields' => \Domain\AcademicRecord\ValueObject\GradeChangeSchema::REQUEST,
            ],
            \Domain\AcademicRecord\Event\GradeChangeWasResolved::class => [
                'type' => 'academic_credit', 'root' => \Domain\AcademicRecord\AcademicCredit::class,
                'fields' => \Domain\AcademicRecord\ValueObject\GradeChangeSchema::RESOLUTION,
            ],
            \Domain\AcademicRecord\Event\FinalGradeWasDecided::class => [
                "type" => "academic_credit",
                "root" => \Domain\AcademicRecord\AcademicCredit::class,
                "fields" => ["decision_id", "previous_decision_id", "grade", "assignment_id"],
            ],
            \Domain\AcademicRecord\Event\InstitutionalCourseWasCorrected::class => [
                'type' => 'academic_credit', 'root' => \Domain\AcademicRecord\AcademicCredit::class,
                'fields' => ['decision_id', 'previous_decision_id', 'previous_course_id', 'course_id',
                    'previous_course_version_id', 'course_version_id', 'course_code', 'course_title'],
            ],
            \Domain\AcademicRecord\Event\InstitutionalCourseWasAccepted::class => [
                'type' => 'academic_credit', 'root' => \Domain\AcademicRecord\AcademicCredit::class,
                'fields' => ['decision_id', 'previous_decision_id', 'previous_course_version_id', 'course_version_id',
                    'course_code', 'course_title'],
            ],
            \Domain\AcademicRecord\Event\InstitutionalAcademicPeriodWasRecorded::class => [
                'type' => 'academic_credit', 'root' => \Domain\AcademicRecord\AcademicCredit::class,
                'fields' => ['decision_id', 'previous_decision_id', 'previous_academic_on', 'academic_on'],
            ],
            \Domain\AcademicRecord\Event\InstitutionalPostingTermWasCorrected::class => [
                "type" => "academic_credit",
                "root" => \Domain\AcademicRecord\AcademicCredit::class,
                "fields" => [
                    "decision_id",
                    "previous_decision_id",
                    "previous_term_id",
                    "previous_term_code",
                    "term_id",
                    "term_code",
                ],
            ],
            \Domain\AcademicRecord\Event\AcademicCompletionWasRecorded::class => [
                "type" => "academic_credit",
                "root" => \Domain\AcademicRecord\AcademicCredit::class,
                "fields" => ["decision_id", "previous_decision_id", "previous_completed_on", "completed_on"],
            ],
            \Domain\AcademicRecord\Event\InstitutionalCreditWasDropped::class => [
                "type" => "academic_credit",
                "root" => \Domain\AcademicRecord\AcademicCredit::class,
                "fields" => ["decision_id", "previous_decision_id", "outcome", "grade"],
            ],
            \Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted::class => [
                "type" => "student_requirement_adjustments",
                "root" => \Domain\DegreeAudit\RequirementAdjustments::class,
                "fields" => [
                    "adjustment_id",
                    "supersedes_adjustment_id",
                    "student_id",
                    "program_id",
                    "requirement_decision_id",
                    "template_id",
                    "requirements_version_id",
                    "definition",
                ],
            ],
            \Domain\DegreeAudit\Event\RequirementAdjustmentWasRevoked::class => [
                "type" => "student_requirement_adjustments",
                "root" => \Domain\DegreeAudit\RequirementAdjustments::class,
                "fields" => ["adjustment_id", "decision_id", "student_id", "program_id"],
            ],
            \Domain\DegreeAudit\Event\StudentRequirementsWereAssigned::class => [
                "type" => "student_degree_requirements",
                "root" => \Domain\DegreeAudit\StudentRequirements::class,
                "fields" => [
                    "decision_id",
                    "previous_decision_id",
                    "student_id",
                    "program_id",
                    "template_id",
                    "requirements_version_id",
                    "academic_year_id",
                    "cohort_catalog_id",
                    "cohort_binding_revision",
                    "pursuit_started_on",
                ],
            ],
            \Domain\Gradebook\Event\GradingScaleWasChanged::class => [
                "type" => "grading_scale",
                "root" => \Domain\Gradebook\GradingScale::class,
                "fields" => ["version_id", "previous_version_id", "definition"],
            ],
            \Domain\DegreeAudit\Event\CourseGroupWasPublished::class => [
                "type" => "course_group_publication",
                "root" => \Domain\DegreeAudit\CourseGroupPublication::class,
                "fields" => ["version_id", "previous_version_id", "definition"],
            ],
            \Domain\DegreeAudit\Event\RequirementsWerePublished::class => [
                "type" => "degree_audit_requirements",
                "root" => \Domain\DegreeAudit\RequirementsPublication::class,
                "fields" => [
                    "program_id",
                    "academic_year_id",
                    "version_id",
                    "previous_version_id",
                    "labels",
                    "definition",
                ],
            ],
            \Domain\Registration\Event\SectionCourseVersionWasBound::class => [
                "type" => "section_course_binding",
                "root" => \Domain\Registration\SectionCourseBinding::class,
                "fields" => ["course_id", "course_version_id"],
            ],
            \Domain\Catalog\Event\CourseDefinitionWasPublished::class => [
                "type" => "course_publication",
                "root" => \Domain\Catalog\CoursePublication::class,
                "fields" => ["version_id", "previous_version_id", "definition"],
            ],
            \Domain\Finance\Event\StudentAccountWasOpened::class => [
                "type" => "student_account",
                "root" => \Domain\Finance\StudentAccount::class,
                "fields" => ["student_id", "term_id", "currency"],
            ],
            \Domain\Finance\Event\AccountEntryWasPosted::class => [
                "type" => "student_account",
                "root" => \Domain\Finance\StudentAccount::class,
                "fields" => ["operation_id", "actor_id", "type", "minor", "currency"],
            ],
            \Domain\Finance\Event\AccountPaymentDetailsWereChanged::class => [
                'type' => 'student_account', 'root' => \Domain\Finance\StudentAccount::class,
                'fields' => ['operation_id', 'entry_operation_id', 'receipt_operation_id',
                    'previous_details', 'details', 'actor_id', 'review_hash'],
            ],
            \Domain\Finance\Event\AccountEntryWasReversed::class => [
                "type" => "student_account",
                "root" => \Domain\Finance\StudentAccount::class,
                "fields" => ["operation_id", "actor_id"],
            ],
            \Domain\Finance\Event\AccountChargeWasCredited::class => [
                'type' => 'student_account', 'root' => \Domain\Finance\StudentAccount::class,
                'fields' => ['source_operation_id', 'operation_id', 'minor', 'currency', 'actor_id', 'review_hash'],
            ],
            \Domain\Finance\Event\AccountEntryWasCorrected::class => [
                'type' => 'student_account', 'root' => \Domain\Finance\StudentAccount::class,
                'fields' => ['source_operation_id', 'operation_id', 'minor', 'currency', 'actor_id', 'review_hash'],
            ],
            \Domain\Finance\Event\BillingRuleWasDefined::class => [
                "type" => "billing_rule",
                "root" => \Domain\Finance\BillingRule::class,
                "fields" => ["term_id", "program_id", "name", "kind", "minor", "currency", "actor_id"],
            ],
            \Domain\Finance\Event\BillingRuleWasRetired::class => [
                "type" => "billing_rule",
                "root" => \Domain\Finance\BillingRule::class,
                "fields" => ["actor_id"],
            ],
            \Domain\Finance\Event\JournalWasPosted::class => [
                "type" => "journal",
                "root" => \Domain\Finance\Journal::class,
                "fields" => ["reference", "posted_on", "currency", "lines", "actor_id"],
            ],
            \Domain\Finance\Event\JournalWasReversed::class => [
                "type" => "journal",
                "root" => \Domain\Finance\Journal::class,
                "fields" => ["actor_id"],
            ],
            \Domain\User\Event\UserWasCreated::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => [
                    "user_id",
                    "person_id",
                    "username",
                    "token",
                    "first_name",
                    "middle_name",
                    "last_name",
                    "email",
                    "role",
                    "password",
                    "created_on",
                ],
            ],
            \Domain\User\Event\UserWasDeleted::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => [],
            ],
            \Domain\User\Event\EmailAddressWasChanged::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => ["email"],
            ],
            \Domain\User\Event\NameWasChanged::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => ["first_name", "middle_name", "last_name"],
            ],
            \Domain\User\Event\PasswordWasChanged::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => ["password", "token"],
            ],
            \Domain\User\Event\RoleWasChanged::class => [
                "type" => "user",
                "root" => \Domain\User\User::class,
                "fields" => ["role"],
            ],
            default => throw new CorruptHistoryException(),
        };
    }

    /** Frozen serialization checks; never call current business commands or publish during replay.
     * @param array<mixed> $payload
     * @param array<mixed> $metadata
     */
    private function validate(
        StreamReference $stream,
        string $class,
        #[\SensitiveParameter] array $payload,
        #[\SensitiveParameter] array $metadata,
        int $version,
    ): void {
        try {
            $definition = $this->definition($class);
            if (
                in_array($class, [\Domain\Student\Event\StudentStandingWasRecorded::class,
                \Domain\Student\Event\StudentStandingWasCorrected::class], true)
            ) {
                \Domain\Student\StudentStanding::validate(
                    $payload,
                    $class === \Domain\Student\Event\StudentStandingWasCorrected::class,
                );
                if ($payload['pursuit_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\Registration\Event\PrerequisitePermissionWasRecorded::class) {
                \Domain\Registration\PrerequisitePermission::validate($payload);
            }
            if (
                in_array($class, [
                    \Domain\Registration\Waitlist\WaitlistPolicyWasSet::class,
                    \Domain\Registration\Waitlist\WaitlistPlaceWasChanged::class,
                ], true)
            ) {
                \Domain\Registration\Waitlist\SectionWaitlist::validate(
                    $payload,
                    $class === \Domain\Registration\Waitlist\WaitlistPolicyWasSet::class,
                );
            }
            if ($class === \Domain\HumanResources\Event\TimeApprovalPolicyWasPublished::class) {
                \Domain\HumanResources\TimeApprovalPolicy::validate($payload);
                if ((string) $stream->aggregateId !== \Domain\HumanResources\TimeApprovalPolicy::ID) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\HumanResources\Event\TimeEntryWasDecided::class) {
                \Domain\HumanResources\TimeDecision::validate($payload);
                if ($payload['entry_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\Gradebook\Event\ClassGradesWereCertified::class) {
                \Domain\Gradebook\GradeCertificationHistory::validate($payload);
                if ($payload['section_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\Student\Event\EnrollmentLoadWasDetermined::class) {
                \Domain\Student\EnrollmentLoadDetermination::validate($payload);
                if ($payload['operation_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\Student\Event\EnrollmentLoadPolicyWasPublished::class) {
                \Domain\Student\EnrollmentLoadPolicy::validate($payload);
                if (
                    ($payload['program_id'] ?? \Domain\Student\ValueObject\EnrollmentLoadPolicyId::GLOBAL)
                        !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\Advising\Event\StudentAdvisorsWereAssigned::class) {
                \Domain\Advising\AdvisorHistory::validate($payload);
                if ($payload['pursuit_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\Registration\Event\RegistrationWindowWasPublished::class) {
                \Domain\Registration\RegistrationWindowHistory::validate($payload);
                if ($payload['definition']['term_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\Student\Event\HiatusCaseWasRecorded::class) {
                \Domain\Student\ValueObject\HiatusCaseSchema::validate($payload);
                if ($payload['facts']['id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                in_array($class, [\Domain\Registration\Event\DropPolicyWasPublished::class,
                \Domain\Registration\Event\SectionDropPolicyWasPublished::class], true)
            ) {
                \Domain\Registration\DropPolicyPublication::validate($payload);
                $section = $class === \Domain\Registration\Event\SectionDropPolicyWasPublished::class;
                if (
                    isset($payload['definition']['section_id']) !== $section ||
                    $payload['definition'][$section ? 'section_id' : 'term_id'] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if (
                in_array($class, [\Domain\Finance\Event\AccountEntryWasCorrected::class,
                    \Domain\Finance\Event\AccountChargeWasCredited::class,
                    \Domain\Finance\Event\AccountPaymentDetailsWereChanged::class], true)
                && (!is_string($payload['review_hash'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $payload['review_hash']) !== 1)
            ) {
                throw new CorruptHistoryException();
            }
            if ($class === \Domain\Finance\Event\AccountPaymentDetailsWereChanged::class) {
                foreach (['previous_details', 'details'] as $field) {
                    $value = $payload[$field] ?? null;
                    if ($field === 'previous_details' && $value === null) {
                        continue;
                    }
                    if (!is_array($value)) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\Finance\ValueObject\PaymentDetails::fromRecorded($value);
                }
            }
            if ($class === \Domain\Student\Event\RestrictionOfficeWasChanged::class) {
                \Domain\Student\PersonRestrictions::validateHandoff($payload);
            }
            if ($class === \Domain\Student\Event\PersonRestrictionsWereRecorded::class) {
                \Domain\Student\PersonRestrictions::validate($payload);
                foreach ($payload['facts'] as $facts) {
                    if ($facts['person_id'] !== (string) $stream->aggregateId) {
                        throw new CorruptHistoryException();
                    }
                }
            }
            if ($class === \Domain\HumanResources\Event\EmploymentDatesWereRecorded::class) {
                \Domain\HumanResources\EmploymentTimeline::validate($payload);
                if ($payload['facts']['employee_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\HumanResources\Event\EmployeePositionWasRecorded::class) {
                \Domain\HumanResources\PositionHistory::validate($payload);
                if ($payload['facts']['id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\HumanResources\Event\EmploymentProfileWasRecorded::class) {
                \Domain\HumanResources\ValueObject\EmploymentFacts::validate($payload);
                if ($payload['facts']['id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\Person\Event\PersonAddressesWereRecorded::class) {
                \Domain\Person\ValueObject\AddressBook::validateEvent($payload);
            }
            if ($class === \Domain\Person\Event\PersonEmergencyContactsWereRecorded::class) {
                \Domain\Person\ValueObject\EmergencyContacts::validateEvent($payload);
            }
            if ($class === \Domain\Person\Event\PersonEducationWasRecorded::class) {
                \Domain\Person\ValueObject\EducationBackground::validateEvent($payload);
            }
            if ($class === \Domain\Person\Event\PersonContactWasRecorded::class) {
                \Domain\Person\ValueObject\ContactDetails::validateEvent($payload);
            }
            if ($class === \Domain\Person\Event\PersonProfileWasRecorded::class) {
                \Domain\Person\ValueObject\ProfileFacts::validate($payload);
                if ($payload['facts']['id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\Catalog\Event\ProgramDefinitionWasPublished::class) {
                \Domain\Catalog\ValueObject\ProgramPublicationSchema::validate($payload);
                if ($payload['specializations']['program_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($class === \Domain\DegreeAudit\Event\RequirementsWerePublished::class && $version === 4) {
                $definition["fields"][] = "grade_scale";
            }
            if ($stream->type === "admission_decision") {
                if ($class === \Domain\Admissions\Event\ApplicationAnswersWereResubmitted::class) {
                    \Domain\Admissions\ValueObject\ApplicationRevisionSchema::validate($payload);
                } elseif ($class === \Domain\Admissions\Event\AdmissionEntryTermWasApproved::class) {
                    \Domain\Admissions\ValueObject\AdmissionDeferralSchema::validate($payload);
                } elseif ($class === \Domain\Admissions\Event\AdmissionOfferWasRecorded::class) {
                    \Domain\Admissions\ValueObject\AdmissionOfferSchema::validate($payload);
                } elseif ($class === \Domain\Admissions\Event\AdmissionReviewStateWasChanged::class) {
                    \Domain\Admissions\ValueObject\AdmissionReviewStateSchema::validate($payload);
                } elseif ($class === \Domain\Admissions\Event\AdmissionApplicationWasSubmitted::class) {
                    \Domain\Admissions\ValueObject\ApplicationSubmissionSchema::validate($payload);
                } elseif (
                    in_array(
                        $class,
                        [
                            \Domain\Admissions\Event\AdmissionDecisionWasObserved::class,
                            \Domain\Admissions\Event\AdmissionDecisionWasCorrected::class,
                        ],
                        true,
                    )
                ) {
                    \Domain\Admissions\ValueObject\AdmissionCorrectionSchema::validate($class, $payload);
                } else {
                    \Domain\Admissions\ValueObject\AdmissionDecisionSchema::validate($class, $payload);
                }
                if (
                    in_array(
                        $class,
                        [
                            \Domain\Admissions\Event\AdmissionApplicationWasObserved::class,
                            \Domain\Admissions\Event\AdmissionDecisionWasObserved::class,
                            \Domain\Admissions\Event\AdmissionApplicationWasSubmitted::class,
                        ],
                        true,
                    ) &&
                    $payload["application"]["id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "matriculation") {
                \Domain\Admissions\ValueObject\MatriculationSchema::validate($class, $payload);
                if ($payload["application_id"] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "student_primary_program") {
                \Domain\Student\ValueObject\PrimaryProgramSchema::validate($class, $payload);
                if (
                    in_array(
                        $class,
                        [
                            \Domain\Student\Event\PrimaryProgramWasObserved::class,
                            \Domain\Student\Event\PrimaryProgramWasEstablished::class,
                        ],
                        true,
                    ) &&
                    $payload["student_id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === 'student_specializations') {
                if ($class === \Domain\Student\Event\StudentSpecializationRequirementsWereAssigned::class) {
                    \Domain\Student\ValueObject\StudentSpecializationRequirementsSchema::read($payload);
                } else {
                    \Domain\Student\ValueObject\StudentSpecializationSchema::read($payload);
                }
                if ($payload['assignment_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "student_program_status") {
                \Domain\Student\ValueObject\StudentProgramStatusSchema::validate($class, $payload);
                if (
                    in_array(
                        $class,
                        [
                            \Domain\Student\Event\StudentProgramWasObserved::class,
                            \Domain\Student\Event\StudentProgramWasAssigned::class,
                        ],
                        true,
                    ) &&
                    $payload["assignment"]["id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "student_activity") {
                \Domain\Student\ValueObject\StudentActivitySchema::validate($class, $payload);
                if ($payload["student_id"] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === 'work_pursuit') {
                \Domain\AcademicRecord\ValueObject\WorkPursuitSchema::validate($payload);
                if ($payload['source']['credit_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "enrollment") {
                \Domain\Registration\ValueObject\EnrollmentEventSchema::validate($class, $payload);
                if (
                    $class === \Domain\Registration\Event\EnrollmentWasEstablished::class &&
                    $payload["enrollment"]["id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "transfer_credit") {
                \Domain\Transfer\ValueObject\TransferEventSchema::validate($class, $payload);
                if (
                    $class === \Domain\Transfer\Event\TransferCreditWasEstablished::class &&
                    $payload["source"]["award_id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            if ($stream->type === "academic_credit") {
                \Domain\AcademicRecord\ValueObject\AcademicEventSchema::validate($class, $payload);
                if (
                    $class === \Domain\AcademicRecord\Event\InstitutionalCreditWasEstablished::class &&
                    $payload["credit"]["id"] !== (string) $stream->aggregateId
                ) {
                    throw new CorruptHistoryException();
                }
            }
            // Earliest User v1 events predate the optional person link.
            if (
                $class === \Domain\User\Event\UserWasCreated::class &&
                !array_key_exists("person_id", $payload)
            ) {
                $payload["person_id"] = null;
            }
            if (
                $definition["type"] !== $stream->type ||
                $definition["root"] !== $stream->aggregateId->aggregateClassName() ||
                count($payload) !== count($definition["fields"]) ||
                array_diff(array_keys($payload), $definition["fields"]) !== []
            ) {
                throw new CorruptHistoryException();
            }
            if ($class === \Domain\Gradebook\Event\GradingScaleWasChanged::class) {
                if (
                    (string) $stream->aggregateId ===
                        \Domain\Gradebook\Service\GradingScaleService::GLOBAL_ID &&
                    $payload["definition"] === null
                ) {
                    throw new CorruptHistoryException();
                }
                if ($payload["version_id"] === $payload["previous_version_id"]) {
                    throw new CorruptHistoryException();
                }
                if ($payload["definition"] !== null) {
                    if (!is_array($payload["definition"])) {
                        throw new CorruptHistoryException();
                    }
                    $scale = new \Domain\Gradebook\ValueObject\GradingScaleDefinition($payload["definition"]);
                    if ($scale->definition["grades"] === []) {
                        throw new CorruptHistoryException();
                    }
                }
            }
            if ($class === \Domain\DegreeAudit\Event\RequirementAdjustmentWasGranted::class) {
                if (
                    !is_array($payload["definition"]) ||
                    !is_string($payload["program_id"]) ||
                    $payload["adjustment_id"] === $payload["supersedes_adjustment_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                if ($version === 1) {
                    new \Domain\DegreeAudit\ValueObject\RequirementAdjustmentDefinition(
                        $payload["definition"],
                    );
                } else {
                    new \Domain\DegreeAudit\ValueObject\RequirementAdjustmentDefinitionV2(
                        $payload["definition"],
                    );
                }
            }
            if (
                $class === \Domain\DegreeAudit\Event\RequirementAdjustmentWasRevoked::class &&
                (!is_string($payload["program_id"]) || $payload["decision_id"] === $payload["adjustment_id"])
            ) {
                throw new CorruptHistoryException();
            }
            if ($class === \Domain\DegreeAudit\Event\StudentRequirementsWereAssigned::class) {
                if (
                    !is_string($payload["program_id"]) ||
                    !is_int($payload["cohort_binding_revision"]) ||
                    $payload["cohort_binding_revision"] < 0 ||
                    !is_string($payload["pursuit_started_on"]) ||
                    $payload["decision_id"] === $payload["previous_decision_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                new \Domain\DegreeAudit\ValueObject\PursuitStart($payload["pursuit_started_on"]);
            }
            if ($class === \Domain\DegreeAudit\Event\CourseGroupWasPublished::class) {
                if (
                    !is_array($payload["definition"]) ||
                    $payload["version_id"] === $payload["previous_version_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                $group =
                $version === 1
                ? new \Domain\DegreeAudit\ValueObject\CourseGroupDefinition($payload["definition"])
                : new \Domain\DegreeAudit\ValueObject\CourseGroupDefinitionV2($payload["definition"]);
                $group->assertPublishable();
            }
            if ($class === \Domain\DegreeAudit\Event\RequirementsWerePublished::class) {
                if (
                    !is_string($payload["program_id"]) ||
                    !is_array($payload["definition"]) ||
                    !is_array($payload["labels"]) ||
                    count($payload["labels"]) !== 3 ||
                    array_diff(array_keys($payload["labels"]), [
                        "program_code",
                        "program_title",
                        "academic_year_code",
                    ]) !== [] ||
                    $payload["version_id"] === $payload["previous_version_id"]
                ) {
                    throw new CorruptHistoryException();
                }
                foreach ($payload["labels"] as $label) {
                    if (
                        !is_string($label) ||
                        trim($label) === "" ||
                        mb_strlen($label) > 191 ||
                        !mb_check_encoding($label, "UTF-8") ||
                        preg_match('/[\x00-\x1F\x7F]/', $label)
                    ) {
                        throw new CorruptHistoryException();
                    }
                }
                $requirements = match ($version) {
                    1 => new \Domain\DegreeAudit\ValueObject\AuditRequirements($payload["definition"]),
                    2 => new \Domain\DegreeAudit\ValueObject\AuditRequirementsV2($payload["definition"]),
                    3, 4 => new \Domain\DegreeAudit\ValueObject\AuditRequirementsV3($payload["definition"]),
                    default => throw new CorruptHistoryException(),
                };
                $requirements->assertPublishable();
                if ($version === 4) {
                    if (!is_array($payload["grade_scale"])) {
                        throw new CorruptHistoryException();
                    }
                    $snapshot = new \Domain\Gradebook\ValueObject\GradingScaleSnapshot(
                        $payload["grade_scale"],
                    );
                    if (
                        $snapshot->data["program_id"] !== null &&
                        $snapshot->data["program_id"] !== $payload["program_id"]
                    ) {
                        throw new CorruptHistoryException();
                    }
                }
            }
            foreach ($payload as $name => $value) {
                // This complete payload is validated by its bounded typed schema above.
                if (
                    in_array($class, [
                    \Domain\Registration\Event\EnrollmentWasReinstated::class,
                    \Domain\AcademicRecord\Event\InstitutionalCreditWasReinstated::class,
                    \Domain\Registration\Waitlist\WaitlistPolicyWasSet::class,
                    \Domain\Registration\Waitlist\WaitlistPlaceWasChanged::class,
                    \Domain\HumanResources\Event\TimeApprovalPolicyWasPublished::class,
                    \Domain\HumanResources\Event\TimeEntryWasDecided::class,
                    \Domain\Gradebook\Event\ClassGradesWereCertified::class,
                    \Domain\Student\Event\EnrollmentLoadWasDetermined::class,
                    \Domain\Registration\Event\PrerequisitePermissionWasRecorded::class,
                    \Domain\Student\Event\EnrollmentLoadPolicyWasPublished::class,
                    \Domain\Advising\Event\StudentAdvisorsWereAssigned::class,
                    \Domain\Student\Event\StudentStandingWasRecorded::class,
                    \Domain\Student\Event\StudentStandingWasCorrected::class], true)
                ) {
                    continue;
                }
                if (str_ends_with((string) $name, "_id")) {
                    if (
                        $class === \Domain\Student\Event\HiatusCaseWasRecorded::class
                        && in_array($name, ['previous_operation_id', 'return_operation_id'], true) && $value === null
                    ) {
                        continue;
                    }
                    if (
                        in_array($class, [\Domain\Registration\Event\DropPolicyWasPublished::class,
                            \Domain\Registration\Event\SectionDropPolicyWasPublished::class,
                            \Domain\Registration\Event\RegistrationWindowWasPublished::class], true)
                        && $name === 'previous_publication_id' && $value === null
                    ) {
                        continue;
                    }
                    if (
                        in_array($class, [\Domain\Person\Event\PersonProfileWasRecorded::class,
                            \Domain\Student\Event\PersonRestrictionsWereRecorded::class,
                            \Domain\HumanResources\Event\EmploymentDatesWereRecorded::class,
                            \Domain\HumanResources\Event\EmployeePositionWasRecorded::class,
                            \Domain\HumanResources\Event\EmploymentProfileWasRecorded::class,
                            \Domain\Admissions\Event\AdmissionOfferWasRecorded::class], true) &&
                        $name === 'previous_operation_id' && $value === null
                    ) {
                        continue;
                    }
                    if (
                        in_array($class, [\Domain\Transfer\Event\TransferCourseWasAccepted::class,
                            \Domain\Transfer\Event\TransferCourseWasReplaced::class,
                            \Domain\AcademicRecord\Event\InstitutionalCourseWasAccepted::class,
                            \Domain\AcademicRecord\Event\InstitutionalCourseWasCorrected::class], true) &&
                        $name === "previous_course_version_id" && $value === null
                    ) {
                        continue;
                    }
                    if (
                        $class === \Domain\Student\Event\StudentActivityWasDecided::class &&
                        $name === "reference_id" &&
                        $value === null
                    ) {
                        continue;
                    }
                    if (
                        in_array($class, [\Domain\AcademicRecord\Event\FinalGradeWasDecided::class,
                            \Domain\AcademicRecord\Event\GradeChangeWasRequested::class,
                            \Domain\AcademicRecord\Event\GradeChangeWasResolved::class], true) &&
                        $name === "assignment_id" &&
                        $value === null
                    ) {
                        continue;
                    }
                    if (
                        in_array(
                            $name,
                            [
                                "program_id",
                                "person_id",
                                "previous_version_id",
                                "previous_catalog_id",
                                "previous_decision_id",
                                "supersedes_adjustment_id",
                            ],
                            true,
                        ) &&
                        $value === null
                    ) {
                        continue;
                    }
                    if (!is_string($value)) {
                        throw new CorruptHistoryException();
                    }
                    new \Domain\Shared\ValueObject\EntityId($value);
                }
            }
            foreach ($metadata as $name => $value) {
                if ($name === 'coursework') {
                    if (
                        $class !== \Domain\AcademicRecord\Event\FinalGradeWasDecided::class || !is_array($value)
                        || $payload['grade']['scale_version_id'] === null
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $evidence = new CourseworkGradeEvidence($value);
                    if ($evidence->facts['grade_id'] !== $payload['grade']['id']) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'withdrawal_grade_correction') {
                    if (
                        $value !== true || !in_array($class, [
                            \Domain\AcademicRecord\Event\FinalGradeWasDecided::class,
                            \Domain\AcademicRecord\Event\GradeChangeWasRequested::class,
                        ], true)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'withdrawal_quantity_reconciliation') {
                    if (
                        $value !== true || ($metadata['withdrawal_grade_correction'] ?? false) !== true ||
                        !in_array($class, [
                            \Domain\AcademicRecord\Event\FinalGradeWasDecided::class,
                            \Domain\AcademicRecord\Event\GradeChangeWasRequested::class,
                        ], true)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'credit_unit_correction') {
                    if (
                        !is_array($value) || !in_array($class, [
                        \Domain\AcademicRecord\Event\FinalGradeWasDecided::class,
                        \Domain\AcademicRecord\Event\GradeChangeWasRequested::class,
                        ], true) || isset($metadata['coursework'])
                    ) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\AcademicRecord\ValueObject\CreditUnitCorrection::fromArray($value);
                    continue;
                }
                if ($name === 'grade_change_policy') {
                    if ($class !== \Domain\AcademicRecord\Event\FinalGradeWasDecided::class || !is_array($value)) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\AcademicRecord\ValueObject\GradeChangeSchema::policy($value);
                    if ($value['mode'] !== 'direct') {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }

                if ($name === 'offering_classifications') {
                    if (
                        $class !== \Domain\Registration\Event\SectionDefinitionWasPublished::class || !is_array($value)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\Registration\ValueObject\SectionClassifications::fromArray($value);
                    continue;
                }
                if ($name === 'drop_policy') {
                    if ($class !== \Domain\AcademicRecord\Event\InstitutionalCreditWasDropped::class) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\Registration\ValueObject\DropPolicyReference::validate(
                        $value,
                        $payload['outcome']['policy_version'],
                    );
                    continue;
                }
                if ($name === 'admission_checklist') {
                    if (
                        !in_array($class, [\Domain\Admissions\Event\AdmissionWasDecided::class,
                        \Domain\Admissions\Event\AdmissionDecisionWasCorrected::class], true)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\Admissions\ValueObject\AdmissionChecklistProof::validate($value, $payload['status']);
                    continue;
                }
                if ($name === 'admission_withdrawal') {
                    if ($class !== \Domain\Admissions\Event\AdmissionReviewStateWasChanged::class) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\Admissions\ValueObject\AdmissionWithdrawalFact::validate($value, $payload);
                    continue;
                }
                if ($name === 'admission_offer') {
                    if (
                        $class !== \Domain\Admissions\Event\StudentWasMatriculated::class || !is_array($value)
                        || count($value) !== 4 || array_diff(array_keys($value), ['offer_id', 'operation_id',
                        'require_acceptance', 'policy_revision']) !== []
                        || !is_bool($value['require_acceptance'] ?? null)
                        || !is_int($value['policy_revision'] ?? null) || $value['policy_revision'] < 0
                    ) {
                        throw new CorruptHistoryException();
                    }
                    foreach (['offer_id', 'operation_id'] as $key) {
                        if ($value[$key] !== null) {
                            if (!is_string($value[$key])) {
                                throw new CorruptHistoryException();
                            }
                            new \Domain\Shared\ValueObject\EntityId($value[$key]);
                        }
                    }
                    if (
                        ($value['offer_id'] === null) !== ($value['operation_id'] === null)
                        || ($value['require_acceptance'] && $value['offer_id'] === null)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'admission_evidence') {
                    if (
                        !in_array($class, [\Domain\Admissions\Event\AdmissionWasDecided::class,
                        \Domain\Admissions\Event\AdmissionDecisionWasCorrected::class], true)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\Admissions\ValueObject\AdmissionEvidence::validate($value);
                    continue;
                }
                if ($name === 'pricing_basis') {
                    if (
                        $class !== \Domain\Finance\Event\BillingRuleWasDefined::class
                        || !is_string($value) || \Domain\Finance\Enum\BillingBasis::tryFrom($value) === null
                    ) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'faculty_assessment') {
                    if (
                        $class !== \Domain\Registration\Event\SectionMeetingPlanWasPublished::class
                        || !is_array($value)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $assessment = new \Domain\Registration\ValueObject\FacultyConflictAssessment($value);
                    $meetings = $payload['definition']['meetings'];
                    if (
                        $assessment->blocked() || $value['unassigned_meetings'] > count($meetings)
                        || in_array(
                            $payload['definition']['section_id'],
                            array_column($value['sources'], 'section_id'),
                            true
                        )
                    ) {
                        throw new CorruptHistoryException();
                    }
                    foreach ($value['conflicts'] as $conflict) {
                        if (!in_array($conflict['own_occurrence_id'], array_column($meetings, 'occurrence_id'), true)) {
                            throw new CorruptHistoryException();
                        }
                    }
                    continue;
                }
                if ($name === 'charge_source') {
                    if ($class !== \Domain\Finance\Event\AccountEntryWasPosted::class || !is_string($value)) {
                        throw new CorruptHistoryException();
                    }
                    $source = \Domain\Finance\Enum\ChargeSource::tryFrom($value);
                    if ($source === null) {
                        throw new CorruptHistoryException();
                    }
                    $source->validate(
                        $payload['type'],
                        ($metadata['staff_recorded'] ?? false) === true,
                        isset($metadata['registration_price'])
                    );
                    continue;
                }
                if ($name === 'registration_price') {
                    if (
                        $class !== \Domain\Finance\Event\AccountEntryWasPosted::class || !is_array($value)
                        || $payload['type'] !== 'charge' || ($metadata['staff_recorded'] ?? false)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $price = \Domain\Finance\ValueObject\RegistrationPrice::fromRecorded($value);
                    if (
                        $price->values() != $value || $price->amount->minor < 1
                        || $price->amount->minor !== $payload['minor']
                        || $price->amount->currency !== $payload['currency']
                    ) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'refund_allocations') {
                    if (
                        !is_array($value) || !in_array($class, [
                        \Domain\Finance\Event\AccountEntryWasPosted::class,
                        \Domain\Finance\Event\AccountEntryWasCorrected::class,
                        ], true) || ($class === \Domain\Finance\Event\AccountEntryWasPosted::class
                        && ($payload['type'] !== 'refund' || ($metadata['staff_recorded'] ?? false) !== true))
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $allocation = new \Domain\Finance\ValueObject\RefundAllocation($value);
                    if ($allocation->values() != $value || $allocation->totalMinor() !== $payload['minor']) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'reversed_credit_ids') {
                    if (
                        $class !== \Domain\Finance\Event\AccountEntryWasReversed::class
                        || !is_array($value) || !array_is_list($value) || $value === [] || count($value) > 1999
                    ) {
                        throw new CorruptHistoryException();
                    }
                    foreach ($value as $credit) {
                        if (!is_string($credit)) {
                            throw new CorruptHistoryException();
                        }
                        new \Domain\Shared\ValueObject\EntityId($credit);
                    }
                    if (count(array_unique($value)) !== count($value)) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'payment_details') {
                    if (
                        !is_array($value) || !in_array($class, [
                        \Domain\Finance\Event\AccountEntryWasPosted::class,
                        \Domain\Finance\Event\AccountEntryWasCorrected::class,
                        ], true)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $details = \Domain\Finance\ValueObject\PaymentDetails::fromRecorded($value);
                    if (
                        $class === \Domain\Finance\Event\AccountEntryWasPosted::class
                        && (($metadata['staff_recorded'] ?? false) !== true
                            || !in_array($payload['type'], ['payment', 'refund'], true) || $details->isEmpty())
                    ) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'review_hash') {
                    if (
                        !in_array($class, [\Domain\Finance\Event\AccountEntryWasPosted::class,
                            \Domain\Finance\Event\AccountEntryWasReversed::class], true)
                        || ($class === \Domain\Finance\Event\AccountEntryWasPosted::class
                            && ($metadata['staff_recorded'] ?? false) !== true) || !is_string($value)
                        || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1
                    ) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'staff_recorded') {
                    if ($class !== \Domain\Finance\Event\AccountEntryWasPosted::class || $value !== true) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === "schema_version" && $value === $version) {
                    continue;
                }
                if ($name === 'reinstatement_evidence') {
                    if (
                        $class !== \Domain\Registration\Event\EnrollmentWasReinstated::class ||
                        !is_array($value)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\Registration\ValueObject\ReinstatementEventSchema::evidence($value, $payload);
                    continue;
                }
                if ($name === 'corequisite_evidence') {
                    if (
                        $class !== \Domain\Registration\Event\EnrollmentWasEstablished::class ||
                        $payload['provenance'] !== 'registered' || !is_array($value)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\Registration\ValueObject\CoRequisiteDecision::fromRetained($value);
                    if (
                        $value['student_id'] !== $payload['enrollment']['student_id'] ||
                        $value['term_id'] !== $payload['enrollment']['term_id'] ||
                        $value['assignment_id'] !== ($metadata['prerequisite_evidence']['assignment_id'] ?? null)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'corequisite_drop_evidence') {
                    if ($class !== \Domain\Registration\Event\EnrollmentWasClosed::class || !is_array($value)) {
                        throw new CorruptHistoryException();
                    }
                    if ((new \Domain\Registration\ValueObject\CoRequisiteDropDecision($value))->blocked()) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'registration_window') {
                    if (
                        $class !== \Domain\Registration\Event\EnrollmentWasEstablished::class ||
                        $payload['provenance'] !== 'registered'
                    ) {
                        throw new CorruptHistoryException();
                    }
                    \Domain\Registration\ValueObject\RegistrationWindowDefinition::reference($value);
                    if ($value['term_id'] !== $payload['enrollment']['term_id']) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'schedule_evidence') {
                    if (
                        $class !== \Domain\Registration\Event\EnrollmentWasEstablished::class
                        || $payload['provenance'] !== 'registered' || !is_array($value)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $decision = new \Domain\Registration\ValueObject\RegistrationScheduleDecision($value);
                    if (
                        $decision->blocked() || $value['student_id'] !== $payload['enrollment']['student_id']
                        || $value['target_section_id'] !== $payload['enrollment']['section_id']
                    ) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'prerequisite_evidence') {
                    if (
                        $class !== \Domain\Registration\Event\EnrollmentWasEstablished::class ||
                        $payload['provenance'] !== 'registered' || !is_array($value)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    $decision = \Domain\Registration\ValueObject\PrerequisiteDecision::fromRetained($value);
                    if ($decision->permission !== null) {
                        $permission = $decision->permission->data;
                        if (
                            $permission['student_id'] !== $payload['enrollment']['student_id'] ||
                            $permission['section_id'] !== $payload['enrollment']['section_id'] ||
                            $permission['term_id'] !== $payload['enrollment']['term_id'] ||
                            $permission['course_version_id'] !== $payload['source']['course_version_id'] ||
                            new \DateTimeImmutable($permission['expires_at']) <=
                                new \DateTimeImmutable($payload['enrollment']['registered_at'])
                        ) {
                            throw new CorruptHistoryException();
                        }
                    }
                    if ($decision->required !== $payload['eligibility']['required_course_ids']) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($name === 'specialization_id') {
                    if (
                        $class !== \Domain\DegreeAudit\Event\RequirementsWerePublished::class || $version !== 4 ||
                        !is_string($value) || !is_string($metadata['curriculum_version_id'] ?? null)
                    ) {
                        throw new CorruptHistoryException();
                    }
                    new \Domain\Shared\ValueObject\EntityId($value);
                    continue;
                }
                if (
                    !in_array(
                        $name,
                        [
                            "policy_version_id",
                            "course_version_id",
                            "section_version_id",
                            "curriculum_version_id",
                            "grading_scheme_version_id",
                        ],
                        true,
                    ) ||
                    !is_string($value)
                ) {
                    throw new CorruptHistoryException();
                }
                new \Domain\Shared\ValueObject\EntityId($value);
            }
            if (
                array_key_exists("currency", $payload) &&
                (!is_string($payload["currency"]) || preg_match('/^[A-Z]{3}$/D', $payload["currency"]) !== 1)
            ) {
                throw new CorruptHistoryException();
            }
            if (
                array_key_exists("minor", $payload) &&
                (!is_int($payload["minor"]) || $payload["minor"] < 1 || $payload["minor"] > 2000000000)
            ) {
                throw new CorruptHistoryException();
            }
            if (
                array_key_exists("type", $payload) &&
                !in_array($payload["type"], ["charge", "payment", "refund"], true)
            ) {
                throw new CorruptHistoryException();
            }
            if (
                array_key_exists("name", $payload) &&
                (!is_string($payload["name"]) ||
                    !mb_check_encoding($payload["name"], "UTF-8") ||
                    trim($payload["name"]) === "" ||
                    mb_strlen($payload["name"]) > 191 ||
                    !in_array($payload["kind"], ["fee", "tuition"], true))
            ) {
                throw new CorruptHistoryException();
            }
            if (
                $definition["type"] === "section_offering_binding"
                && (!is_string($payload["definition_hash"])
                    || preg_match('/^[a-f0-9]{64}$/D', $payload["definition_hash"]) !== 1)
            ) {
                throw new CorruptHistoryException();
            }
            if (
                $class === \Domain\Registration\Event\SectionOfferingVersionWasAmended::class
                && $payload['publication_id'] === $payload['previous_publication_id']
            ) {
                throw new CorruptHistoryException();
            }
            if ($definition['type'] === 'section_meeting_plan') {
                if (!is_array($payload['definition']) || $payload['version_id'] === $payload['previous_version_id']) {
                    throw new CorruptHistoryException();
                }
                $plan = new \Domain\Registration\ValueObject\SectionMeetingPlanDefinition($payload['definition']);
                if ($plan->data['section_id'] !== (string) $stream->aggregateId) {
                    throw new CorruptHistoryException();
                }
            }
            if ($definition["type"] === "section_publication") {
                if (!is_array($payload["definition"]) || $payload["version_id"] === $payload["previous_version_id"]) {
                    throw new CorruptHistoryException();
                }
                new \Domain\Registration\ValueObject\SectionDefinitionV1($payload["definition"]);
            }
            if ($definition["type"] === "course_publication") {
                $this->courseDefinition($payload, $stream, $version);
            }
            if ($definition["type"] === "user") {
                $this->user($payload, $stream);
            }
            if ($class === \Domain\Finance\Event\JournalWasPosted::class) {
                $this->journal($payload);
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    /** @param array<mixed> $payload */
    private function user(#[\SensitiveParameter] array $payload, StreamReference $stream): void
    {
        foreach ($payload as $name => $value) {
            if ($name === 'sign_in_enabled') {
                if (!is_bool($value)) {
                    throw new CorruptHistoryException();
                }
                continue;
            }
            if ($name === 'intent' && (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1)) {
                throw new CorruptHistoryException();
            }
            if ($name === "person_id" && $value === null) {
                continue;
            }
            if ($name === "created_on") {
                $normalized = json_decode(
                    ProtectedEventCodec::canonical(["date" => $value]),
                    true,
                    32,
                    JSON_THROW_ON_ERROR,
                )["date"];
                if (
                    is_array($normalized) &&
                    is_string($normalized["date"] ?? null) &&
                    is_string($normalized["timezone"] ?? null)
                ) {
                    new DateTimeImmutable($normalized["date"], new DateTimeZone($normalized["timezone"]));
                } elseif (is_string($normalized)) {
                    new DateTimeImmutable($normalized, new DateTimeZone("UTC"));
                } else {
                    throw new CorruptHistoryException();
                }
                continue;
            }
            if (!is_string($value) || !mb_check_encoding($value, "UTF-8") || strlen($value) > 4096) {
                throw new CorruptHistoryException();
            }
            if ($name === "user_id" && $value !== (string) $stream->aggregateId) {
                throw new CorruptHistoryException();
            }
            if ($name === "token") {
                new \Domain\User\ValueObject\UserToken($value);
            }
            if (in_array($name, ["username", "email", "password", "role"], true) && trim($value) === "") {
                throw new CorruptHistoryException();
            }
        }
    }

    /** @param array<mixed> $payload */
    private function courseDefinition(array $payload, StreamReference $stream, int $version): void
    {
        if (!is_array($payload["definition"]) || $version !== 1) {
            throw new CorruptHistoryException();
        }
        $course = \Domain\Catalog\ValueObject\CourseDefinition::fromArray($payload["definition"]);
        if (
            in_array((string) $stream->aggregateId, $course->prerequisites, true)
            || $payload["version_id"] === $payload["previous_version_id"]
        ) {
            throw new CorruptHistoryException();
        }
    }

    public function streamFor(AggregateId $id): StreamReference
    {
        $type = match ($id->aggregateClassName()) {
            \Domain\Registration\Waitlist\SectionWaitlist::class => 'section_waitlist',
            \Domain\HumanResources\TimeApprovalPolicy::class => 'timesheet_policy',
            \Domain\HumanResources\TimeDecision::class => 'timesheet_decision',
            \Domain\Gradebook\GradeCertificationHistory::class => 'class_grade_certifications',
            \Domain\Registration\PrerequisitePermission::class => 'prerequisite_permission',
            \Domain\Student\EnrollmentLoadDetermination::class => 'enrollment_load_determination',
            \Domain\Student\EnrollmentLoadPolicy::class => 'enrollment_load_policy',
            \Domain\Student\StudentStanding::class => 'student_standing',
            \Domain\Advising\AdvisorHistory::class => 'student_advisors',
            \Domain\Registration\RegistrationWindowHistory::class => 'term_registration_window',
            \Domain\Registration\DropPolicyPublication::class =>
            $id instanceof \Domain\Registration\ValueObject\DropPolicyPublicationId && $id->isSection
            ? 'section_drop_policy' : 'term_drop_policy',
            \Domain\Student\PersonRestrictions::class => 'person_restrictions',
            \Domain\HumanResources\PositionHistory::class => 'employment_position',
            \Domain\HumanResources\EmploymentTimeline::class => 'employment_lifecycle',
            \Domain\HumanResources\EmploymentHistory::class => 'employment_profile',
            \Domain\Person\ProfileHistory::class => 'person_profile',
            \Domain\AcademicRecord\WorkPursuit::class => 'work_pursuit',
            \Domain\Student\StudentSpecializations::class => 'student_specializations',
            \Domain\Gradebook\GradingScale::class => "grading_scale",
            \Domain\Catalog\ProgramPublication::class => "program_publication",
            \Domain\Registration\SectionOfferingBinding::class => "section_offering_binding",
            \Domain\Registration\SectionMeetingPlan::class => 'section_meeting_plan',
            \Domain\Registration\SectionPublication::class => "section_publication",
            \Domain\Admissions\AdmissionDecision::class => "admission_decision",
            \Domain\Admissions\Matriculation::class => "matriculation",
            \Domain\Student\StudentPrimaryProgram::class => "student_primary_program",
            \Domain\Student\StudentProgramStatus::class => "student_program_status",
            \Domain\Student\StudentActivity::class => "student_activity",
            \Domain\Registration\EnrollmentHistory::class => "enrollment",
            \Domain\Transfer\TransferCredit::class => "transfer_credit",
            \Domain\AcademicRecord\AcademicCredit::class => "academic_credit",
            \Domain\DegreeAudit\RequirementAdjustments::class => "student_requirement_adjustments",
            \Domain\DegreeAudit\StudentRequirements::class => "student_degree_requirements",
            \Domain\DegreeAudit\CourseGroupPublication::class => "course_group_publication",
            \Domain\DegreeAudit\RequirementsPublication::class => "degree_audit_requirements",
            \Domain\Registration\SectionCourseBinding::class => "section_course_binding",
            \Domain\Catalog\CoursePublication::class => "course_publication",
            \Domain\Finance\StudentAccount::class => "student_account",
            \Domain\Finance\BillingRule::class => "billing_rule",
            \Domain\Finance\Journal::class => "journal",
            \Domain\User\User::class => "user",
            default => throw new CorruptHistoryException(),
        };
        return new StreamReference($id, $type);
    }

    /**
     * @throws TypeException
     */
    public function stream(string $type, string $id): StreamReference
    {
        $aggregateId = match ($type) {
            'section_waitlist' => new \Domain\Registration\Waitlist\WaitlistId($id),
            'timesheet_policy' => new \Domain\HumanResources\ValueObject\TimeApprovalPolicyId($id),
            'timesheet_decision' => new \Domain\HumanResources\ValueObject\TimeDecisionId($id),
            'class_grade_certifications' => new \Domain\Gradebook\ValueObject\GradeCertificationId($id),
            'prerequisite_permission' => new \Domain\Registration\ValueObject\PrerequisitePermissionId($id),
            'enrollment_load_determination' => new \Domain\Student\ValueObject\LoadDeterminationId($id),
            'enrollment_load_policy' => new \Domain\Student\ValueObject\EnrollmentLoadPolicyId($id),
            'student_standing' => new \Domain\Student\ValueObject\StudentStandingId($id),
            'student_advisors' => new \Domain\Advising\ValueObject\AdvisorHistoryId($id),
            'term_registration_window' => new \Domain\Registration\ValueObject\RegistrationWindowId($id),
            'term_drop_policy' => new \Domain\Registration\ValueObject\DropPolicyPublicationId($id),
            'section_drop_policy' => new \Domain\Registration\ValueObject\DropPolicyPublicationId($id, true),
            'person_restrictions' => new \Domain\Student\ValueObject\PersonRestrictionsId($id),
            'employment_position' => new \Domain\HumanResources\ValueObject\EmployeePositionId($id),
            'employment_lifecycle' => new \Domain\HumanResources\ValueObject\EmploymentTimelineId($id),
            'person_profile' => new \Domain\Person\ValueObject\PersonProfileId($id),
            'employment_profile' => new \Domain\HumanResources\ValueObject\EmploymentProfileId($id),
            'work_pursuit' => new \Domain\AcademicRecord\ValueObject\WorkPursuitId($id),
            'student_specializations' => new \Domain\Student\ValueObject\StudentSpecializationsId($id),
            "grading_scale" => new \Domain\Gradebook\ValueObject\GradingScaleId($id),
            "section_offering_binding" => new \Domain\Registration\ValueObject\SectionOfferingBindingId($id),
            'section_meeting_plan' => new \Domain\Registration\ValueObject\SectionMeetingPlanId($id),
            "section_publication" => new \Domain\Registration\ValueObject\SectionPublicationId($id),
            "admission_decision" => new \Domain\Admissions\ValueObject\AdmissionDecisionId($id),
            "matriculation" => new \Domain\Admissions\ValueObject\MatriculationId($id),
            "student_primary_program" => new \Domain\Student\ValueObject\StudentPrimaryProgramId($id),
            "student_program_status" => new \Domain\Student\ValueObject\StudentProgramStatusId($id),
            "student_activity" => new \Domain\Student\ValueObject\StudentActivityId($id),
            "enrollment" => new \Domain\Registration\ValueObject\EnrollmentHistoryId($id),
            "transfer_credit" => new \Domain\Transfer\ValueObject\TransferCreditId($id),
            "academic_credit" => new \Domain\AcademicRecord\ValueObject\AcademicCreditId($id),
            "student_requirement_adjustments" => new \Domain\DegreeAudit\ValueObject\RequirementAdjustmentsId(
                $id,
            ),
            "student_degree_requirements" => new \Domain\DegreeAudit\ValueObject\StudentRequirementsId($id),
            "course_group_publication" => new \Domain\DegreeAudit\ValueObject\CourseGroupPublicationId($id),
            "degree_audit_requirements" => new \Domain\DegreeAudit\ValueObject\RequirementsPublicationId($id),
            "section_course_binding" => new \Domain\Registration\ValueObject\SectionCourseBindingId($id),
            "course_publication" => new \Domain\Catalog\ValueObject\CoursePublicationId($id),
            "program_publication" => new \Domain\Catalog\ValueObject\ProgramPublicationId($id),
            "student_account" => new \Domain\Finance\ValueObject\AccountId($id),
            "billing_rule" => new \Domain\Finance\ValueObject\BillingRuleId($id),
            "journal" => new \Domain\Finance\ValueObject\JournalId($id),
            "user" => new \Domain\User\ValueObject\UserId($id),
            default => throw new CorruptHistoryException(),
        };
        return $this->streamFor($aggregateId);
    }

    /** @param array<mixed> $payload */
    private function journal(#[\SensitiveParameter] array $payload): void
    {
        if (
            !is_string($payload["reference"]) ||
            preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,79}$~D', $payload["reference"]) !== 1 ||
            !is_string($payload["posted_on"]) ||
            !is_array($payload["lines"]) ||
            !array_is_list($payload["lines"]) ||
            count($payload["lines"]) < 2 ||
            count($payload["lines"]) > 500
        ) {
            throw new CorruptHistoryException();
        }
        $on = DateTimeImmutable::createFromFormat("!Y-m-d", $payload["posted_on"]);
        if ($on === false || $on->format("Y-m-d") !== $payload["posted_on"]) {
            throw new CorruptHistoryException();
        }
        $balance = 0;
        $debits = 0;
        $accounts = [];
        foreach ($payload["lines"] as $line) {
            if (
                !is_array($line) ||
                count($line) !== 2 ||
                !is_string($line["account_id"] ?? null) ||
                !is_int($line["minor"] ?? null) ||
                $line["minor"] === 0 ||
                abs($line["minor"]) > 2000000000
            ) {
                throw new CorruptHistoryException();
            }
            new \Domain\Shared\ValueObject\EntityId($line["account_id"]);
            $balance += $line["minor"];
            $debits += max(0, $line["minor"]);
            $accounts[$line["account_id"]] = true;
        }
        if ($balance !== 0 || $debits > 2000000000 || count($accounts) < 2) {
            throw new CorruptHistoryException();
        }
    }

    /** @return array<string, mixed> */
    public function describe(#[\SensitiveParameter] DomainEvent $event): array
    {
        $version = $event->metaParam("schema_version", 1);
        if (!is_int($version)) {
            throw new CorruptHistoryException();
        }
        $this->resolve($event::class, $event->eventType(), $version);
        $metadata = array_diff_key(
            $event->metadata(),
            array_flip([
                Metadata::AGGREGATE_ID,
                Metadata::AGGREGATE_TYPE,
                Metadata::AGGREGATE_PLAYHEAD,
                Metadata::EVENT_ID,
                Metadata::EVENT_TYPE,
                Metadata::RECORDED_AT,
            ]),
        );
        $this->validate(
            new StreamReference($event->aggregateId(), $event->metaParam(Metadata::AGGREGATE_TYPE)),
            $event::class,
            $event->payload(),
            $metadata,
            $version,
        );
        if (
            strlen(
                DateTimeImmutable::createFromInterface($event->recordedAt())
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d\TH:i:s.u\Z"),
            ) !== 27
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "class" => $event::class,
            "type" => $event->eventType(),
            "schema_version" => $version,
            "payload" => $event->payload(),
            "metadata" => $metadata,
            "source_recorded_at" => DateTimeImmutable::createFromInterface($event->recordedAt())
                ->setTimezone(new DateTimeZone("UTC"))
                ->format("Y-m-d\TH:i:s.u\Z"),
        ];
    }

    /** @param array<string, mixed> $data */
    public function restore(
        StreamReference $stream,
        string $eventId,
        int $position,
        #[\SensitiveParameter] array $data,
    ): DomainEvent {
        if (
            !is_string($data["class"] ?? null) ||
            !is_string($data["type"] ?? null) ||
            !is_int($data["schema_version"] ?? null) ||
            !is_array($data["payload"] ?? null) ||
            !is_array($data["metadata"] ?? null) ||
            !is_string($data["source_recorded_at"] ?? null)
        ) {
            throw new CorruptHistoryException();
        }
        $class = $this->resolve($data["class"], $data["type"], $data["schema_version"]);
        $this->validate($stream, $class, $data["payload"], $data["metadata"], $data["schema_version"]);
        $date = DateTimeImmutable::createFromFormat(
            "!Y-m-d\TH:i:s.u\Z",
            $data["source_recorded_at"],
            new DateTimeZone("UTC"),
        );
        if ($date === false || $date->format("Y-m-d\TH:i:s.u\Z") !== $data["source_recorded_at"]) {
            throw new CorruptHistoryException();
        }
        if (($data["metadata"]["schema_version"] ?? 1) !== $data["schema_version"]) {
            throw new CorruptHistoryException();
        }
        // Framework metadata is reconstructed from verified envelope fields; custom metadata cannot override it.
        return $class::fromArray([
            "aggregateId" => $stream->aggregateId,
            "payload" => $data["payload"],
            "metadata" =>
                [
                    Metadata::AGGREGATE_ID => $stream->aggregateId,
                    Metadata::AGGREGATE_TYPE => $stream->type,
                    Metadata::AGGREGATE_PLAYHEAD => $position,
                    Metadata::EVENT_ID => new EventId($eventId),
                    Metadata::EVENT_TYPE => $data["type"],
                    Metadata::RECORDED_AT => $date,
                ] + $data["metadata"],
        ]);
    }
}
