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

final readonly class AcademicDetailsService
{
    public const string PLACEMENT_PERMISSION = "sis:academic:placement:amend";
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

    public function reviewDetails(
        EntityId $student,
        EntityId $section,
        EntityId $enrollment,
        #[\SensitiveParameter] ?string $academicOn,
        #[\SensitiveParameter] ?string $completedOn,
        EntityId $postingTerm,
        int $version,
        #[\SensitiveParameter] string $reason,
        string $effectiveOn,
    ): array {
        $this->date($effectiveOn, AcademicDateKind::Completion, "effective_on");
        return $this->reviewChanges(
            $student,
            $section,
            $enrollment,
            [
                "academic_on" => $academicOn,
                "completed_on" => $completedOn,
                "posting_term_id" => $postingTerm->value,
            ],
            $version,
            $reason,
            "academic-details-review",
            "details",
            $effectiveOn,
        );
    }

    public function commitDetails(
        EntityId $student,
        EntityId $section,
        #[\SensitiveParameter] string $token,
    ): array {
        return $this->commitChanges($student, $section, $token, "academic-details-review", "details");
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
        ?string $effectiveOn = null,
    ): array {
        $actor = $this->authorize();
        foreach ($requested as $name => $value) {
            if ($name !== "posting_term_id") {
                $this->date($value, AcademicDateKind::from($name));
            }
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
            $effectiveOn,
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
                $current =
                    $name === "posting_term_id"
                        ? $facts["term_id"]
                        : AcademicDateKind::from($name)->current($root);
                if ($current !== $value) {
                    $changes[] = $name;
                }
            }
            if ($changes === []) {
                self::invalid(
                    $field,
                    "Change at least one academic detail, or explicitly clear a known date.",
                );
            }
            // Each intermediate event must also describe a valid timeline. Move completion first when
            // the new start is later than the old completion; otherwise moving the start first is safe.
            if (
                in_array("academic_on", $changes, true) &&
                in_array("completed_on", $changes, true) &&
                $requested["academic_on"] !== null &&
                $root->completedOn() !== null &&
                $requested["academic_on"] > $root->completedOn()
            ) {
                $changes = [
                    "completed_on",
                    "academic_on",
                    ...array_diff($changes, ["academic_on", "completed_on"]),
                ];
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
            $effectiveDate =
                $effectiveOn ??
                (in_array("completed_on", $changes, true)
                    ? $requested["completed_on"]
                    : $requested["academic_on"]);
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
                "following_decision_id" => count($changes) >= 2 ? EntityId::generate()->value : null,
                "effective_at" => $effective->format(DATE_ATOM),
                "reason" => $reason,
                "expires_at" => $now->getTimestamp() + 1200,
            ];
            if (count($changes) === 3) {
                $data["third_decision_id"] = EntityId::generate()->value;
            }
            if (array_key_exists("posting_term_id", $requested)) {
                $target = $this->records->term(new EntityId($requested["posting_term_id"]));
                $data += [
                    "previous_posting_term_id" => $facts["term_id"],
                    "posting_term_id" => $target["id"],
                    "posting_term_code" => $target["code"],
                    "previous_term_code" => $root->postingTerm()["term_code"] ?? null,
                    "previous_posting_term_code_display" =>
                        $root->postingTerm()["term_code"] ??
                        $this->records->term(new EntityId($facts["term_id"]))["code"],
                ];
            }
            $this->sameActor($actor, $facts["status"] === "W", in_array("posting_term_id", $changes, true));
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
                count($changes) > ($tokenKind === "academic-details-review" ? 3 : 2) ||
                count(array_unique($changes)) !== count($changes)
            ) {
                throw new \InvalidArgumentException();
            }
            foreach ($changes as $name) {
                if (
                    !is_string($name) ||
                    (AcademicDateKind::tryFrom($name) === null &&
                        !($name === "posting_term_id" && $tokenKind === "academic-details-review")) ||
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
        $placement = in_array("posting_term_id", $changes, true);
        $this->sameActor($actor, placement: $placement);
        return $this->transactions->run(function () use (
            $student,
            $section,
            $actor,
            $d,
            $changes,
            $field,
            $placement,
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
                $decision = match ($index) {
                    0 => $operation->value,
                    1 => $d["following_decision_id"],
                    2 => $d["third_decision_id"],
                };
                $class =
                    $name === "posting_term_id"
                        ? \Domain\AcademicRecord\Event\InstitutionalPostingTermWasCorrected::class
                        : AcademicDateKind::from($name)->eventClass();
                $payload =
                    $name === "posting_term_id"
                        ? [
                            "decision_id" => $decision,
                            "previous_decision_id" => $previous,
                            "previous_term_id" => $d["previous_posting_term_id"],
                            "previous_term_code" => $d["previous_term_code"],
                            "term_id" => $d["posting_term_id"],
                            "term_code" => $d["posting_term_code"],
                        ]
                        : [
                            "decision_id" => $decision,
                            "previous_decision_id" => $previous,
                            "previous_" . $name => $d["previous_" . $name],
                            $name => $d[$name],
                        ];
                $events[] = $class
                    ::occur(new AcademicCreditId($id->value), $payload, [
                        "__aggregate_type" => "academic_credit",
                    ])
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
                    if ($name === "posting_term_id") {
                        $this->sameActor($actor, $facts["status"] === "W", true);
                        $this->date(
                            substr($d["effective_at"], 0, 10),
                            AcademicDateKind::Completion,
                            "effective_on",
                        );
                        $target = $this->records->term(new EntityId($d[$name]));
                        if ($target["code"] !== $d["posting_term_code"]) {
                            throw new ConcurrentModificationException(
                                "The posting term changed. Review academic details again.",
                            );
                        }
                        $before = $root->postingTerm();
                        $root->acceptHistorical($events[$index]);
                        $this->records->recordPostingTerm($id, $root->postingTerm(), $before);
                        $this->audit->record($actor, "academic.posting_term_corrected", $id);
                        continue;
                    }
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
            $this->sameActor($actor, $facts["status"] === "W", $placement);
            if ($newDecision && $d["expires_at"] <= $this->clock->now()->getTimestamp()) {
                throw new ConcurrentModificationException(
                    "The review expired before completion. Review the academic dates again.",
                );
            }
            return $receipt;
        });
    }

    private function date(
        #[\SensitiveParameter] ?string $date,
        AcademicDateKind $kind,
        ?string $field = null,
    ): void {
        if ($date === null) {
            return;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1) {
            self::invalid($field ?? $kind->value, "Enter a real date no later than today.");
        }
        $parsed = DateTimeImmutable::createFromFormat("!Y-m-d", $date);
        if (
            $parsed === false ||
            $parsed->format("Y-m-d") !== $date ||
            $date > $this->clock->now()->format("Y-m-d")
        ) {
            self::invalid($field ?? $kind->value, "Enter a real date no later than today.");
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
        $this->authorize($facts["status"] === "W");
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
            !$root->canCorrectSource() ||
            $row === null ||
            $row["status"] !== $facts["status"] ||
            $row["term_id"] !== ($root->registrationTermId() ?? $facts["term_id"]) ||
            (isset($requested["posting_term_id"]) &&
                $requested["posting_term_id"] !== $facts["term_id"] &&
                $root->pendingGradeChange() !== null)
        ) {
            self::invalid(
                $field,
                "Use a verified active or withdrawn academic record. " .
                    "Resolve missing grades, inconsistent quantities or pending grade corrections first.",
            );
        }
        if (
            $facts["status"] === "W" &&
            array_key_exists("completed_on", $requested) &&
            $requested["completed_on"] !== $root->completedOn()
        ) {
            self::invalid(
                "completed_on",
                "A withdrawn attempt cannot be marked completed. Review only its source academic period.",
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

    private function authorize(bool $withdrawn = false, bool $placement = false): EntityId
    {
        $actor = $this->access->requirePermission("sis:gradebook:amend");
        if (
            $this->access->requirePermission("sis:student:read")->value !== $actor->value ||
            ($withdrawn &&
                $this->access->requirePermission("sis:gradebook:closed")->value !== $actor->value) ||
            ($placement &&
                $this->access->requirePermission(self::PLACEMENT_PERMISSION)->value !== $actor->value)
        ) {
            throw new AccessDeniedException("The academic decision actor changed.");
        }
        return $actor;
    }

    private function sameActor(EntityId $actor, bool $withdrawn = false, bool $placement = false): void
    {
        if ($this->authorize($withdrawn, $placement)->value !== $actor->value) {
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
