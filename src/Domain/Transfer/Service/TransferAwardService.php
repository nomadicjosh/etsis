<?php

declare(strict_types=1);

namespace Domain\Transfer\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Registration\Repository\RegistrationRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferAwardRepository;
use Domain\Transfer\ValueObject\AwardChange;
use DomainException;

final readonly class TransferAwardService
{
    public function __construct(
        private TransferAwardRepository $awards,
        private RegistrationRepository $registration,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private TransferCreditLedger $credits,
        private ReviewProof $reviews,
    ) {
    }

    private function authorize(): EntityId
    {
        $actor = $this->access->requirePermission("sis:transfer:manage");
        if (
            $this->access->requirePermission("sis:student:read")->value !== $actor->value ||
            $this->access->authenticatedUserId()->value !== $actor->value
        ) {
            throw new \Domain\Shared\Exception\AccessDeniedException(
                "The transfer staff account changed. Review again.",
            );
        }
        return $actor;
    }

    /** @return array<string, mixed> */
    public function view(EntityId $student, EntityId $award): array
    {
        $actor = $this->authorize();
        $record = $this->stableRecord($student, $award);
        $this->audit->record($actor, "transfer.award_reviewed", $award);
        $this->authorizedActor($actor);
        return $record;
    }

    /** @return array<string, mixed> */
    public function preview(
        EntityId $student,
        EntityId $award,
        #[\SensitiveParameter] AwardChange $change,
        ?EntityId $operation = null,
    ): array {
        $actor = $this->authorize();
        $budget = new HistoryReadBudget();
        $initial = $this->stableRecord($student, $award, $budget);
        $operation ??= EntityId::generate();
        $grade =
        $change->grade === null
        ? null
        : $this->awards->grade(
            $change->grade,
            $student,
            $initial["retained_grade_id"] === null
                        ? null
                        : new EntityId($initial["retained_grade_id"]),
        );
        return $this->transactions->run(function () use (
            $student,
            $award,
            $change,
            $actor,
            $initial,
            $grade,
            $operation,
            $budget,
        ): array {
            $this->lock($initial, $grade);
            $this->authorizedActor($actor);
            $plan = $this->plan($this->lockedRecord($student, $award, $initial, $budget), $change, $grade);
            $this->audit->record($actor, "transfer.correction_previewed", $award);
            $this->authorizedActor($actor);
            $revision = $this->revision($plan);
            return $plan + [
                "revision" => $revision,
                "operation_id" => $operation->value,
                "review_token" => $this->reviews->issue(
                    "transfer-correction",
                    $change->kind,
                    $award,
                    $actor,
                    $operation,
                    $revision,
                ),
            ];
        });
    }

    /** @return array<string, mixed> */
    public function confirm(
        EntityId $student,
        EntityId $award,
        EntityId $operation,
        #[\SensitiveParameter] AwardChange $change,
        string $revision,
        #[\SensitiveParameter] ?string $reviewToken = null,
    ): array {
        $actor = $this->authorize();
        $budget = new HistoryReadBudget();
        $initial = $this->stableRecord($student, $award, $budget);
        // Resolve lock identities outside the transaction; lock the complete context before any consistent read.
        $seen = $this->awards->action($operation);
        $grade =
        $seen !== null || $change->grade === null
        ? null
        : $this->awards->grade(
            $change->grade,
            $student,
            $initial["retained_grade_id"] === null
                        ? null
                        : new EntityId($initial["retained_grade_id"]),
        );
        return $this->transactions->run(function () use (
            $student,
            $award,
            $operation,
            $change,
            $revision,
            $actor,
            $initial,
            $grade,
            $reviewToken,
            $budget,
        ): array {
            $this->lock($initial, $grade);
            $this->authorizedActor($actor);
            $previous = $this->awards->action($operation);
            if ($previous !== null) {
                if ($previous["award_id"] !== $award->value || $previous["actor_id"] !== $actor->value) {
                    throw new ConcurrentModificationException(
                        "This operation belongs to another transfer correction.",
                    );
                }
                $payload = $this->open($previous);
                if (
                    ($payload["format"] ?? null) === "transfer-receipt.v2"
                        ? $payload["request_hash"] !== $this->requestHash($change) ||
                            $payload["revision"] !== $revision
                        : $payload["request"] + ["effective_on" => null] !== $change->describe() ||
                            $payload["revision"] !== $revision
                ) {
                    throw new ConcurrentModificationException(
                        "This operation belongs to another transfer correction.",
                    );
                }
                $this->verifyReceipt($award, $previous, $payload, $budget);
                $this->authorizedActor($actor);
                return $this->receiptRow($previous);
            }
            $current = $this->lockedRecord($student, $award, $initial, $budget);
            $plan = $this->plan($current, $change, $grade);
            if (!hash_equals($this->revision($plan), $revision)) {
                throw new ConcurrentModificationException(
                    "The reviewed transfer award or grade changed. Preview it again.",
                );
            }
            $this->reviewProof($reviewToken, $award, $actor, $operation, $change, $revision);
            $this->credits->change(
                $current,
                $change,
                $grade,
                $actor,
                $operation,
                new \DateTimeImmutable($plan["effective_at"]),
                $budget,
            );
            $this->awards->record(
                $operation,
                $award,
                new EntityId($current["credit"]["id"]),
                $actor,
                $current["credit"]["version"] + 1,
                $change->kind,
                $this->cipher->seal(
                    json_encode(
                        [
                            "format" => "transfer-receipt.v2",
                            "request_hash" => $this->requestHash($change),
                            "revision" => $revision,
                        ],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format(DATE_ATOM),
            );
            $this->audit->record($actor, "transfer.award_" . $change->kind, $award);
            $this->authorizedActor($actor);
            $this->reviewProof($reviewToken, $award, $actor, $operation, $change, $revision);
            return $this->receiptRow(
                $this->awards->action($operation) ??
                    throw new RecordNotFoundException("Transfer correction receipt not found."),
            );
        });
    }

    /** @return array<string, mixed> */
    public function receipt(EntityId $student, EntityId $award, EntityId $operation): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $award, $operation, $actor): array {
            $budget = new HistoryReadBudget();
            $this->stableRecord($student, $award, $budget);
            $row = $this->awards->action($operation);
            if ($row === null || $row["award_id"] !== $award->value) {
                throw new RecordNotFoundException("Transfer correction not found.");
            }
            $this->verifyReceipt($award, $row, $this->open($row), $budget);
            $this->authorizedActor($actor);
            return $this->receiptRow($row);
        });
    }

    /** @return array<string, mixed> */
    public function history(EntityId $student, EntityId $award, ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $award, $after, $actor): array {
            $budget = new HistoryReadBudget();
            $this->stableRecord($student, $award, $budget);
            $rows = $this->awards->history($award, $after);
            $items = [];
            $selected = array_slice($rows, 0, 50);
            $payloads = [];
            $operations = [];
            foreach ($selected as $row) {
                $payloads[$row["id"]] = $this->open($row);
                if (($payloads[$row["id"]]["format"] ?? null) === "transfer-receipt.v2") {
                    $operations[] = $row["id"];
                }
            }
            $details = $operations === [] ? [] : $this->credits->details($award, $operations, $budget);
            foreach ($selected as $row) {
                $payload = $payloads[$row["id"]];
                if (($payload["format"] ?? null) === "transfer-receipt.v2") {
                    $detail =
                        $details[$row["id"]] ??
                        throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
                    if (
                        $detail["after"]["id"] !== $row["credit_id"] ||
                        $detail["after"]["version"] !== (int) $row["version"] ||
                        $detail["actor_id"] !== $row["actor_id"] ||
                        $detail["action"] !== $row["kind"]
                    ) {
                        throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
                    }
                    $items[] = $this->receiptRow($row) + $detail;
                } else {
                    $items[] = $this->receiptRow($row) + [
                        "reason" => $payload["request"]["reason"],
                        "before" => $payload["before"],
                        "after" => $payload["after"],
                        "history_source" => "legacy_receipt",
                        "effective_at" => null,
                    ];
                }
            }
            $this->audit->record($actor, "transfer.correction_history_read", $award);
            $this->authorizedActor($actor);
            return ["items" => $items, "next_after" => count($rows) > 50 ? $rows[49]["id"] : null];
        });
    }

    /** Serialize verified reads with this student's award writers; do not compare different committed heads.
     * @return array<string,mixed> */
    private function stableRecord(
        EntityId $student,
        EntityId $award,
        ?HistoryReadBudget $budget = null,
    ): array {
        return $this->transactions->run(function () use ($student, $award, $budget): array {
            $this->registration->lockStudent($student);
            $record = $this->awards->get($student, $award);
            $this->awards->lock($award, new EntityId($record["credit"]["id"]));
            $this->authorize();
            return $this->credits->verified($this->awards->get($student, $award), $budget);
        });
    }

    /** @param array<string, mixed> $initial
     * @return array<string, mixed>
     */
    private function lockedRecord(
        EntityId $student,
        EntityId $award,
        array $initial,
        HistoryReadBudget $budget,
    ): array {
        $current = $this->credits->verified($this->awards->get($student, $award), $budget);
        if (
            $current["credit"]["id"] !== $initial["credit"]["id"] ||
            $current["retained_grade_id"] !== $initial["retained_grade_id"]
        ) {
            throw new ConcurrentModificationException(
                "The transfer context changed while waiting for review. Reload it.",
            );
        }
        return $current;
    }

    /** @param array<string, mixed> $record
     * @param array<string, mixed>|null $grade
     */
    private function lock(array $record, ?array $grade): void
    {
        $this->registration->lockStudent(new EntityId($record["student_id"]));
        $this->registration->lockPerson(new EntityId($record["person_id"]));
        $this->awards->lock(new EntityId($record["id"]), new EntityId($record["credit"]["id"]));
        $grades = array_values(
            array_unique(array_filter([$record["credit"]["grade_id"], $grade["id"] ?? null])),
        );
        sort($grades, SORT_STRING);
        foreach ($grades as $id) {
            $this->awards->lockGrade(new EntityId($id));
        }
    }

    /** @param array<string, mixed> $record
     * @param array<string, mixed>|null $initialGrade
     * @return array<string, mixed>
     */
    private function plan(
        array $record,
        #[\SensitiveParameter] AwardChange $change,
        ?array $initialGrade,
    ): array {
        $before = $record["credit"] + [
            "grade_code" => $record["grade_code"],
            "counts_gpa" => $record["counts_gpa"],
        ];
        $voided = $before["status"] === "V";
        if ($change->kind === "reinstate" ? !$voided : !in_array($before["status"], ["A", "N"], true)) {
            throw new DomainException("Select the appropriate action for the current award state.");
        }
        $grade =
        $change->grade === null
        ? null
        : $this->awards->grade(
            $change->grade,
            new EntityId($record["student_id"]),
            $record["retained_grade_id"] === null ? null : new EntityId($record["retained_grade_id"]),
        );
        if ($grade !== $initialGrade) {
            throw new ConcurrentModificationException("The target grade changed. Preview it again.");
        }
        $after = array_replace($before, [
            "attempted_milli" => $change->credits ?? 0,
            "completed_milli" => $change->credits ?? 0,
            "grade_points_milli" =>
                $grade !== null && $grade["counts_gpa"]
                    ? intdiv(($change->credits ?? 0) * $grade["points_milli"], 1000)
                    : 0,
            "grade_id" => $grade["id"] ?? null,
            "grade_code" => $grade["code"] ?? null,
            "counts_gpa" => (bool) ($grade["counts_gpa"] ?? false),
            "status" => $change->kind === "void" ? "V" : "A",
        ]);
        if ($before === $after) {
            throw new ValidationException([
                [
                    "field" => "credits_milli",
                    "message" => "Change the credits or grade before reviewing a correction.",
                ],
            ]);
        }
        $after["version"] = $before["version"] + 1;
        $now = $this->clock->now();
        $effective = $change->effectiveOn ?? $now->format("Y-m-d");
        if ($effective < $record["completed_on"] || $effective > $now->format("Y-m-d")) {
            throw new ValidationException([
                [
                    "field" => "effective_on",
                    "message" => "Choose an effective date from course completion through today.",
                ],
            ]);
        }
        return [
            "effective_at" => new \DateTimeImmutable($effective, $now->getTimezone())->format(DATE_ATOM),
            "record" => $record,
            "before" => $before,
            "after" => $after,
            "request" => $change->describe(),
        ];
    }

    /** @param array<string,mixed> $row
     * @param array<string,mixed> $payload */
    private function verifyReceipt(
        EntityId $award,
        array $row,
        array $payload,
        HistoryReadBudget $budget,
    ): void {
        if (($payload["format"] ?? null) !== "transfer-receipt.v2") {
            return;
        }
        $detail =
        $this->credits->details($award, [$row["id"]], $budget)[$row["id"]] ??
        throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        if (
            $detail["after"]["id"] !== $row["credit_id"] ||
            $detail["after"]["version"] !== (int) $row["version"] ||
            $detail["actor_id"] !== $row["actor_id"] ||
            $detail["action"] !== $row["kind"]
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
    }
    private function authorizedActor(EntityId $actor): void
    {
        if ($this->authorize()->value !== $actor->value) {
            throw new \Domain\Shared\Exception\AccessDeniedException("The transfer decision actor changed.");
        }
    }
    private function reviewProof(
        #[\SensitiveParameter] ?string $token,
        EntityId $award,
        EntityId $actor,
        EntityId $operation,
        AwardChange $change,
        string $revision,
    ): void {
        if ($token === null) {
            throw new ValidationException([
                [
                    "field" => "review_token",
                    "message" => "Review this transfer correction again before confirming.",
                ],
            ]);
        }
        $this->reviews->validate(
            $token,
            "transfer-correction",
            $change->kind,
            $award,
            $actor,
            $operation,
            $revision,
        );
    }
    private function requestHash(#[\SensitiveParameter] AwardChange $change): string
    {
        return hash("sha256", json_encode($change->describe(), JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $plan */
    private function revision(array $plan): string
    {
        return hash("sha256", json_encode($plan, JSON_THROW_ON_ERROR));
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function open(array $row): array
    {
        return json_decode($this->cipher->open($row["encrypted_content"]), true, 32, JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function receiptRow(array $row): array
    {
        return [
            "operation_id" => $row["id"],
            "award_id" => $row["award_id"],
            "credit_id" => $row["credit_id"],
            "actor_id" => $row["actor_id"],
            "version" => (int) $row["version"],
            "action" => $row["kind"],
            "created_at" => $row["created_at"],
        ];
    }
}
