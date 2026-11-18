<?php

declare(strict_types=1);

namespace Domain\Transfer\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
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
use Domain\Transfer\Event\TransferAcademicPeriodWasRecorded;
use Domain\Transfer\Event\TransferAcademicDatesWereRecorded;
use Domain\Transfer\Event\TransferPostingTermWasChanged;
use Domain\Transfer\Repository\TransferAwardRepository;
use Domain\Transfer\Repository\TransferCreditRepository;
use Domain\Transfer\ValueObject\TransferCreditId;
use Throwable;

/** Shared registrar review of source chronology and local posting placement, preserving awarded credit. */
final readonly class TransferAcademicDetailsService
{
    public function __construct(
        private TransferCreditLedger $credits,
        private TransferCreditRepository $records,
        private TransferAwardRepository $awards,
        private RegistrationRepository $registration,
        private HistoricalEventStore $history,
        private UnitOfWork $transactions,
        private StudentAccess $access,
        private MessageCipher $cipher,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /** Required nullable date: clearing a known fact is an explicit command.
     * @return array<string,mixed>
     */
    public function review(
        EntityId $student,
        EntityId $award,
        #[\SensitiveParameter] ?string $academicOn,
        int $version,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] ?string $completedOn = null,
        #[\SensitiveParameter] ?string $effectiveOn = null,
        ?EntityId $term = null,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $award,
            $academicOn,
            $version,
            $reason,
            $actor,
            $completedOn,
            $effectiveOn,
            $term,
        ): array {
            $this->lock($student, $award, $actor);
            $budget = new HistoryReadBudget();
            [$root, $facts] = $this->credits->decisionRecord($student, $award, $budget);
            if ($facts["version"] !== $version) {
                throw new ConcurrentModificationException(
                    "Reload the transfer award before reviewing its academic details.",
                );
            }
            if (!in_array($facts["status"], ["A", "N"], true)) {
                self::invalid(
                    "completed_on",
                    "Reinstate the voided award before reviewing academic details.",
                );
            }
            $baseline = $root->playhead() === 0;
            $record = $baseline
                ? $this->credits->verified($this->awards->get($student, $award), $budget)
                : null;
            $previousCompleted = $root->source()["completed_on"] ?? $record["completed_on"];
            $completedOn ??= $previousCompleted;
            $today = $this->clock->now()->format("Y-m-d");
            $effectiveOn ??= $today;
            $this->date($completedOn, $today, "completed_on");
            $this->date($academicOn, $completedOn);
            $this->date($effectiveOn, $today, "effective_on");
            if ($effectiveOn < $completedOn) {
                self::invalid("effective_on", "Use an effective date from course completion through today.");
            }
            $source = $root->source() ?? $record;
            $term ??= new EntityId($source["term_id"]);
            $termChange = $term->value !== $source["term_id"];
            $target = $termChange ? $this->records->term($term, lock: true) : null;
            if (!$termChange && $root->academicOn() === $academicOn && $completedOn === $previousCompleted) {
                self::invalid(
                    "academic_on",
                    "Change a term or date, or explicitly clear a known period start.",
                );
            }
            $this->availableDate($student, $award, $completedOn);
            $reason = trim($reason);
            if (
                $reason === "" ||
                mb_strlen($reason) > 1000 ||
                !mb_check_encoding($reason, "UTF-8") ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
            ) {
                self::invalid("reason", "Explain the source evidence in at most 1,000 characters.");
            }
            $operation = EntityId::generate();
            $now = $this->clock->now();
            $effective = new DateTimeImmutable($effectiveOn, $now->getTimezone());
            $previousDecision =
                $root->decision() ??
                \Domain\Gradebook\ValueObject\PublishedGradeId::forVersion(
                    $operation,
                    "transfer.observation." . $award->value,
                )->value;
            new EventContext($actor, $effective, $operation, reason: $reason);
            $data = [
                "kind" => "transfer-dates-review",
                "student_id" => $student->value,
                "award_id" => $award->value,
                "credit_id" => $facts["id"],
                "operation_id" => $operation->value,
                "actor_id" => $actor->value,
                "expected_version" => $root->playhead(),
                "credit_version" => $version,
                "previous_decision_id" => $previousDecision,
                "previous_academic_on" => $root->academicOn(),
                "academic_on" => $academicOn,
                "previous_completed_on" => $previousCompleted,
                "completed_on" => $completedOn,
                "previous_term_id" => $source["term_id"],
                "term_id" => $term->value,
                "previous_term_code" => $source["term_code"],
                "term_code" => $target["code"] ?? $source["term_code"],
                "term_revision" => $target === null ? null : $this->termRevision($target),
                "baseline" => $baseline,
                "record_revision" =>
                    $record === null ? null : hash("sha256", json_encode($record, JSON_THROW_ON_ERROR)),
                "earlier_history_unknown" => $baseline || $root->provenance() === "observed_existing",
                "effective_at" => $effective->format(DATE_ATOM),
                "reason" => $reason,
                "expires_at" => $now->getTimestamp() + 1200,
            ];
            $this->actor($actor);
            return $data + ["review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR))];
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
                !in_array($d["kind"] ?? null, ["transfer-dates-review", "transfer-period-review"], true) ||
                ($d["student_id"] ?? null) !== $student->value ||
                ($d["award_id"] ?? null) !== $award->value ||
                !is_int($d["expires_at"] ?? null) ||
                !is_int($d["expected_version"] ?? null) ||
                !is_int($d["credit_version"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            self::invalid("review_token", "Review the academic details again.");
        }
        if ($d["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This academic-date review belongs to another registrar.");
        }
        return $this->transactions->run(function () use ($student, $award, $actor, $d): array {
            $this->lock($student, $award, $actor);
            $budget = new HistoryReadBudget();
            [$root, $facts] = $this->credits->decisionRecord($student, $award, $budget);
            if ($facts["id"] !== $d["credit_id"]) {
                throw new CorruptHistoryException();
            }
            if ($d["kind"] === "transfer-period-review") {
                return $this->acceptedPeriodReceipt($award, $actor, $d, $budget);
            }
            $operation = new EntityId($d["operation_id"]);
            if ($d["baseline"] && $root->playhead() === 0) {
                $record = $this->credits->verified($this->awards->get($student, $award), $budget);
                if (
                    !hash_equals(
                        $d["record_revision"],
                        hash("sha256", json_encode($record, JSON_THROW_ON_ERROR)),
                    )
                ) {
                    throw new ConcurrentModificationException(
                        "The earlier award facts changed. Review the academic details again.",
                    );
                }
                $root = $this->credits->observe($record, $actor, $operation, $budget);
            }
            $termChange = isset($d["term_id"]) && $d["term_id"] !== $d["previous_term_id"];
            $eventClass = $termChange
                ? TransferPostingTermWasChanged::class
                : TransferAcademicDatesWereRecorded::class;
            $placement = $termChange
                ? array_intersect_key(
                    $d,
                    array_flip(["previous_term_id", "term_id", "previous_term_code", "term_code"]),
                )
                : [];
            $payload =
                [
                    "decision_id" => $operation->value,
                    "previous_decision_id" => $d["previous_decision_id"],
                    "previous_academic_on" => $d["previous_academic_on"],
                    "academic_on" => $d["academic_on"],
                    "previous_completed_on" => $d["previous_completed_on"],
                    "completed_on" => $d["completed_on"],
                ] + $placement;
            $event = $eventClass
                ::occur(new TransferCreditId($award->value), $payload, [
                    "__aggregate_type" => "transfer_credit",
                ])
                ->withPlayhead($d["expected_version"] + ($d["baseline"] ? 2 : 1));
            // Original retries are resolved before expiry/current-state checks. A failed new decision
            // rolls its event, receipt and projection back together in this same transaction.
            $receipt = $this->history->append(
                $this->stream($award),
                $operation,
                $event->playhead() - 1,
                new EventContext(
                    $actor,
                    new DateTimeImmutable($d["effective_at"]),
                    $operation,
                    new EntityId($d["previous_decision_id"]),
                    $d["reason"],
                ),
                [$event],
            );
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                if (
                    $d["expires_at"] <= $this->clock->now()->getTimestamp() ||
                    $facts["version"] !== $d["credit_version"]
                ) {
                    throw new ConcurrentModificationException(
                        "The transfer award or review changed. Review its academic details again.",
                    );
                }
                $this->date($d["completed_on"], $this->clock->now()->format("Y-m-d"), "completed_on");
                $this->date($d["academic_on"], $d["completed_on"]);
                $this->availableDate($student, $award, $d["completed_on"]);
                if ($termChange) {
                    $this->verifyTerm($d);
                }
                $root->acceptHistorical($event);
                $current = $root->credit() ?? throw new CorruptHistoryException();
                $this->records->project($current, $facts["version"]);
                $this->records->track($root->source() ?? throw new CorruptHistoryException(), $current);
                $this->audit->record(
                    $actor,
                    $termChange ? "transfer.posting_term_changed" : "transfer.academic_dates_recorded",
                    $award,
                );
                if ($termChange) {
                    $this->verifyTerm($d);
                }
                if ($d["expires_at"] <= $this->clock->now()->getTimestamp()) {
                    throw new ConcurrentModificationException(
                        "The academic-details review expired during confirmation.",
                    );
                }
            }
            $this->actor($actor);
            return $receipt;
        });
    }

    /** Curated frozen journal page; no raw event, actor, reason or credit/grade payload.
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
                $e = $stored->event;
                $placement = $e instanceof TransferPostingTermWasChanged;
                $dates = $placement || $e instanceof TransferAcademicDatesWereRecorded;
                $period = $dates || $e instanceof TransferAcademicPeriodWasRecorded;
                $p = $e->payload();
                $items[] = [
                    "action" => $placement
                        ? "posting_term_changed"
                        : ($period
                            ? ($dates
                                ? "dates_recorded"
                                : "period_recorded")
                            : ($e instanceof \Domain\Transfer\Event\TransferCourseWasReplaced
                                ? "course_replaced"
                                : ($e instanceof \Domain\Transfer\Event\TransferCourseWasAccepted
                                    ? "course_accepted"
                                    : ($e instanceof \Domain\Transfer\Event\TransferCreditWasEstablished
                                        ? $p["provenance"]
                                        : $p["action"])))),
                    "previous_term_code" => $placement ? $p["previous_term_code"] : null,
                    "term_code" => $placement ? $p["term_code"] : null,
                    "previous_academic_on" => $period ? $p["previous_academic_on"] : null,
                    "academic_on" => $period ? $p["academic_on"] : null,
                    "previous_completed_on" => $dates ? $p["previous_completed_on"] : null,
                    "completed_on" => $dates ? $p["completed_on"] : null,
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

    /** @param array<string,mixed> $data */
    private function verifyTerm(#[\SensitiveParameter] array $data): void
    {
        $term = $this->records->term(new EntityId($data["term_id"]), lock: true);
        if (!hash_equals($data["term_revision"], $this->termRevision($term))) {
            throw new ConcurrentModificationException(
                "The selected term changed. Review the academic details again.",
            );
        }
    }

    /** @param array<string,mixed> $term */
    private function termRevision(array $term): string
    {
        ksort($term);
        return hash("sha256", json_encode($term, JSON_THROW_ON_ERROR));
    }

    private function date(
        #[\SensitiveParameter] ?string $date,
        #[\SensitiveParameter] string $completed,
        string $field = "academic_on",
    ): void {
        if ($date === null) {
            return;
        }
        if (
            preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 ||
            !($parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date)) ||
            $parsed->format("Y-m-d") !== $date ||
            $date > $completed ||
            $date > $this->clock->now()->format("Y-m-d")
        ) {
            self::invalid($field, "Use a real date no later than its related completion date or today.");
        }
    }

    private function availableDate(EntityId $student, EntityId $award, string $date): void
    {
        $row = $this->records->award($award);
        $equivalency = new EntityId($row["equivalency_id"]);
        $candidates = [
            $this->records->existing($student, $equivalency, $date),
            $this->records->corrected($student, $equivalency, $date),
        ];
        foreach ($candidates as $other) {
            if ($other !== null && $other["id"] !== $award->value) {
                self::invalid(
                    "completed_on",
                    "Another award reserves this completion date for the same equivalency.",
                );
            }
        }
    }

    /** Earlier proofs can return only a real, already accepted period decision.
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function acceptedPeriodReceipt(
        EntityId $award,
        EntityId $actor,
        #[\SensitiveParameter] array $data,
        HistoryReadBudget $budget,
    ): array {
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream($award), $cursor);
            foreach ($page->events as $stored) {
                $event = $stored->event;
                $p = $event->payload();
                if (
                    !($event instanceof TransferAcademicPeriodWasRecorded) ||
                    $p["decision_id"] !== $data["operation_id"]
                ) {
                    continue;
                }
                if (
                    $event->playhead() !== $data["expected_version"] + 1 ||
                    $p["previous_decision_id"] !== $data["previous_decision_id"] ||
                    $p["previous_academic_on"] !== $data["previous_academic_on"] ||
                    $p["academic_on"] !== $data["academic_on"] ||
                    $stored->context->actor?->value !== $actor->value ||
                    $stored->context->reason !== $data["reason"] ||
                    $stored->context->effectiveAt?->getTimestamp() !==
                        new DateTimeImmutable($data["effective_at"])->getTimestamp()
                ) {
                    self::invalid("review_token", "Review the academic details again.");
                }
                $receipt = $this->history->append(
                    $this->stream($award),
                    new EntityId($data["operation_id"]),
                    $data["expected_version"],
                    $stored->context,
                    [$event],
                );
                $this->actor($actor);
                return $receipt;
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        self::invalid("review_token", "Refresh this earlier academic-date review before confirming.");
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
            throw new AccessDeniedException("The transfer award belongs to another student.");
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
