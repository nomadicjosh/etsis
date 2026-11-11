<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use DateTimeImmutable;
use Domain\AcademicRecord\Event\AcademicCompletionWasRecorded;
use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\ValueObject\AcademicCreditId;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Throwable;

final readonly class AcademicCompletionService
{
    public function __construct(
        private InstitutionalCreditLedger $credits,
        private AcademicCreditRepository $records,
        private HistoricalEventStore $history,
        private UnitOfWork $transactions,
        private StudentAccess $access,
        private MessageCipher $cipher,
        private Clock $clock,
        private AuditTrail $audit,
    ) {
    }

    /** A required nullable date deliberately distinguishes an explicit clear from an omitted command.
     * @return array<string,mixed>
     */
    public function review(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        #[\SensitiveParameter] ?string $completedOn,
        int $version,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $this->date($completedOn);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $completedOn,
            $version,
            $reason,
            $actor,
        ): array {
            $this->lock($student, $section, $actor);
            [$id, $root, $facts] = $this->credits->decisionRecord($student, $section, $enrollment);
            if ($facts["version"] !== $version) {
                throw new ConcurrentModificationException(
                    "Reload the academic record before reviewing its completion date.",
                );
            }
            $this->posted($enrollment, $section, $facts, $root->playhead(), $root->grade() !== null);
            if ($root->completedOn() === $completedOn) {
                self::invalid(
                    "completed_on",
                    "Choose a different actual completion date, or clear a known date.",
                );
            }
            $reason = trim($reason);
            if (
                $reason === "" ||
                mb_strlen($reason) > 1000 ||
                !mb_check_encoding($reason, "UTF-8") ||
                preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
            ) {
                self::invalid("reason", "Explain this academic correction in at most 1,000 characters.");
            }
            $operation = EntityId::generate();
            $now = $this->clock->now();
            // This is the effective completion fact; accepted/recorded time remains the journal's clock.
            $effective =
                $completedOn === null ? $now : new DateTimeImmutable($completedOn, $now->getTimezone());
            new EventContext($actor, $effective, $operation, reason: $reason);
            $data = [
                "kind" => "academic-completion-review",
                "student_id" => $student->value,
                "section_id" => $section->value,
                "enrollment_id" => $enrollment->value,
                "credit_id" => $id->value,
                "operation_id" => $operation->value,
                "actor_id" => $actor->value,
                "expected_version" => $root->playhead(),
                "credit_version" => $version,
                "previous_decision_id" => $root->decision(),
                "previous_completed_on" => $root->completedOn(),
                "completed_on" => $completedOn,
                "effective_at" => $effective->format(DATE_ATOM),
                "reason" => $reason,
                "expires_at" => $now->getTimestamp() + 1200,
            ];
            $this->sameActor($actor);
            return $data + ["review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR))];
        });
    }

    /** @return array{operation_id:string,first_version:int,last_version:int,event_ids:list<string>} */
    public function commit(EntityId $student, EntityId $section, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize();
        try {
            if (strlen($token) > 16384) {
                throw new \InvalidArgumentException();
            }
            $d = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($d) ||
                ($d["kind"] ?? null) !== "academic-completion-review" ||
                ($d["student_id"] ?? null) !== $student->value ||
                ($d["section_id"] ?? null) !== $section->value ||
                !is_int($d["expires_at"] ?? null) ||
                !is_int($d["expected_version"] ?? null) ||
                !is_int($d["credit_version"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
        } catch (Throwable) {
            self::invalid("review_token", "Review the actual completion date again.");
        }
        if ($d["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This completion-date review belongs to another staff member.");
        }
        return $this->transactions->run(function () use ($student, $section, $actor, $d): array {
            $this->lock($student, $section, $actor);
            $enrollment = new EntityId($d["enrollment_id"]);
            [$id, $root, $facts] = $this->credits->decisionRecord($student, $section, $enrollment);
            if ($id->value !== $d["credit_id"]) {
                throw new CorruptHistoryException();
            }
            $operation = new EntityId($d["operation_id"]);
            $event = AcademicCompletionWasRecorded::occur(
                new AcademicCreditId($id->value),
                [
                    "decision_id" => $operation->value,
                    "previous_decision_id" => $d["previous_decision_id"],
                    "previous_completed_on" => $d["previous_completed_on"],
                    "completed_on" => $d["completed_on"],
                ],
                ["__aggregate_type" => "academic_credit"],
            )->withPlayhead($d["expected_version"] + 1);
            // Append verifies an original retry before new-command expiry/state checks. Any failed new
            // decision rolls back its event and receipt with the projection in this same transaction.
            $receipt = $this->history->append(
                new StreamReference(new AcademicCreditId($id->value), "academic_credit"),
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
                        "The record or review changed. Review the completion date again.",
                    );
                }
                $this->posted($enrollment, $section, $facts, $root->playhead(), $root->grade() !== null);
                $this->date($d["completed_on"]);
                $root->acceptHistorical($event);
                $this->records->project(
                    $root->credit() ?? throw new CorruptHistoryException(),
                    $section,
                    $facts["version"],
                );
                $this->audit->record($actor, "academic.completion_recorded", $id);
            }
            $this->sameActor($actor);
            return $receipt;
        });
    }

    private function date(#[\SensitiveParameter] ?string $date): void
    {
        if ($date === null) {
            return;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            self::invalid("completed_on", "Enter a real actual completion date no later than today.");
        }
        $parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date);
        if (
            $parsed === false ||
            $parsed->format("Y-m-d") !== $date ||
            $date > $this->clock->now()->format("Y-m-d")
        ) {
            self::invalid("completed_on", "Enter a real actual completion date no later than today.");
        }
    }

    /** @param array<string,mixed> $facts */
    private function posted(EntityId $enrollment, EntityId $section, array $facts, int $head, bool $gradeKnown): void
    {
        $row = $this->records->enrollment($enrollment);
        if ($row !== null && ($row["student_id"] !== $facts["student_id"] || $row["section_id"] !== $section->value)) {
            throw new CorruptHistoryException();
        }
        if (
            $head < 1 ||
            !$gradeKnown ||
            $facts["grade_id"] === null ||
            !in_array($facts["status"], ["A", "N"], true) ||
            $row === null ||
            $row["status"] !== $facts["status"] ||
            $row["term_id"] !== $facts["term_id"]
        ) {
            self::invalid(
                "completed_on",
                "A verified posted grade and active academic record are required. Review the grade history first.",
            );
        }
    }

    private function authorize(): EntityId
    {
        $actor = $this->access->requirePermission("sis:gradebook:amend");
        $this->access->requirePermission("sis:student:read");
        return $actor;
    }

    private function sameActor(EntityId $actor): void
    {
        if ($this->authorize()->value !== $actor->value) {
            throw new AccessDeniedException("The academic decision actor changed.");
        }
    }

    private function lock(EntityId $student, EntityId $section, EntityId $actor): void
    {
        $this->records->lockStudent($student);
        $this->records->lockSection($section);
        $this->sameActor($actor);
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
