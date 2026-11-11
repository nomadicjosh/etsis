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
use Domain\Transfer\Repository\TransferAwardRepository;
use Domain\Transfer\Repository\TransferCreditRepository;
use Domain\Transfer\ValueObject\TransferCreditId;
use Throwable;

/** Source chronology is a registrar decision, independent from local posting terms and awarded credit. */
final readonly class TransferPeriodService
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
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $award,
            $academicOn,
            $version,
            $reason,
            $actor,
        ): array {
            $this->lock($student, $award, $actor);
            [$root, $facts] = $this->credits->periodRecord($student, $award, new HistoryReadBudget());
            if ($facts["version"] !== $version) {
                throw new ConcurrentModificationException(
                    "Reload the transfer award before reviewing its source period.",
                );
            }
            if (
                $root->playhead() < 1 ||
                $root->source() === null ||
                !in_array($facts["status"], ["A", "N"], true)
            ) {
                self::invalid(
                    "academic_on",
                    "A recorded active transfer award is required. " .
                        "Review untracked award history or reinstate a voided award first.",
                );
            }
            $this->date($academicOn, $root->source()["completed_on"]);
            if ($root->academicOn() === $academicOn) {
                self::invalid(
                    "academic_on",
                    "Choose a different source period start, or explicitly clear a known date.",
                );
            }
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
            $effective =
                $academicOn === null ? $now : new DateTimeImmutable($academicOn, $now->getTimezone());
            new EventContext($actor, $effective, $operation, reason: $reason);
            $data = [
                "kind" => "transfer-period-review",
                "student_id" => $student->value,
                "award_id" => $award->value,
                "credit_id" => $facts["id"],
                "operation_id" => $operation->value,
                "actor_id" => $actor->value,
                "expected_version" => $root->playhead(),
                "credit_version" => $version,
                "previous_decision_id" => $root->decision(),
                "previous_academic_on" => $root->academicOn(),
                "academic_on" => $academicOn,
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
                ($d["kind"] ?? null) !== "transfer-period-review" ||
                ($d["student_id"] ?? null) !== $student->value ||
                ($d["award_id"] ?? null) !== $award->value ||
                !is_int($d["expires_at"] ?? null) ||
                !is_int($d["expected_version"] ?? null) ||
                !is_int($d["credit_version"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            self::invalid("review_token", "Review the source academic period again.");
        }
        if ($d["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This source-period review belongs to another registrar.");
        }
        return $this->transactions->run(function () use ($student, $award, $actor, $d): array {
            $this->lock($student, $award, $actor);
            [$root, $facts] = $this->credits->periodRecord($student, $award, new HistoryReadBudget());
            if ($facts["id"] !== $d["credit_id"]) {
                throw new CorruptHistoryException();
            }
            $operation = new EntityId($d["operation_id"]);
            $event = TransferAcademicPeriodWasRecorded::occur(
                new TransferCreditId($award->value),
                [
                    "decision_id" => $operation->value,
                    "previous_decision_id" => $d["previous_decision_id"],
                    "previous_academic_on" => $d["previous_academic_on"],
                    "academic_on" => $d["academic_on"],
                ],
                ["__aggregate_type" => "transfer_credit"],
            )->withPlayhead($d["expected_version"] + 1);
            // Original retries are resolved before expiry/current-state checks. A failed new decision
            // rolls its event, receipt and projection back together in this same transaction.
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
            if ($receipt["event_ids"] === [(string) $event->eventId()]) {
                if (
                    $d["expires_at"] <= $this->clock->now()->getTimestamp() ||
                    $facts["version"] !== $d["credit_version"]
                ) {
                    throw new ConcurrentModificationException(
                        "The transfer award or review changed. Review its source period again.",
                    );
                }
                $this->date(
                    $d["academic_on"],
                    ($root->source() ?? throw new CorruptHistoryException())["completed_on"],
                );
                $root->acceptHistorical($event);
                $current = $root->credit() ?? throw new CorruptHistoryException();
                $this->records->project($current, $facts["version"]);
                $this->records->track(
                    $root->source() ?? throw new CorruptHistoryException(),
                    $current,
                );
                $this->audit->record($actor, "transfer.source_period_recorded", $award);
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
            [$root] = $this->credits->periodRecord($student, $award, $budget);
            $page = $budget->page($this->history, $this->stream($award), $cursor);
            $items = [];
            foreach ($page->events as $stored) {
                $e = $stored->event;
                $period = $e instanceof TransferAcademicPeriodWasRecorded;
                $p = $e->payload();
                $items[] = [
                    "action" => $period
                        ? "period_recorded"
                        : ($e instanceof \Domain\Transfer\Event\TransferCreditWasEstablished
                            ? $p["provenance"]
                            : $p["action"]),
                    "previous_academic_on" => $period ? $p["previous_academic_on"] : null,
                    "academic_on" => $period ? $p["academic_on"] : null,
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

    private function date(
        #[\SensitiveParameter] ?string $date,
        #[\SensitiveParameter] string $completed,
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
            self::invalid("academic_on", "Use a real source period start no later than completion or today.");
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
