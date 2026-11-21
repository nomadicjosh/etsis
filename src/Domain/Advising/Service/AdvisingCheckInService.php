<?php

declare(strict_types=1);

namespace Domain\Advising\Service;

use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\AdvisingCheckIn;
use Domain\Advising\Repository\AdvisingCheckInRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\StudentPrimaryProgramLedger;
use Domain\Student\Service\StudentProgramStatusLedger;

/** Private operational follow-up. No academic, appointment or messaging side effects. */
final readonly class AdvisingCheckInService
{
    public function __construct(
        private AdvisingCheckInRepository $records,
        private AdmissionsRepository $admissions,
        private StudentRecordRepository $students,
        private PersonProfileRepository $people,
        private StudentProgramStatusLedger $programs,
        private StudentPrimaryProgramLedger $primary,
        private AdvisorProgressAccess $advisors,
        private StudentAccess $access,
        private AccessRepository $roles,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    private function authorize(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        ?EntityId $expected = null,
    ): EntityId {
        $actor = $this->access->authenticatedUserId();
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException();
        }
        if ($channel === "owner") {
            if ($this->owner($student)?->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        } else {
            $this->access->requirePermission("sis:advising:checkin");
            $authorized = match ($channel) {
                "staff" => $this->access->requirePermission("sis:student:read"),
                "advisor" => $this->advisors->requireAdvisor($student, $pursuit),
                default => throw new AccessDeniedException(),
            };
            if ($authorized->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        }
        if ($actor->value !== $this->access->authenticatedUserId()->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function owner(EntityId $student): ?EntityId
    {
        return $this->admissions->person(
            $this->admissions->student($student)->personId,
        )->ownerUserId;
    }

    private function scope(EntityId $student, EntityId $pursuit): bool
    {
        $s = $this->admissions->student($student);
        $p = $this->students->assignment($pursuit);
        if ($p->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Student program not found.");
        }
        $budget = new HistoryReadBudget();
        $this->programs->verify($p, $budget);
        $primary = $this->primary->documentContext($s, $budget);
        return $s->active &&
        $p->status === "active" &&
        $primary["assignment_id"] === $pursuit->value;
    }

    private function lock(EntityId $student): void
    {
        $this->roles->lock();
        $this->people->lock($this->admissions->student($student)->personId);
        $this->students->lock($student);
    }

    public function listing(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        bool $finished = false,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $actor,
            $student,
            $pursuit,
            $channel,
            $finished,
            $after,
        ): array {
            $current = $this->scope($student, $pursuit);
            $owner = $this->owner($student);
            $rows = $this->records->page(
                $student,
                $pursuit,
                $channel === "owner" ? $actor : null,
                $finished,
                $after,
            );
            $items = array_slice($rows, 0, 10);
            $this->audit->record($actor, "advising.checkins_read", $student);
            $this->authorize($student, $pursuit, $channel, $actor);
            if (
                $owner?->value !== $this->owner($student)?->value ||
                $current !== $this->scope($student, $pursuit)
            ) {
                throw new ConcurrentModificationException(
                    "Student context changed. Refresh advising check-ins.",
                );
            }
            return [
                "items" => array_map(
                    static fn(AdvisingCheckIn $item): array => $item->view(
                        $channel === "owner",
                        $item->facts()["owner_user_id"] !== $owner?->value,
                    ),
                    $items,
                ),
                "next_after" => count($rows) > 10 ? end($items)->facts()["id"] : null,
                "new_id" => EntityId::generate()->value,
                "can_record" => $channel !== "owner" && $current && $owner !== null,
                "today" => $this->clock->now()->format("Y-m-d"),
            ];
        });
    }

    public function record(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        EntityId $id,
        string $heldOn,
        ?string $dueOn,
        #[\SensitiveParameter] string $nextStep,
        #[\SensitiveParameter] string $privateNote,
    ): array {
        if (!in_array($channel, ["staff", "advisor"], true)) {
            throw new AccessDeniedException();
        }
        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $channel,
            $actor,
            $id,
            $heldOn,
            $dueOn,
            $nextStep,
            $privateNote,
        ): array {
            $this->lock($student);
            $this->authorize($student, $pursuit, $channel, $actor);
            $current = $this->scope($student, $pursuit);
            $owner = $this->owner($student) ?? throw new AccessDeniedException();
            $candidate = AdvisingCheckIn::create(
                $id,
                $student,
                $pursuit,
                $owner,
                $actor,
                $heldOn,
                $dueOn,
                $privateNote,
                $nextStep,
                $this->now(),
            );
            $intent = $candidate->intent("record", 0, $nextStep);
            $old = $this->records->find($student, $pursuit, $id);
            if ($old !== null) {
                $result = $old->retry("record", $actor, $intent);
            } else {
                if (!$current || $heldOn > $this->clock->now()->format("Y-m-d")) {
                    throw new ValidationException([
                        [
                            "field" => "held_on",
                            "message" =>
                                "Use the active primary pursuit and a check-in dated today or earlier.",
                        ],
                    ]);
                }
                if ($this->records->activeCount($student, $pursuit) >= 20) {
                    throw new ConcurrentModificationException(
                        "Review and resolve existing follow-up before recording another check-in (20 active maximum).",
                    );
                }
                $this->records->save($candidate, null);
                $this->audit->record($actor, "advising.checkin_recorded", $id);
                $result = $candidate->retry("record", $actor, $intent);
            }
            $this->authorize($student, $pursuit, $channel, $actor);
            if (
                $owner->value !== $this->owner($student)?->value ||
                $current !== $this->scope($student, $pursuit)
            ) {
                throw new ConcurrentModificationException(
                    "Student context changed. Refresh advising check-ins.",
                );
            }
            return $result;
        });
    }

    public function act(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        EntityId $id,
        string $action,
        int $version,
        #[\SensitiveParameter] string $message,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        if (
            ($channel === "owner" && $action !== "respond") ||
            ($channel !== "owner" && !in_array($action, ["close", "cancel"], true))
        ) {
            throw new AccessDeniedException();
        }
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $channel,
            $id,
            $actor,
            $action,
            $version,
            $message,
        ): array {
            $this->lock($student);
            $this->authorize($student, $pursuit, $channel, $actor);
            $this->scope($student, $pursuit);
            $owner = $this->owner($student);
            $item =
                $this->records->find($student, $pursuit, $id) ??
                throw new RecordNotFoundException("Advising check-in not found.");
            if (
                $item->facts()["owner_user_id"] !== $owner?->value &&
                ($channel === "owner" || $action !== "cancel")
            ) {
                throw new AccessDeniedException(
                    "This check-in belongs to an earlier account link. Staff must cancel it.",
                );
            }
            $intent = $item->intent($action, $version, $message);
            if (($result = $item->retry($action, $actor, $intent)) === null) {
                $result = $item->change($action, $actor, $version, $message, $this->now());
                $this->records->save($item, $version);
                $this->audit->record($actor, "advising.checkin_" . $action, $id);
            }
            $this->authorize($student, $pursuit, $channel, $actor);
            if ($owner?->value !== $this->owner($student)?->value) {
                throw new ConcurrentModificationException(
                    "Student account changed. Refresh advising check-ins.",
                );
            }
            return $result;
        });
    }

    private function now(): string
    {
        return $this->clock->now()->setTimezone(new DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z");
    }
}
