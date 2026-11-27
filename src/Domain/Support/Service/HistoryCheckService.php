<?php

declare(strict_types=1);

namespace Domain\Support\Service;

use DateTimeZone;
use Domain\AcademicRecord\Repository\AcademicProjectionVerifier;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\Repository\ApplicationProjectionVerifier;
use Domain\Audit\Repository\AuditTrail;
use Domain\Finance\Repository\AccountProjectionVerifier;
use Domain\Finance\ValueObject\AccountId;
use Domain\HumanResources\Repository\EmploymentProjectionVerifier;
use Domain\HumanResources\Repository\TimeRepository;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\Repository\PersonProjectionVerifier;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Repository\StudentDecisionProjectionVerifier;
use Domain\Student\Repository\LoadDeterminationRepository;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Support\Enum\HistoryCheckSubject;
use Domain\Support\HistoryCheckReceipt;
use Domain\Support\HistoryCheckScope;
use Domain\Support\Repository\HistoryCheckRepository;
use Throwable;

use function Codefy\Framework\Helpers\trans;

/** Explicitly authorized operator facade. Verification itself remains outside a snapshot transaction. */
final readonly class HistoryCheckService
{
    public function __construct(
        private HistoryCheckRepository $receipts,
        private PersonProjectionVerifier $persons,
        private AcademicProjectionVerifier $academics,
        private AccountProjectionVerifier $accounts,
        private ApplicationProjectionVerifier $applications,
        private StudentDecisionProjectionVerifier $decisions,
        private LoadDeterminationRepository $loads,
        private AdmissionsRepository $admissions,
        private PersonProfileRepository $people,
        private StudentRecordRepository $students,
        private AccessRepository $roles,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private EmploymentProjectionVerifier $employmentHistory,
        private TimeRepository $employees,
        private \Domain\DegreeAudit\Repository\DegreeProgressRecordRepository $progressRecords,
        private \Domain\Registration\Waitlist\WaitlistProjectionVerifier $waitlistHistory,
        private \Domain\Registration\Waitlist\WaitlistRepository $waitlists,
        private \Domain\Catalog\Repository\CatalogProjectionVerifier $catalogHistory,
        private \Domain\Catalog\Repository\CatalogRevisionRepository $catalog,
        private \Domain\Registration\Repository\GraduateHistoryVerifier $graduateHistory,
        private \Domain\DegreeAudit\Repository\CourseGroupRepository $courseGroups,
        private \Domain\DegreeAudit\Repository\RequirementsRepository $curricula,
        private \Domain\Person\Repository\PersonSsnProjectionVerifier $ssns,
        private \Domain\Registration\Repository\MeetingPlanVerifier $meetingPlans,
    ) {
    }

    private function scope(
        HistoryCheckSubject $kind,
        EntityId $id,
        EntityId $actor,
    ): HistoryCheckScope {
        foreach (["sis:history:verify", "sis:audit:read", ...$kind->permissions()] as $permission) {
            if (
                $this->access->requirePermission($permission)->value !== $actor->value
                || ($kind === HistoryCheckSubject::Ssn && !in_array(
                    $actor->value,
                    $this->roles->allowedUsers([$actor], $permission),
                    true
                ))
            ) {
                throw new AccessDeniedException();
            }
        }
        if (
            in_array($kind, [HistoryCheckSubject::Program, HistoryCheckSubject::CourseGroup,
            HistoryCheckSubject::Curriculum], true)
        ) {
            if ($actor->value !== $this->access->authenticatedUserId()->value) {
                throw new AccessDeniedException();
            }
            return new HistoryCheckScope(
                $kind,
                $id,
                curriculumContext: $this->catalogHistory->curriculumContext($kind->value, $id)
            );
        }
        if ($kind === HistoryCheckSubject::GraduatePolicy) {
            if ($actor->value !== $this->access->authenticatedUserId()->value) {
                throw new AccessDeniedException();
            }
            return new HistoryCheckScope($kind, $id);
        }
        if (
            in_array($kind, [HistoryCheckSubject::Course, HistoryCheckSubject::Section,
            HistoryCheckSubject::MeetingPlan], true)
        ) {
            if ($actor->value !== $this->access->authenticatedUserId()->value) {
                throw new AccessDeniedException();
            }
            $section = $kind === HistoryCheckSubject::Course ? null : $this->catalog->get('sections', $id);
            $course = $section === null ? $id : new EntityId($section['course_id']);
            $this->catalog->get('courses', $course);
            return new HistoryCheckScope(
                $kind,
                $id,
                null,
                null,
                null,
                section: $section === null ? null : $id,
                course: $course,
                term: $section === null ? null : new EntityId($section['term_id'])
            );
        }
        $employee = in_array($kind, [
            HistoryCheckSubject::PrimaryPosition, HistoryCheckSubject::Position, HistoryCheckSubject::TimeEntry,
        ], true) ? $this->receipts->employmentSubject($kind, $id) : null;
        $waiting = $kind === HistoryCheckSubject::Waitlist
        ? ($this->waitlists->entry($id) ?? throw new RecordNotFoundException(
            trans('Waitlist request not found.')
        )) : null;
        $load = $kind === HistoryCheckSubject::Load
        ? ($this->loads->find($id) ?? throw new RecordNotFoundException("Load determination not found."))
        : null;
        $progress = $kind === HistoryCheckSubject::Progress
        ? ($this->progressRecords->find($id) ?? throw new RecordNotFoundException(
            trans('Recorded progress not found.')
        ))
        : null;
        $pursuit = match ($kind) {
            HistoryCheckSubject::Standing, HistoryCheckSubject::Advisors, HistoryCheckSubject::Requirements,
            HistoryCheckSubject::GraduateApprovals => $id,
            HistoryCheckSubject::Load => new EntityId($load["pursuit_id"]),
            HistoryCheckSubject::Progress => new EntityId($progress['pursuit_id']),
            default => null,
        };
        $student = match ($kind) {
            HistoryCheckSubject::Person, HistoryCheckSubject::Ssn => null,
            HistoryCheckSubject::PrimaryPosition, HistoryCheckSubject::Position, HistoryCheckSubject::TimeEntry => null,
            HistoryCheckSubject::Application => null,
            HistoryCheckSubject::Student => $id,
            HistoryCheckSubject::Credit => $this->receipts->creditStudent($id),
            HistoryCheckSubject::Waitlist => new EntityId($waiting['student_id']),
            HistoryCheckSubject::Standing, HistoryCheckSubject::Advisors, HistoryCheckSubject::Requirements,
            HistoryCheckSubject::GraduateApprovals =>
            $this->students->assignment($id)->studentId,
            HistoryCheckSubject::Load => new EntityId($load["student_id"]),
            HistoryCheckSubject::Progress => new EntityId($progress['student_id']),
            HistoryCheckSubject::Account => $this->receipts->accountStudent($id),
        };
        if (
            $kind === HistoryCheckSubject::Progress &&
            $this->students->assignment($pursuit)->studentId->value !== $student->value
        ) {
            throw new AccessDeniedException();
        }
        $person = $this->admissions->person(
            $employee !== null ? $this->employees->employee($employee)->personId
                : ($kind === HistoryCheckSubject::Application ? $this->admissions->application($id)->personId
                    : ($student === null ? $id : $this->admissions->student($student)->personId)),
        );
        if (
            ($kind === HistoryCheckSubject::Ssn && $person->ownerUserId === null) ||
            ($waiting !== null && $waiting['person_id'] !== $person->id->value) ||
            $actor->value !== $this->access->authenticatedUserId()->value ||
            ($person->ownerUserId !== null &&
                $this->roles->isSuper($person->ownerUserId->value) &&
                !$this->roles->isSuper($actor->value))
        ) {
            throw new AccessDeniedException(
                "This history check requires current support authority.",
            );
        }
        return new HistoryCheckScope(
            $kind,
            $id,
            $person->id,
            $person->ownerUserId,
            $student,
            $pursuit,
            $employee,
            $waiting === null ? null : new EntityId($waiting['section_id']),
        );
    }

    private function recheck(HistoryCheckScope $scope, EntityId $actor): void
    {
        $current = $this->scope($scope->kind, $scope->subject, $actor);
        if (
            $scope->criteria() !== $current->criteria()
            || $scope->pursuit?->value !== $current->pursuit?->value
            || $scope->employee?->value !== $current->employee?->value
            || $scope->section?->value !== $current->section?->value
            || $scope->course?->value !== $current->course?->value
            || $scope->term?->value !== $current->term?->value
            || $scope->curriculumContext !== $current->curriculumContext
        ) {
            throw new ConcurrentModificationException(
                "Record routing changed. Reopen the record before checking its history.",
            );
        }
    }

    public function run(HistoryCheckSubject $kind, EntityId $id): array
    {
        $actor = $this->access->authenticatedUserId();
        $scope = $this->scope($kind, $id, $actor);
        // The selected verifiers detect concurrent changes. Never enclose them in the receipt transaction.
        $result = $this->verify($scope);
        $checkedAt = $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s\Z");
        $this->recheck($scope, $actor);
        return $this->transactions->run(function () use (
            $scope,
            $actor,
            $result,
            $checkedAt,
        ): array {
            $this->lock($scope);
            $this->recheck($scope, $actor);
            $receipt = new HistoryCheckReceipt(
                EntityId::generate(),
                $scope,
                $actor,
                $result["status"],
                $result["events_checked"],
                $checkedAt,
            );
            $this->receipts->save($receipt);
            $this->audit->record($actor, "support.history_checked", $receipt->id);
            $this->recheck($scope, $actor);
            return [
                "result" => $result,
                "receipt" => $receipt->view(),
                "event_limit" => HistoryCheckReceipt::EVENT_LIMIT,
            ];
        });
    }

    private function verify(HistoryCheckScope $scope): array
    {
        try {
            $result = match ($scope->kind) {
                HistoryCheckSubject::GraduatePolicy => $this->graduateHistory
                    ->policy(HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::GraduateApprovals => $this->graduateHistory
                    ->approvals($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Person => $this->persons
                    ->verify($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)
                    ->describe(),
                HistoryCheckSubject::Ssn => $this->ssns
                    ->verify($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Course => $this->catalogHistory
                    ->course($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Section => $this->catalogHistory
                    ->section($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::MeetingPlan => $this->meetingPlans
                    ->verify($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Program, HistoryCheckSubject::CourseGroup, HistoryCheckSubject::Curriculum
                => $this->catalogHistory->curriculum($scope->kind->value, $scope->subject,
                        HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Waitlist => $this->waitlistHistory
                    ->verify($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Credit => $this->academics
                    ->credit($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Student => $this->academics
                    ->verify($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)
                    ->describe(),
                HistoryCheckSubject::Account => $this->accounts
                    ->verify(
                        new AccountId($scope->subject->value),
                        HistoryCheckReceipt::EVENT_LIMIT,
                    )
                    ->describe(),
                HistoryCheckSubject::Standing => $this->decisions
                    ->standing($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)
                    ->describe(),
                HistoryCheckSubject::Load => $this->decisions
                    ->load($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)
                    ->describe(),
                HistoryCheckSubject::Advisors => $this->decisions
                    ->advisors($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Requirements => $this->decisions
                    ->requirements($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Progress => $this->decisions
                    ->progressRecord($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::PrimaryPosition => $this->employmentHistory
                    ->primaryPosition($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Position => $this->employmentHistory
                    ->position($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::TimeEntry => $this->employmentHistory
                    ->timeEntry($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)->describe(),
                HistoryCheckSubject::Application => $this->applications
                    ->verify($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)
                    ->describe(),
            };
            if (
                ($result[$scope->kind->referenceKey()] ?? null) !== $scope->subject->value ||
                ($result["scope"] ?? null) !== $scope->kind->scope() ||
                ($result["events_checked"] ?? -1) > HistoryCheckReceipt::EVENT_LIMIT
            ) {
                throw new \LogicException("Unexpected selected-verification result.");
            }
            return $result;
        } catch (Throwable) {
            // No provider exception, profile value, financial amount or protected payload enters a receipt/response.
            return [
                "status" => "unverified",
                "scope" => $scope->kind->scope(),
                $scope->kind->referenceKey() => $scope->subject->value,
                "events_checked" => 0,
                "issues" => ["unavailable"],
                "records_changed" => false,
            ];
        }
    }

    private function lock(HistoryCheckScope $scope): void
    {
        $this->roles->lock();
        if ($scope->kind === HistoryCheckSubject::Program) {
            $this->catalog->lock('programs', $scope->subject);
        }
        if ($scope->kind === HistoryCheckSubject::CourseGroup) {
            $this->courseGroups->lock($scope->subject);
        }
        if ($scope->kind === HistoryCheckSubject::Curriculum) {
            $this->curricula->lockContext(
                new EntityId($scope->curriculumContext['program_id']),
                new EntityId($scope->curriculumContext['academic_year_id'])
            );
            $this->curricula->lock($scope->subject);
        }
        if ($scope->person !== null) {
            $this->people->lock($scope->person);
        }
        if (
            $scope->section !== null && in_array($scope->kind, [HistoryCheckSubject::Section,
            HistoryCheckSubject::MeetingPlan], true)
        ) {
            $this->catalog->lock('sections', $scope->section);
        }
        if ($scope->course !== null) {
            $this->catalog->lock('courses', $scope->course);
        }
        if ($scope->student !== null) {
            $this->students->lock($scope->student);
        }
        if ($scope->employee !== null) {
            $this->employees->lockEmployee($scope->employee);
        }
        if ($scope->kind === HistoryCheckSubject::Application) {
            $this->admissions->lockApplication($scope->subject);
        }
    }

    public function history(HistoryCheckSubject $kind, EntityId $id, ?EntityId $after = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $scope = $this->scope($kind, $id, $actor);
        return $this->transactions->run(function () use ($scope, $actor, $after): array {
            $this->lock($scope);
            $this->recheck($scope, $actor);
            $rows = $this->receipts->page($scope, $after);
            $items = array_slice($rows, 0, 10);
            $this->audit->record($actor, "support.history_read", $scope->subject);
            $this->recheck($scope, $actor);
            return [
                "items" => array_map(
                    static fn(HistoryCheckReceipt $receipt): array => $receipt->view(),
                    $items,
                ),
                "next_after" => count($rows) > 10 ? end($items)->id->value : null,
            ];
        });
    }
}
