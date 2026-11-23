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
    ) {
    }

    private function scope(
        HistoryCheckSubject $kind,
        EntityId $id,
        EntityId $actor,
    ): HistoryCheckScope {
        foreach (["sis:history:verify", "sis:audit:read", ...$kind->permissions()] as $permission) {
            if ($this->access->requirePermission($permission)->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        }
        $load = $kind === HistoryCheckSubject::Load
        ? ($this->loads->find($id) ?? throw new RecordNotFoundException("Load determination not found."))
        : null;
        $pursuit = match ($kind) {
            HistoryCheckSubject::Standing => $id,
            HistoryCheckSubject::Load => new EntityId($load["pursuit_id"]),
            default => null,
        };
        $student = match ($kind) {
            HistoryCheckSubject::Person => null,
            HistoryCheckSubject::Application => null,
            HistoryCheckSubject::Student => $id,
            HistoryCheckSubject::Standing => $this->students->assignment($id)->studentId,
            HistoryCheckSubject::Load => new EntityId($load["student_id"]),
            HistoryCheckSubject::Account => $this->receipts->accountStudent($id),
        };
        $person = $this->admissions->person(
            $kind === HistoryCheckSubject::Application ? $this->admissions->application($id)->personId
                : ($student === null ? $id : $this->admissions->student($student)->personId),
        );
        if (
            $actor->value !== $this->access->authenticatedUserId()->value ||
            ($person->ownerUserId !== null &&
                $this->roles->isSuper($person->ownerUserId->value) &&
                !$this->roles->isSuper($actor->value))
        ) {
            throw new AccessDeniedException(
                "This history check requires current support authority.",
            );
        }
        return new HistoryCheckScope($kind, $id, $person->id, $person->ownerUserId, $student, $pursuit);
    }

    private function recheck(HistoryCheckScope $scope, EntityId $actor): void
    {
        $current = $this->scope($scope->kind, $scope->subject, $actor);
        if (
            $scope->criteria() !== $current->criteria()
            || $scope->pursuit?->value !== $current->pursuit?->value
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
                HistoryCheckSubject::Person => $this->persons
                    ->verify($scope->subject, HistoryCheckReceipt::EVENT_LIMIT)
                    ->describe(),
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
        $this->people->lock($scope->person);
        if ($scope->student !== null) {
            $this->students->lock($scope->student);
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
