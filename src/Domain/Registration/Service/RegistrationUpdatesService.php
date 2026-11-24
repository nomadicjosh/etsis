<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Registration\Repository\RegistrationUpdatesRepository;
use Domain\Registration\Waitlist\WaitlistLedger;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class RegistrationUpdatesService
{
    public function __construct(
        private RegistrationUpdatesRepository $updates,
        private PersonAccountRepository $people,
        private AdmissionsRepository $admissions,
        private WaitlistLedger $waitlists,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    public function page(
        bool $unreadOnly = true,
        ?string $afterAt = null,
        ?EntityId $after = null,
    ): array {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        if (($afterAt === null) !== ($after === null)) {
            throw new InvalidArgumentException(
                "Use the complete registration update cursor.",
            );
        }
        if ($afterAt !== null) {
            $date = DateTimeImmutable::createFromFormat(
                "!Y-m-d\TH:i:s.u\Z",
                $afterAt,
            );
            if (!$date || $date->format("Y-m-d\TH:i:s.u\Z") !== $afterAt) {
                throw new InvalidArgumentException(
                    "Use a valid registration update cursor.",
                );
            }
        }
        $rows = $this->updates->page(
            $actor,
            $person->id,
            $unreadOnly,
            $afterAt,
            $after,
        );
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $items = array_map(
            static fn(array $r): array => [
                "id" => $r["id"],
                "revision" => (int) $r["revision"],
                "status" => $r["status"],
                "recorded_at" => $r["recorded_at"],
                "unread" => $r["read_at"] === null,
                "course_code" => $r["course_code"],
                "course_title" => $r["course_title"],
                "section_code" => $r["section_code"],
                "term_code" => $r["term_code"],
            ],
            $rows,
        );
        if ($this->person($actor)->id->value !== $person->id->value) {
            throw new AccessDeniedException();
        }
        return [
            "items" => $items,
            "next" => $more
                ? [
                    "at" => $rows[array_key_last($rows)]["recorded_at"],
                    "id" => $rows[array_key_last($rows)]["id"],
                ]
                : null,
        ];
    }

    private function person(EntityId $actor): \Domain\Person\Person
    {
        $person = $this->people->findByUser($actor);
        if (
            $this->access->authenticatedUserId()->value !== $actor->value ||
            $person?->ownerUserId?->value !== $actor->value
        ) {
            throw new AccessDeniedException(
                'Registration updates require this account\'s own person record.',
            );
        }
        return $person;
    }

    public function delete(EntityId $notice, int $revision): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        if ($revision < 1 || $revision > 2000) {
            throw new InvalidArgumentException(
                "Reload the registration update before deleting it.",
            );
        }
        return $this->transactions->run(function () use (
            $actor,
            $person,
            $notice,
            $revision,
        ): array {
            $this->admissions->lockPerson($person->id);
            $this->person($actor);
            $row = $this->updates->owned($actor, $person->id, $notice);
            if ($row === null) {
                return ["deleted" => true]; // An absent/foreign reference reveals no other account's state.
            }
            $this->updates->lock($notice);
            if ((int) $row["revision"] !== $revision) {
                throw new ConcurrentModificationException(
                    "Reload the current registration update.",
                );
            }
            $this->updates->delete($actor, $person->id, $notice);
            $this->audit->record(
                $actor,
                "registration.update_deleted",
                $notice,
            );
            if (
                $this->person($actor)->id->value !== $person->id->value ||
                $this->updates->owned($actor, $person->id, $notice) !== null
            ) {
                throw new ConcurrentModificationException(
                    "The notification changed before deletion completed.",
                );
            }
            return ["deleted" => true];
        });
    }

    public function read(EntityId $notice, int $revision): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        if ($revision < 1 || $revision > 2000) {
            throw new InvalidArgumentException(
                "Reload the registration update before marking it read.",
            );
        }
        return $this->transactions->run(function () use (
            $actor,
            $person,
            $notice,
            $revision,
        ): array {
            $this->admissions->lockPerson($person->id);
            $this->person($actor);
            $this->updates->owned($actor, $person->id, $notice) ??
                throw new AccessDeniedException();
            $this->updates->lock($notice);
            $row =
                $this->updates->owned($actor, $person->id, $notice) ??
                throw new AccessDeniedException();
            if (
                (int) $row["revision"] !== $revision ||
                $row["person_id"] !== $person->id->value
            ) {
                throw new ConcurrentModificationException(
                    "Reload the current registration update.",
                );
            }
            $root = $this->waitlists->load(
                new EntityId($row["section_id"]),
                WaitlistLedger::budget(),
            );
            $receipt = $root->receipt(new EntityId($row["operation_id"]));
            $entry = $root->entries()[$row["entry_id"]] ?? null;
            if (
                $receipt === null ||
                $entry === null ||
                $receipt["version"] !== $revision ||
                $receipt["entry_id"] !== $row["entry_id"] ||
                $receipt["action"] !== $row["status"] ||
                $entry["student_id"] !== $row["student_id"] ||
                $entry["person_id"] !== $row["person_id"] ||
                $entry["status"] !== $row["status"]
            ) {
                throw new CorruptHistoryException();
            }
            if ($row["read_at"] === null) {
                $at = $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d\TH:i:s.u\Z");
                $this->updates->markRead($actor, $notice, $at);
                $this->audit->record(
                    $actor,
                    "registration.update_read",
                    $notice,
                );
                $row["read_at"] = $at;
            }
            $currentPerson = $this->person($actor);
            if (
                $currentPerson->id->value !== $person->id->value ||
                $this->updates->owned($actor, $person->id, $notice) != $row
            ) {
                throw new ConcurrentModificationException(
                    "The registration update changed before completion.",
                );
            }
            return ["unread" => false];
        });
    }
}
