<?php

declare(strict_types=1);

namespace Domain\AcademicRecord\Service;

use DateTimeImmutable;
use Domain\AcademicRecord\AcademicCredit;
use Domain\AcademicRecord\ValueObject\AcademicDateKind;
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

final readonly class AcademicDateService
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
        #[\SensitiveParameter] ?string $dateValue,
        int $version,
        #[\SensitiveParameter] string $reason,
        AcademicDateKind $kind = AcademicDateKind::Completion,
    ): array {
        return $this->reviewChanges(
            $student,
            $section,
            $enrollment,
            [$kind->value => $dateValue],
            $version,
            $reason,
            $kind->tokenKind(),
            $kind->value,
        );
    }

    /** Both nullable dates are explicit inputs: null deliberately records an unknown fact.
     * @return array<string,mixed>
     */
    public function reviewChronology(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        #[\SensitiveParameter] ?string $academicOn,
        #[\SensitiveParameter] ?string $completedOn,
        int $version,
        #[\SensitiveParameter] string $reason,
    ): array {
        return $this->reviewChanges(
            $student,
            $section,
            $enrollment,
            ["academic_on" => $academicOn, "completed_on" => $completedOn],
            $version,
            $reason,
            "academic-chronology-review",
            "dates",
        );
    }

    /** @param array<string,?string> $requested
     * @return array<string,mixed>
     */
    private function reviewChanges(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        #[\SensitiveParameter] array $requested,
        int $version,
        #[\SensitiveParameter] string $reason,
        string $tokenKind,
        string $field,
    ): array {
        $actor = $this->authorize();
        foreach ($requested as $name => $value) {
            $this->date($value, AcademicDateKind::from($name));
        }
        return $this->transactions->run(function () use (
            $student,
            $section,
            $enrollment,
            $requested,
            $version,
            $reason,
            $actor,
            $tokenKind,
            $field,
        ): array {
            $this->lock($student, $section, $actor);
            [$id, $root, $facts] = $this->credits->decisionRecord($student, $section, $enrollment);
            if ($facts["version"] !== $version) {
                throw new ConcurrentModificationException(
                    "Reload the academic record before reviewing its dates.",
                );
            }
            $this->posted($enrollment, $section, $facts, $root, $requested, $field);
            $changes = [];
            foreach ($requested as $name => $value) {
                if (AcademicDateKind::from($name)->current($root) !== $value) {
                    $changes[] = $name;
                }
            }
            if ($changes === []) {
                self::invalid($field, "Change at least one date, or explicitly clear a known date.");
            }
            // Each intermediate event must also describe a valid timeline. Move completion first when
            // the new start is later than the old completion; otherwise moving the start first is safe.
            if (
                count($changes) === 2 &&
                $requested["academic_on"] !== null &&
                $root->completedOn() !== null &&
                $requested["academic_on"] > $root->completedOn()
            ) {
                $changes = ["completed_on", "academic_on"];
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
            $effectiveDate = in_array("completed_on", $changes, true)
                ? $requested["completed_on"]
                : $requested["academic_on"];
            $effective =
                $effectiveDate === null ? $now : new DateTimeImmutable($effectiveDate, $now->getTimezone());
            new EventContext($actor, $effective, $operation, reason: $reason);
            $data = [
                "kind" => $tokenKind,
                "student_id" => $student->value,
                "section_id" => $section->value,
                "enrollment_id" => $enrollment->value,
                "credit_id" => $id->value,
                "operation_id" => $operation->value,
                "actor_id" => $actor->value,
                "expected_version" => $root->playhead(),
                "credit_version" => $version,
                "previous_decision_id" => $root->decision(),
                "previous_academic_on" => $root->academicOn(),
                "previous_completed_on" => $root->completedOn(),
                "academic_on" => array_key_exists("academic_on", $requested)
                    ? $requested["academic_on"]
                    : $root->academicOn(),
                "completed_on" => array_key_exists("completed_on", $requested)
                    ? $requested["completed_on"]
                    : $root->completedOn(),
                "changes" => $changes,
                "following_decision_id" => count($changes) === 2 ? EntityId::generate()->value : null,
                "effective_at" => $effective->format(DATE_ATOM),
                "reason" => $reason,
                "expires_at" => $now->getTimestamp() + 1200,
            ];
            $this->sameActor($actor);
            return $data + ["review_token" => $this->cipher->seal(json_encode($data, JSON_THROW_ON_ERROR))];
        });
    }

    /** @return array{operation_id:string,first_version:int,last_version:int,event_ids:list<string>} */
    public function commit(
        EntityId $student,
        EntityId $section,
        #[\SensitiveParameter] string $token,
        AcademicDateKind $kind = AcademicDateKind::Completion,
    ): array {
        return $this->commitChanges($student, $section, $token, $kind->tokenKind(), $kind->value);
    }

    /** @return array{operation_id:string,first_version:int,last_version:int,event_ids:list<string>} */
    public function commitChronology(
        EntityId $student,
        EntityId $section,
        #[\SensitiveParameter] string $token,
    ): array {
        return $this->commitChanges($student, $section, $token, "academic-chronology-review", "dates");
    }

    /** @return array{operation_id:string,first_version:int,last_version:int,event_ids:list<string>} */
    private function commitChanges(
        EntityId $student,
        EntityId $section,
        #[\SensitiveParameter] string $token,
        string $tokenKind,
        string $field,
    ): array {
        $actor = $this->authorize();
        try {
            if (strlen($token) > 16384) {
                throw new \InvalidArgumentException();
            }
            $d = json_decode($this->cipher->open($token), true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($d) ||
                ($d["kind"] ?? null) !== $tokenKind ||
                ($d["student_id"] ?? null) !== $student->value ||
                ($d["section_id"] ?? null) !== $section->value ||
                !is_int($d["expires_at"] ?? null) ||
                !is_int($d["expected_version"] ?? null) ||
                !is_int($d["credit_version"] ?? null)
            ) {
                throw new \InvalidArgumentException();
            }
            // Existing single-date confirmations retain their original payload and retry fingerprint.
            $changes = $d["changes"] ?? [$field];
            if (
                !is_array($changes) ||
                !array_is_list($changes) ||
                count($changes) < 1 ||
                count($changes) > 2 ||
                count(array_unique($changes)) !== count($changes)
            ) {
                throw new \InvalidArgumentException();
            }
            foreach ($changes as $name) {
                if (
                    !is_string($name) ||
                    AcademicDateKind::tryFrom($name) === null ||
                    !array_key_exists($name, $d) ||
                    !array_key_exists("previous_" . $name, $d)
                ) {
                    throw new \InvalidArgumentException();
                }
            }
        } catch (Throwable) {
            self::invalid("review_token", "Review the academic dates again.");
        }
        if ($d["actor_id"] !== $actor->value) {
            throw new AccessDeniedException("This academic-date review belongs to another staff member.");
        }
        return $this->transactions->run(function () use (
            $student,
            $section,
            $actor,
            $d,
            $changes,
            $field,
        ): array {
            $this->lock($student, $section, $actor);
            $enrollment = new EntityId($d["enrollment_id"]);
            [$id, $root, $facts] = $this->credits->decisionRecord($student, $section, $enrollment);
            if ($id->value !== $d["credit_id"]) {
                throw new CorruptHistoryException();
            }
            $operation = new EntityId($d["operation_id"]);
            $events = [];
            $previous = $d["previous_decision_id"];
            foreach ($changes as $index => $name) {
                $decision = $index === 0 ? $operation->value : $d["following_decision_id"];
                $events[] = AcademicDateKind::from($name)
                    ->eventClass()
                    ::occur(
                        new AcademicCreditId($id->value),
                        [
                            "decision_id" => $decision,
                            "previous_decision_id" => $previous,
                            "previous_" . $name => $d["previous_" . $name],
                            $name => $d[$name],
                        ],
                        ["__aggregate_type" => "academic_credit"],
                    )
                    ->withPlayhead($d["expected_version"] + $index + 1);
                $previous = $decision;
            }
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
                $events,
            );
            $ids = array_map(static fn($event): string => (string) $event->eventId(), $events);
            $newDecision = $receipt["event_ids"] === $ids;
            if ($newDecision) {
                if (
                    $d["expires_at"] <= $this->clock->now()->getTimestamp() ||
                    $facts["version"] !== $d["credit_version"]
                ) {
                    throw new ConcurrentModificationException(
                        "The record or review changed. Review the academic dates again.",
                    );
                }
                $requested = array_intersect_key($d, array_flip($changes));
                $this->posted($enrollment, $section, $facts, $root, $requested, $field);
                foreach ($changes as $index => $name) {
                    $kind = AcademicDateKind::from($name);
                    $this->date($d[$name], $kind);
                    $root->acceptHistorical($events[$index]);
                    $this->audit->record($actor, $kind->action(), $id);
                }
                $this->records->project(
                    $root->credit() ?? throw new CorruptHistoryException(),
                    $section,
                    $facts["version"],
                );
            }
            $this->sameActor($actor);
            if ($newDecision && $d["expires_at"] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException(
                    "The review expired before completion. Review the academic dates again.",
                );
            }
            return $receipt;
        });
    }

    private function date(#[\SensitiveParameter] ?string $date, AcademicDateKind $kind): void
    {
        if ($date === null) {
            return;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            self::invalid($kind->value, "Enter a real " . $kind->label() . " no later than today.");
        }
        $parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date);
        if (
            $parsed === false ||
            $parsed->format("Y-m-d") !== $date ||
            $date > $this->clock->now()->format("Y-m-d")
        ) {
            self::invalid($kind->value, "Enter a real " . $kind->label() . " no later than today.");
        }
    }

    /** @param array<string,mixed> $facts
     * @param array<string,?string> $requested
     */
    private function posted(
        EntityId $enrollment,
        EntityId $section,
        array $facts,
        AcademicCredit $root,
        array $requested,
        string $field,
    ): void {
        $row = $this->records->enrollment($enrollment);
        if (
            $row !== null &&
            ($row["student_id"] !== $facts["student_id"] || $row["section_id"] !== $section->value)
        ) {
            throw new CorruptHistoryException();
        }
        if (
            $root->playhead() < 1 ||
            $root->grade() === null ||
            $facts["grade_id"] === null ||
            !in_array($facts["status"], ["A", "N"], true) ||
            $row === null ||
            $row["status"] !== $facts["status"] ||
            $row["term_id"] !== $facts["term_id"]
        ) {
            self::invalid(
                $field,
                "A verified posted grade and active academic record are required. Review the grade history first.",
            );
        }
        if (
            $root->sectionVersion() !== null &&
            array_key_exists("academic_on", $requested) &&
            $requested["academic_on"] !== $root->academicOn()
        ) {
            self::invalid(
                "academic_on",
                "The published offering already retains the academic period. Its dates cannot be replaced here.",
            );
        }
        $start = array_key_exists("academic_on", $requested)
        ? $requested["academic_on"]
        : $root->academicOn();
        $end = array_key_exists("completed_on", $requested)
        ? $requested["completed_on"]
        : $root->completedOn();
        if ($start !== null && $end !== null && $start > $end) {
            self::invalid(
                $field,
                "The source academic-period start cannot be after the actual completion date. Review both facts.",
            );
        }
    }

    private function authorize(): EntityId
    {
        $actor = $this->access->requirePermission("sis:gradebook:amend");
        if ($this->access->requirePermission("sis:student:read")->value !== $actor->value) {
            throw new AccessDeniedException("The academic decision actor changed.");
        }
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
