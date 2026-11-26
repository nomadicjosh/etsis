<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\Repository\AcademicUpdatesRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans_html;

final readonly class AcademicUpdatesService
{
    public function __construct(
        private AcademicUpdatesRepository $updates,
        private PersonAccountRepository $accounts,
        private AdmissionsRepository $people,
        private InstitutionalCreditLedger $credits,
        private AcademicCreditRepository $records,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    private function person(EntityId $actor): \Domain\Person\Person
    {
        $person = $this->accounts->findByUser($actor);
        if (
            $person?->ownerUserId?->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        return $person;
    }

    public function page(bool $unreadOnly = true, ?string $afterAt = null, ?EntityId $after = null): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        if (($afterAt === null) !== ($after === null)) {
            throw new InvalidArgumentException(trans_html("Use the complete academic notification cursor."));
        }
        if ($afterAt !== null) {
            $at = DateTimeImmutable::createFromFormat("!Y-m-d\TH:i:s.u\Z", $afterAt);
            if (!$at || $at->format("Y-m-d\TH:i:s.u\Z") !== $afterAt) {
                throw new InvalidArgumentException(trans_html("Use a valid academic notification cursor."));
            }
        }
        $inventory = $this->updates->page($actor, $person->id, $unreadOnly, $afterAt, $after);
        $rows = array_slice($inventory, 0, 20);
        $items = array_map(
            static fn(array $row): array => array_intersect_key(
                $row,
                array_flip([
                    "id",
                    "status",
                    "recorded_at",
                    "course_code",
                    "course_title",
                    "section_code",
                    "term_code",
                ]),
            ) + ["revision" => (int) $row["revision"], "unread" => $row["read_at"] === null],
            $rows,
        );
        if (
            $this->person($actor)->id->value !== $person->id->value ||
            $inventory !== $this->updates->page($actor, $person->id, $unreadOnly, $afterAt, $after)
        ) {
            throw new ConcurrentModificationException(trans_html("Academic notifications changed. Refresh the list."));
        }
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return [
            "items" => $items,
            "next" => count($inventory) > 20 ? ["at" => $last["recorded_at"], "id" => $last["id"]] : null,
        ];
    }

    public function detail(EntityId $notice): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        return $this->transactions->run(function () use ($actor, $person, $notice): array {
            $this->people->lockPerson($person->id);
            $row = $this->updates->owned($actor, $person->id, $notice) ?? throw new AccessDeniedException();
            $student = new EntityId($row["student_id"]);
            $this->records->lockStudent($student);
            $facts = $this->credits->auditFacts(
                new EntityId($row["credit_id"]),
                $student,
                new HistoryReadBudget(),
            );
            if (
                $facts["credit"]["source_id"] !== $row["enrollment_id"] ||
                $this->records->projection(new EntityId($row["credit_id"])) != $facts["credit"]
            ) {
                throw new ConcurrentModificationException(
                    trans_html("The academic record changed. Open the notification again."),
                );
            }
            $this->person($actor);
            if ($this->updates->owned($actor, $person->id, $notice) !== $row) {
                throw new ConcurrentModificationException(
                    trans_html("Academic notifications changed. Refresh the list."),
                );
            }
            return [
                "course_code" => $facts["credit"]["course_code"],
                "course_title" => $facts["credit"]["course_title"],
                "grade" => $facts["grade"]["code"] ?? null,
                "status" => $facts["credit"]["status"],
                "term_code" =>
                    $facts["retained_term_code"] ??
                    $this->records->term(new EntityId($facts["credit"]["term_id"]))["code"],
                "completed_milli" => $facts["credit"]["completed_milli"],
            ];
        });
    }

    public function read(EntityId $notice, int $revision): array
    {
        return $this->mutate($notice, $revision, false);
    }

    public function delete(EntityId $notice, int $revision): array
    {
        return $this->mutate($notice, $revision, true);
    }

    private function mutate(EntityId $notice, int $revision, bool $delete): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        return $this->transactions->run(function () use ($actor, $person, $notice, $revision, $delete): array {
            $this->people->lockPerson($person->id);
            $this->person($actor);
            $row = $this->updates->owned($actor, $person->id, $notice);
            if ($row === null && $delete) {
                return ["deleted" => true];
            }
            if ($row === null) {
                throw new AccessDeniedException();
            }
            $this->updates->lock($notice);
            $row = $this->updates->owned($actor, $person->id, $notice) ?? throw new AccessDeniedException();
            if ((int) $row["revision"] !== $revision) {
                throw new ConcurrentModificationException(trans_html("Reload the current academic notification."));
            }
            if ($delete) {
                $this->updates->delete($actor, $person->id, $notice);
                $this->audit->record($actor, "academic.notification_deleted", $notice);
            } elseif ($row["read_at"] === null) {
                $row["read_at"] = $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d\TH:i:s.u\Z");
                $this->updates->markRead($actor, $notice, $row["read_at"]);
                $this->audit->record($actor, "academic.notification_read", $notice);
            }
            if (
                $this->person($actor)->id->value !== $person->id->value ||
                $this->updates->owned($actor, $person->id, $notice) != ($delete ? null : $row)
            ) {
                throw new ConcurrentModificationException(
                    trans_html("Academic notifications changed. Refresh the list."),
                );
            }
            return $delete ? ["deleted" => true] : ["unread" => false];
        });
    }
}
