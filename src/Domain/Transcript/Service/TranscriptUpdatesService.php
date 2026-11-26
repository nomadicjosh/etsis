<?php

declare(strict_types=1);

namespace Domain\Transcript\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Person;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transcript\Repository\TranscriptRepository;
use Domain\Transcript\Repository\TranscriptUpdatesRepository;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans_html;

/** Private operational status only: no transcript, recipient, release confirmation or delivery evidence. */
final readonly class TranscriptUpdatesService
{
    public function __construct(
        private TranscriptUpdatesRepository $updates,
        private TranscriptRepository $records,
        private PersonAccountRepository $accounts,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    private function person(EntityId $actor): Person
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
            throw new InvalidArgumentException(trans_html("Use the complete transcript notification cursor."));
        }
        if ($afterAt !== null) {
            $at = DateTimeImmutable::createFromFormat("!Y-m-d\TH:i:s.u\Z", $afterAt);
            if (!$at || $at->format("Y-m-d\TH:i:s.u\Z") !== $afterAt) {
                throw new InvalidArgumentException(trans_html("Use a valid transcript notification cursor."));
            }
        }
        $inventory = $this->updates->page($actor, $person->id, $unreadOnly, $afterAt, $after);
        $rows = array_slice($inventory, 0, 20);
        $items = array_map(
            static fn(array $row): array => array_intersect_key(
                $row,
                array_flip(["id", "status", "recorded_at", "requested_at", "delivery"]),
            ) + ["revision" => (int) $row["revision"], "unread" => $row["read_at"] === null],
            $rows,
        );
        if (
            $this->person($actor)->id->value !== $person->id->value ||
            $inventory !== $this->updates->page($actor, $person->id, $unreadOnly, $afterAt, $after)
        ) {
            throw new ConcurrentModificationException(
                trans_html("Transcript notifications changed. Reopen the list."),
            );
        }
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return [
            "items" => $items,
            "next" => count($inventory) > 20 ? ["at" => $last["recorded_at"], "id" => $last["id"]] : null,
        ];
    }

    /** Uses fulfillment's existing student/person/request lock order, then the disposable notice. */
    private function lock(EntityId $actor, Person $person, EntityId $notice, array $row): array
    {
        $this->records->lockStudent(new EntityId($row["student_id"]));
        $this->records->lockPerson($person->id);
        $this->records->lockRequest(new EntityId($row["request_id"]));
        $this->updates->lock($notice);
        if (
            $this->person($actor)->id->value !== $person->id->value ||
            $this->updates->owned($actor, $person->id, $notice) !== $row
        ) {
            throw new ConcurrentModificationException(
                trans_html("Transcript notifications changed. Reopen the list."),
            );
        }
        $request = new EntityId($row["request_id"]);
        $record = $this->records->request($request);
        $latest = $this->records->latest($request);
        if (
            !in_array($row["status"], ["ready", "fulfilled"], true) ||
            $record["status"] !== $row["status"] ||
            (int) $record["version"] !== (int) $row["revision"] ||
            ($latest["id"] ?? null) !== $row["document_id"] ||
            (int) $latest["prepared_version"] !== (int) $record["version"] - (int) ($row["status"] === "fulfilled")
        ) {
            throw new ConcurrentModificationException(
                trans_html("The transcript request changed. Reopen the notification."),
            );
        }
        return ["record" => $record, "latest" => $latest];
    }

    private function verify(
        EntityId $actor,
        Person $person,
        EntityId $notice,
        ?array $row,
        #[\SensitiveParameter] array $context,
    ): void {
        $request = new EntityId($context["record"]["id"]);
        if (
            $this->person($actor)->id->value !== $person->id->value ||
            $this->updates->owned($actor, $person->id, $notice) != $row ||
            $this->records->request($request) !== $context["record"] ||
            $this->records->latest($request) !== $context["latest"]
        ) {
            throw new ConcurrentModificationException(
                trans_html("The transcript notification changed before completion."),
            );
        }
    }

    public function detail(EntityId $notice): array
    {
        $actor = $this->access->authenticatedUserId();
        $person = $this->person($actor);
        return $this->transactions->run(function () use ($actor, $person, $notice): array {
            $row = $this->updates->owned($actor, $person->id, $notice) ?? throw new AccessDeniedException();
            $context = $this->lock($actor, $person, $notice, $row);
            $this->verify($actor, $person, $notice, $row, $context);
            $record = $context["record"];
            return [
                "requested_at" => $record["requested_at"],
                "delivery" => $record["delivery"],
                "status" => $record["status"],
                "recorded_at" => $row["recorded_at"],
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
            $row = $this->updates->owned($actor, $person->id, $notice);
            if ($row === null && $delete) {
                return ["deleted" => true];
            }
            if ($row === null) {
                throw new AccessDeniedException();
            }
            $context = $this->lock($actor, $person, $notice, $row);
            if ((int) $row["revision"] !== $revision) {
                throw new ConcurrentModificationException(
                    trans_html("Reload the current transcript notification."),
                );
            }
            if ($delete) {
                $this->updates->delete($actor, $person->id, $notice);
                $this->audit->record($actor, "transcript.notification_deleted", $notice);
            } elseif ($row["read_at"] === null) {
                $row["read_at"] = $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format("Y-m-d\TH:i:s.u\Z");
                $this->updates->markRead($actor, $notice, $row["read_at"]);
                $this->audit->record($actor, "transcript.notification_read", $notice);
            }
            $this->verify($actor, $person, $notice, $delete ? null : $row, $context);
            return $delete ? ["deleted" => true] : ["unread" => false];
        });
    }
}
