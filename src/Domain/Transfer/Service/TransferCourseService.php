<?php

declare(strict_types=1);

namespace Domain\Transfer\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Service\PublishedCourseReader;
use Domain\Communications\Service\MessageCipher;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Event\TransferCourseWasAccepted;
use Domain\Transfer\Event\TransferCreditWasEstablished;
use Domain\Transfer\Repository\TransferAwardRepository;
use Domain\Transfer\Repository\TransferCreditRepository;
use Domain\Transfer\ValueObject\TransferCreditFacts;
use Domain\Transfer\ValueObject\TransferCreditId;
use Throwable;

/** A new acceptance decision within the same institutional course; never inferred earlier evidence. */
final readonly class TransferCourseService
{
    public function __construct(
        private TransferCreditLedger $credits,
        private TransferCreditRepository $records,
        private TransferAwardRepository $awards,
        private RegistrationRepository $registration,
        private PublishedCourseReader $courses,
        private HistoricalEventStore $history,
        private UnitOfWork $transactions,
        private StudentAccess $access,
        private MessageCipher $cipher,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string,mixed> */
    public function choices(EntityId $student, EntityId $award, ?string $cursor = null): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $award, $cursor, $actor): array {
            $this->lock($student, $award, $actor);
            [, $facts] = $this->credits->decisionRecord($student, $award, new HistoryReadBudget());
            $page = $this->courses->page(new EntityId($facts["course_id"]), $cursor);
            $this->actor($actor);
            return $page;
        });
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $student,
        EntityId $award,
        EntityId $courseVersion,
        int $version,
        #[\SensitiveParameter] string $effectiveOn,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $award,
            $courseVersion,
            $version,
            $effectiveOn,
            $reason,
            $actor,
        ): array {
            $this->lock($student, $award, $actor);
            $budget = new HistoryReadBudget();
            [$root, $facts] = $this->credits->decisionRecord($student, $award, $budget);
            $record = array_replace($this->awards->get($student, $award), $root->source() ?? []);
            if ($facts["version"] !== $version) {
                throw new ConcurrentModificationException(
                    "Reload the award before reviewing its accepted course.",
                );
            }
            $this->active($facts);
            $this->date($effectiveOn, $record["completed_on"]);
            if ($root->courseVersion() === $courseVersion->value) {
                self::invalid("course_version_id", "Select a different accepted publication.");
            }
            $definition = $this->courses->version(new EntityId($facts["course_id"]), $courseVersion, $budget);
            $reason = trim($reason);
            if (
                $reason === "" ||
                mb_strlen($reason) > 1000 ||
                !mb_check_encoding($reason, "UTF-8") ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
            ) {
                self::invalid("reason", "Explain the acceptance evidence in at most 1,000 characters.");
            }
            $operation = EntityId::generate();
            $previous =
                $root->decision() ??
                PublishedGradeId::forVersion($operation, "transfer.observation." . $award->value)->value;
            $now = $this->clock->now();
            $d = [
                "kind" => "transfer-course-review",
                "student_id" => $student->value,
                "award_id" => $award->value,
                "credit_id" => $facts["id"],
                "operation_id" => $operation->value,
                "actor_id" => $actor->value,
                "expected_version" => max(1, $root->playhead()),
                "credit_version" => $version,
                "previous_decision_id" => $previous,
                "previous_course_version_id" => $root->courseVersion(),
                "course_version_id" => $courseVersion->value,
                "course_id" => $facts["course_id"],
                "course_code" => $definition->definition->code,
                "course_title" =>
                    $definition->definition->text->transcriptTitle ?? $definition->definition->title,
                "record_revision" => $this->revision($record, $facts),
                "effective_at" => new DateTimeImmutable($effectiveOn, $now->getTimezone())->format(DATE_ATOM),
                "reason" => $reason,
                "expires_at" => $now->getTimestamp() + 1200,
            ];
            $this->actor($actor);
            return [
                "previous_course_version_id" => $root->courseVersion(),
                "before" => [
                    "course_code" => $facts["course_code"],
                    "course_title" => $facts["course_title"],
                ],
                "after" => ["course_code" => $d["course_code"], "course_title" => $d["course_title"]],
                "course_version_id" => $courseVersion->value,
                "effective_on" => $effectiveOn,
                "reason" => $reason,
                "earlier_history_unknown" =>
                    $root->playhead() === 0 || $root->provenance() === "observed_existing",
                "review_token" => $this->cipher->seal(json_encode($d, JSON_THROW_ON_ERROR)),
            ];
        });
    }

    /** @return array{operation_id:string,first_version:int,last_version:int,event_ids:list<string>} */
    public function commit(EntityId $student, EntityId $award, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize();
        try {
            if (strlen($token) > 16384) {
                throw new \InvalidArgumentException();
            }
            $d = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($d) ||
                ($d["kind"] ?? null) !== "transfer-course-review" ||
                ($d["student_id"] ?? null) !== $student->value ||
                ($d["award_id"] ?? null) !== $award->value ||
                !is_int($d["expires_at"] ?? null) ||
                !is_int($d["expected_version"] ?? null) ||
                !is_int($d["credit_version"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            self::invalid("review_token", "Review the accepted course definition again.");
        }
        if ($d["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This review belongs to another registrar.");
        }
        return $this->transactions->run(function () use ($student, $award, $actor, $d): array {
            $this->lock($student, $award, $actor);
            $budget = new HistoryReadBudget();
            [$root, $facts] = $this->credits->decisionRecord($student, $award, $budget);
            if ($facts["id"] !== $d["credit_id"]) {
                throw new CorruptHistoryException();
            }
            $operation = new EntityId($d["operation_id"]);
            $record = array_replace($this->awards->get($student, $award), $root->source() ?? []);
            if ($root->playhead() === 0) {
                $this->fresh($d, $record, $facts);
                $root = $this->credits->observe($record, $actor, $operation);
            }
            $event = TransferCourseWasAccepted::occur(
                new TransferCreditId($award->value),
                array_intersect_key(
                    $d,
                    array_flip([
                        "previous_decision_id",
                        "previous_course_version_id",
                        "course_version_id",
                        "course_id",
                        "course_code",
                        "course_title",
                    ]),
                ) + ["decision_id" => $operation->value],
                ["__aggregate_type" => "transfer_credit"],
            )->withPlayhead($d["expected_version"] + 1);
            $receipt = $this->history->append(
                $this->stream($award),
                $operation,
                $d["expected_version"],
                new EventContext(
                    $actor,
                    new DateTimeImmutable($d["effective_at"]),
                    $operation,
                    new EntityId($d["previous_decision_id"]),
                    $d["reason"],
                ),
                [$event],
            );
            // An original retry returns its receipt before checking expiry or a newer accepted state.
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                $this->fresh($d, $record, $facts);
                $definition = $this->courses->version(
                    new EntityId($facts["course_id"]),
                    new EntityId($d["course_version_id"]),
                    $budget,
                );
                if (
                    $definition->definition->code !== $d["course_code"] ||
                    ($definition->definition->text->transcriptTitle ?? $definition->definition->title) !==
                        $d["course_title"]
                ) {
                    throw new CorruptHistoryException();
                }
                $root->acceptHistorical($event);
                $this->records->acceptCourse(
                    new EntityId($facts["id"]),
                    $definition,
                    $d["previous_course_version_id"] === null
                        ? null
                        : new EntityId($d["previous_course_version_id"]),
                );
                $current = $root->credit() ?? throw new CorruptHistoryException();
                $this->records->project($current, $facts["version"]);
                $this->records->track($root->source() ?? throw new CorruptHistoryException(), $current);
                $this->audit->record($actor, "transfer.course_accepted", $award);
            }
            $this->actor($actor);
            return $receipt;
        });
    }

    /** Curated frozen decisions, with no raw payload, protected reason or actor.
     * @return array<string,mixed>
     */
    public function history(EntityId $student, EntityId $award, ?string $cursor = null): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $award, $cursor, $actor): array {
            $this->lock($student, $award, $actor);
            $budget = new HistoryReadBudget();
            [$root] = $this->credits->decisionRecord($student, $award, $budget);
            $page = $budget->page($this->history, $this->stream($award), $cursor);
            $items = [];
            foreach ($page->events as $stored) {
                $event = $stored->event;
                $p = $event->payload();
                if (
                    !($event instanceof TransferCourseWasAccepted) &&
                    !($event instanceof TransferCreditWasEstablished)
                ) {
                    continue;
                }
                $initial = $event instanceof TransferCreditWasEstablished;
                $items[] = [
                    "action" => $initial ? $p["provenance"] : "course_accepted",
                    "course_version_id" => $initial
                        ? $event->metaParam("course_version_id", null)
                        : $p["course_version_id"],
                    "previous_course_version_id" => $initial ? null : $p["previous_course_version_id"],
                    "course_code" => $initial ? $p["credit"]["course_code"] : $p["course_code"],
                    "course_title" => $initial ? $p["credit"]["course_title"] : $p["course_title"],
                    "effective_at" => $stored->context->describe()["effective_at"],
                    "recorded_at" => $stored->recordedAt->format(DATE_ATOM),
                ];
            }
            $this->actor($actor);
            return [
                "items" => $items,
                "next_cursor" => $page->nextCursor,
                "earlier_history_unknown" =>
                    $root->playhead() === 0 || $root->provenance() === "observed_existing",
            ];
        });
    }

    /** @param array<string,mixed> $d
     * @param array<string,mixed> $record
     * @param array<string,mixed> $facts */
    private function fresh(
        #[\SensitiveParameter] array $d,
        #[\SensitiveParameter] array $record,
        #[\SensitiveParameter] array $facts,
    ): void {
        if (
            $d["expires_at"] <= $this->clock->now()->getTimestamp() ||
            $facts["version"] !== $d["credit_version"] ||
            !hash_equals($d["record_revision"], $this->revision($record, $facts))
        ) {
            throw new ConcurrentModificationException(
                "The award or review changed. Review its accepted course again.",
            );
        }
        $this->active($facts);
        $this->date(substr($d["effective_at"], 0, 10), $record["completed_on"]);
    }
    /** @param array<string,mixed> $record
     * @param array<string,mixed> $facts */
    private function revision(
        #[\SensitiveParameter] array $record,
        #[\SensitiveParameter] array $facts,
    ): string {
        $source = array_map(static fn(string $key): mixed => $record[$key], [
            "id",
            "student_id",
            "equivalency_id",
            "completed_on",
            "term_id",
            "term_code",
            "institution_id",
            "institution_code",
            "institution_name",
            "external_course_id",
            "external_code",
            "external_title",
        ]);
        $credit = array_map(static fn(string $key): mixed => $facts[$key], TransferCreditFacts::FIELDS);
        return hash("sha256", json_encode([$source, $credit], JSON_THROW_ON_ERROR));
    }
    /** @param array<string,mixed> $facts */
    private function active(array $facts): void
    {
        if (!in_array($facts["status"], ["A", "N"], true)) {
            self::invalid(
                "course_version_id",
                "Reinstate a voided award before reviewing its accepted course.",
            );
        }
    }
    private function date(#[\SensitiveParameter] string $date, #[\SensitiveParameter] string $completed): void
    {
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 ||
            !($parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date)) ||
            $parsed->format("Y-m-d") !== $date ||
            $date < $completed ||
            $date > $this->clock->now()->format("Y-m-d")
        ) {
            self::invalid("effective_on", "Use a real effective date between external completion and today.");
        }
    }
    private function authorize(): EntityId
    {
        $actor = $this->access->requirePermission("sis:transfer:manage");
        $this->access->requirePermission("sis:student:read");
        return $actor;
    }
    private function actor(EntityId $actor): void
    {
        if ($this->authorize()->value !== $actor->value) {
            throw new AccessDeniedException("The transfer decision actor changed.");
        }
    }
    private function lock(EntityId $student, EntityId $award, EntityId $actor): void
    {
        $this->registration->lockStudent($student);
        $row = $this->records->award($award);
        if ($row["student_id"] !== $student->value) {
            throw new AccessDeniedException("This award belongs to another student.");
        }
        $this->awards->lock($award, new EntityId($row["credit_id"]));
        $this->actor($actor);
    }
    private function stream(EntityId $award): StreamReference
    {
        return new StreamReference(new TransferCreditId($award->value), "transfer_credit");
    }
    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
