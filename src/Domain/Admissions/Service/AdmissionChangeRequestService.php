<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\AdmissionChangeRequest;
use Domain\Admissions\Enum\ApplicationStatus;
use Domain\Admissions\Repository\AdmissionChangeRequestRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Admissions\Repository\AdmissionUpdatesRepository;
use Domain\Admissions\ValueObject\AdmissionDecisionSchema;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans;

/** Encrypted applicant working requests. Formal program/intake writers retain their own independent authority. */
final readonly class AdmissionChangeRequestService
{
    public function __construct(
        private AdmissionChangeRequestRepository $requests,
        private AdmissionsRepository $admissions,
        private AdmissionDecisionLedger $history,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private AdmissionUpdatesRepository $updates,
    ) {
    }

    private function authorize(
        EntityId $id,
        EntityId $actor,
        bool $mine,
    ): AdmissionApplication {
        if (
            $this->access->authenticatedUserId()->value !== $actor->value ||
            (!$mine &&
                $this->access->requirePermission("sis:admissions:manage")
                    ->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        $a = $this->admissions->application($id);
        $person = $this->admissions->person($a->personId);
        if (
            ($mine && $person->ownerUserId?->value !== $actor->value) ||
            (!$mine &&
                $person->ownerUserId !== null &&
                $this->roles->isSuper($person->ownerUserId->value) &&
                !$this->roles->isSuper($actor->value))
        ) {
            throw new AccessDeniedException();
        }
        return $a;
    }

    private function stamp(AdmissionApplication $a): array
    {
        return [
            AdmissionDecisionSchema::facts($a),
            $this->admissions->person($a->personId)->ownerUserId?->value,
        ];
    }

    private function boundary(
        AdmissionApplication $a,
        HistoryReadBudget $budget,
    ): array {
        $source = $this->history->inspect($a, $budget);
        // Replays reconstruct value objects; compare their stable source identity, not PHP object identity.
        return [
            $this->stamp($a),
            $source["decision_position"],
            $source["matriculation_position"],
            $source["earlier_history_unknown"],
            $source["submission_fact"]?->event->value,
        ];
    }

    private function recordAccess(
        AdmissionChangeRequest $request,
        AdmissionApplication $a,
        EntityId $actor,
        bool $mine,
    ): void {
        $f = $request->facts();
        if (
            $f["application_id"] !== $a->id->value ||
            $f["person_id"] !== $a->personId->value ||
            ($mine && $f["owner_user_id"] !== $actor->value) ||
            (!$mine &&
                $this->roles->isSuper($f["owner_user_id"]) &&
                !$this->roles->isSuper($actor->value))
        ) {
            throw new AccessDeniedException();
        }
    }

    private function run(EntityId $id, bool $mine, callable $work): array
    {
        $actor = $this->access->authenticatedUserId();
        $route = $this->authorize($id, $actor, $mine);
        return $this->transactions->run(function () use (
            $id,
            $mine,
            $actor,
            $route,
            $work,
        ): array {
            if (!$mine) {
                $this->roles->lock();
            }
            $this->admissions->lockPerson($route->personId);
            $this->admissions->lockApplication($id);
            $a = $this->authorize($id, $actor, $mine);
            if ($route->personId->value !== $a->personId->value) {
                throw new ConcurrentModificationException(
                    trans("Refresh the application before continuing."),
                );
            }
            $budget = new HistoryReadBudget(
                maximumEvents: 2000,
                maximumBytes: 4_194_304,
                maximumMilliseconds: 5000,
            );
            $boundary = $this->boundary($a, $budget);
            $result = $work($a, $actor);
            $current = $this->authorize($id, $actor, $mine);
            if ($boundary !== $this->boundary($current, $budget)) {
                throw new ConcurrentModificationException(
                    trans("Refresh the application before continuing."),
                );
            }
            $budget->checkTime();
            return $result;
        });
    }

    public function listing(
        EntityId $application,
        bool $mine,
        bool $finished = false,
        ?EntityId $after = null,
    ): array {
        return $this->run($application, $mine, function (
            AdmissionApplication $a,
            EntityId $actor,
        ) use (
            $mine,
            $finished,
            $after
): array {
            $rows = $this->requests->page(
                $a->id,
                $mine ? $actor : null,
                $finished,
                $after,
            );
            foreach ($rows as $record) {
                $this->recordAccess($record, $a, $actor, $mine);
            }
            $owner = $this->admissions->person($a->personId)->ownerUserId;
            $items = array_slice($rows, 0, 10);
            $this->audit->record(
                $actor,
                "admissions.change_requests_read",
                $a->id,
            );
            $currentRows = $this->requests->page(
                $a->id,
                $mine ? $actor : null,
                $finished,
                $after,
            );
            foreach ($currentRows as $record) {
                $this->recordAccess($record, $a, $actor, $mine);
            }
            if (
                array_map(
                    static fn(AdmissionChangeRequest $r): array => $r->facts(),
                    $rows,
                ) !==
                array_map(
                    static fn(AdmissionChangeRequest $r): array => $r->facts(),
                    $currentRows,
                )
            ) {
                throw new ConcurrentModificationException(
                    trans("Refresh this follow-up before responding."),
                );
            }
            return [
                "items" => array_map(
                    static fn(AdmissionChangeRequest $r): array => $r->view(
                        $a->version,
                        $r->facts()["owner_user_id"] !== $owner?->value,
                    ),
                    $items,
                ),
                "next_after" =>
                    count($rows) > 10 ? end($items)->facts()["id"] : null,
                "application_revision" => $a->version,
                "application_status" => $a->status->value,
                "new_id" => EntityId::generate()->value,
            ];
        });
    }

    public function request(
        EntityId $application,
        EntityId $id,
        string $area,
        #[\SensitiveParameter] string $message,
        int $revision,
    ): array {
        return $this->run($application, true, function (
            AdmissionApplication $a,
            EntityId $actor,
        ) use (
            $id,
            $area,
            $message,
            $revision
): array {
            $old = $this->requests->find($a->id, $id);
            if ($old !== null) {
                $this->recordAccess($old, $a, $actor, true);
                if ($old->facts()["area"] !== $area) {
                    throw new ConcurrentModificationException(
                        trans("This request reference was already used."),
                    );
                }
                return $old->retry(
                    "request",
                    $actor,
                    $old->intent("request", 0, $revision, $message),
                ) ??
                    throw new \LogicException(
                        "A retained request must have its original receipt.",
                    );
            }
            if (
                $a->status !== ApplicationStatus::Submitted ||
                $revision !== $a->version
            ) {
                throw new ConcurrentModificationException(
                    trans(
                        "Refresh an application awaiting a decision before requesting a correction.",
                    ),
                );
            }
            $candidate = AdmissionChangeRequest::create(
                $id,
                $a->id,
                $a->personId,
                $actor,
                $revision,
                $area,
                $message,
                $this->now(),
            );
            if (
                $this->requests->pending($a->id, $area) ||
                $this->requests->count($a->id) >= 100
            ) {
                throw new ConcurrentModificationException(
                    trans(
                        "Review existing follow-ups or contact the admissions office before adding another.",
                    ),
                );
            }
            $this->requests->save($candidate, null);
            $this->audit->record($actor, "admissions.change_requested", $id);
            if (
                $this->requests->find($a->id, $id)?->facts() !==
                $candidate->facts()
            ) {
                throw new CorruptHistoryException();
            }
            return $candidate->retry(
                "request",
                $actor,
                $candidate->intent("request", 0, $revision, $message),
            );
        });
    }

    public function finish(
        EntityId $application,
        EntityId $id,
        bool $mine,
        int $version,
        int $revision,
        #[\SensitiveParameter] string $message,
    ): array {
        return $this->run($application, $mine, function (
            AdmissionApplication $a,
            EntityId $actor,
        ) use (
            $id,
            $mine,
            $version,
            $revision,
            $message
): array {
            $record =
                $this->requests->find($a->id, $id) ??
                throw new RecordNotFoundException(
                    trans("Application follow-up not found."),
                );
            $this->recordAccess($record, $a, $actor, $mine);
            $action = $mine ? "cancel" : "close";
            $intent = $record->intent($action, $version, $revision, $message);
            if (
                ($accepted = $record->retry($action, $actor, $intent)) !== null
            ) {
                return $accepted;
            }
            if ($revision !== $a->version) {
                throw new ConcurrentModificationException(
                    trans("Refresh the application before continuing."),
                );
            }
            $result = $record->finish(
                $action,
                $actor,
                $version,
                $revision,
                $message,
                $this->now(),
            );
            $this->requests->save($record, $version);
            if (
                !$mine &&
                $record->facts()["owner_user_id"] ===
                    $this->admissions->person($a->personId)->ownerUserId?->value
            ) {
                $this->updates->record(
                    $a->id,
                    new EntityId($record->facts()["owner_user_id"]),
                    $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
                );
            }
            $this->audit->record(
                $actor,
                "admissions.change_request_" . $action,
                $id,
            );
            if (
                $this->requests->find($a->id, $id)?->facts() !==
                $record->facts()
            ) {
                throw new CorruptHistoryException();
            }
            $this->recordAccess($record, $a, $actor, $mine);
            return $result;
        });
    }

    private function now(): string
    {
        return $this->clock
            ->now()
            ->setTimezone(new DateTimeZone("UTC"))
            ->format("Y-m-d\TH:i:s\Z");
    }
}
