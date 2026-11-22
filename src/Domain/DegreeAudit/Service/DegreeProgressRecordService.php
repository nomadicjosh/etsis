<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\DegreeAudit\RecordedDegreeProgress;
use Domain\DegreeAudit\Event\DegreeProgressWasRecorded;
use Domain\DegreeAudit\Repository\DegreeProgressRecordRepository;
use Domain\DegreeAudit\ValueObject\DegreeProgressRecordId;
use Domain\DegreeAudit\ValueObject\DegreeProgressSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\Service\StudentSpecializationService;

final readonly class DegreeProgressRecordService
{
    public const string PERMISSION = "sis:degree-audit:record";

    public function __construct(
        private DegreeProgressService $progress,
        private DegreeProgressRecordRepository $records,
        private StudentRecordRepository $students,
        private StudentSpecializationService $curriculum,
        private AdmissionsRepository $admissions,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
    ) {
    }

    public function review(
        EntityId $student,
        EntityId $pursuit,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize($student, $pursuit, "record");
        $this->reason($reason);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $reason,
            $actor,
        ): array {
            $snapshot = $this->context($student, $pursuit, new HistoryReadBudget());
            $operation = EntityId::generate();
            $revision = $snapshot->fingerprint();
            $intent = $this->intent($student, $pursuit, $revision, $reason);
            $this->authorize($student, $pursuit, "record", $actor);
            return [
                "operation_id" => $operation->value,
                "revision" => $revision,
                "reason" => trim($reason),
                "summary" => $snapshot->summary(),
                "review_token" => $this->proofs->issue(
                    "degree-progress-record",
                    "record",
                    $student,
                    $actor,
                    $operation,
                    $intent,
                ),
            ];
        });
    }

    public function confirm(
        EntityId $student,
        EntityId $pursuit,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize($student, $pursuit, "record");
        $this->reason($reason);
        $intent = $this->intent($student, $pursuit, $revision, $reason);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $operation,
            $revision,
            $reason,
            $token,
            $actor,
            $intent,
        ): array {
            $this->students->lock($student);
            $budget = new HistoryReadBudget();
            $stored = $this->restore($operation, $budget);
            $this->proofs->validate(
                $token,
                "degree-progress-record",
                "record",
                $student,
                $actor,
                $operation,
                $intent,
                $stored !== null,
            );
            if ($stored !== null) {
                $p = $stored->event->payload();
                if (
                    $p["snapshot"]["student_id"] !== $student->value ||
                    $p["snapshot"]["pursuit_id"] !== $pursuit->value ||
                    $p["intent"] !== $intent ||
                    $stored->context->actor?->value !== $actor->value
                ) {
                    throw new AccessDeniedException();
                }
            } else {
                $snapshot = $this->context($student, $pursuit, $budget);
                $this->same($revision, $snapshot->fingerprint());
                $this->audit->record($actor, "degree.progress_recorded", $operation);
                $this->authorize($student, $pursuit, "record", $actor);
                $this->proofs->validate(
                    $token,
                    "degree-progress-record",
                    "record",
                    $student,
                    $actor,
                    $operation,
                    $intent,
                );
                $this->same($revision, $this->context($student, $pursuit, $budget)->fingerprint());
                $event = DegreeProgressWasRecorded::occur(
                    new DegreeProgressRecordId($operation->value),
                    [
                        "operation_id" => $operation->value,
                        "snapshot" => $snapshot->data,
                        "intent" => $intent,
                    ],
                    ["__aggregate_type" => "recorded_degree_progress"],
                )->withPlayhead(1);
                $at = $this->clock->now();
                if ($at->format("Y-m-d") !== $snapshot->data["as_of_date"]) {
                    throw new ConcurrentModificationException(
                        "The review date changed. Review progress again.",
                    );
                }
                $context = new EventContext($actor, $at, $operation, reason: trim($reason));
                $this->history->append($this->stream($operation), $operation, 0, $context, [
                    $event,
                ]);
                $this->records->insert($this->projection($operation, $snapshot, $at));
            }
            $this->authorize($student, $pursuit, "record", $actor);
            $this->proofs->validate(
                $token,
                "degree-progress-record",
                "record",
                $student,
                $actor,
                $operation,
                $intent,
                $stored !== null,
            );
            return ["status" => "recorded", "operation_id" => $operation->value];
        });
    }

    public function listing(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $channel,
            $after,
            $actor,
        ): array {
            $rows = $this->records->page($student, $pursuit, $after);
            $budget = new HistoryReadBudget();
            $items = [];
            foreach (array_slice($rows, 0, 5) as $row) {
                $stored = $this->selected($student, $pursuit, new EntityId($row["id"]), $budget);
                $d = $stored->event->payload()["snapshot"];
                $items[] = [
                    "id" => $row["id"],
                    "as_of_date" => $d["as_of_date"],
                    "recorded_at" => $stored->context->effectiveAt->format(DATE_ATOM),
                    "outcome" => $d["result"]["outcome"],
                    "component_count" => count($d["components"]),
                ];
            }
            $this->authorize($student, $pursuit, $channel, $actor);
            $canRecord = false;
            if ($channel === "staff") {
                try {
                    $this->access->requirePermission(self::PERMISSION);
                    $canRecord = true;
                } catch (AccessDeniedException) {
                }
            }
            return [
                "items" => $items,
                "next_after" => count($rows) > 5 ? $rows[4]["id"] : null,
                "can_record" => $canRecord,
            ];
        });
    }

    public function show(EntityId $student, EntityId $pursuit, EntityId $id, string $channel): array
    {
        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $id,
            $channel,
            $actor,
        ): array {
            $stored = $this->selected($student, $pursuit, $id, new HistoryReadBudget());
            $result = new DegreeProgressSnapshot($stored->event->payload()["snapshot"])->summary();
            $this->audit->record($actor, "degree.progress_record_read", $id);
            $this->authorize($student, $pursuit, $channel, $actor);
            return [
                "id" => $id->value,
                "recorded_at" => $stored->context->effectiveAt->format(DATE_ATOM),
                "summary" => $result,
            ];
        });
    }

    private function context(
        EntityId $student,
        EntityId $pursuit,
        HistoryReadBudget $budget,
    ): DegreeProgressSnapshot {
        $this->students->lock($student);
        $basis = $this->progress->capture($student, $pursuit, $budget);
        if (strlen(json_encode($basis, JSON_THROW_ON_ERROR)) > DegreeProgressSnapshot::MAX_BYTES) {
            throw new ValidationException([
                [
                    "field" => "progress",
                    "message" =>
                        "This review exceeds the retained-record size limit. Current progress remains available.",
                ],
            ]);
        }
        return new DegreeProgressSnapshot($basis);
    }

    private function selected(
        EntityId $student,
        EntityId $pursuit,
        EntityId $id,
        HistoryReadBudget $budget,
    ): StoredEvent {
        $stored =
        $this->restore($id, $budget) ??
        throw new \Domain\Shared\Exception\RecordNotFoundException(
            "Recorded progress not found.",
        );
        $d = $stored->event->payload()["snapshot"];
        if ($d["student_id"] !== $student->value || $d["pursuit_id"] !== $pursuit->value) {
            throw new AccessDeniedException();
        }
        return $stored;
    }

    private function restore(EntityId $id, HistoryReadBudget $budget): ?StoredEvent
    {
        $row = $this->records->find($id);
        $page = $budget->page($this->history, $this->stream($id));
        if ($row === null && $page->throughVersion === 0 && $page->events === []) {
            return null;
        }
        if (
            $row === null ||
            $page->throughVersion !== 1 ||
            count($page->events) !== 1 ||
            $page->nextCursor !== null
        ) {
            throw new CorruptHistoryException();
        }
        $stored = $page->events[0];
        $root = RecordedDegreeProgress::root(new DegreeProgressRecordId($id->value));
        $root->accept($stored);
        $expected = $this->projection($id, $root->snapshot(), $stored->context->effectiveAt);
        foreach ($expected as $key => $value) {
            if ($key === "recorded_at") {
                if (new \DateTimeImmutable($row[$key])->format("Y-m-d H:i:s") !== $value) {
                    throw new CorruptHistoryException();
                }
            } elseif ($row[$key] !== $value) {
                throw new CorruptHistoryException();
            }
        }
        return $stored;
    }

    private function projection(
        EntityId $id,
        DegreeProgressSnapshot $snapshot,
        \DateTimeImmutable $at,
    ): array {
        return [
            "id" => $id->value,
            "student_id" => $snapshot->data["student_id"],
            "pursuit_id" => $snapshot->data["pursuit_id"],
            "recorded_on" => $snapshot->data["as_of_date"],
            "recorded_at" => $at->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d H:i:s"),
            "source_hash" => $snapshot->fingerprint(),
        ];
    }

    private function authorize(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        ?EntityId $expected = null,
    ): EntityId {
        $actor = $this->access->authenticatedUserId();
        if ($channel === "owner") {
            if (
                $this->students->assignment($pursuit)->studentId->value !== $student->value ||
                $this->admissions->person($this->admissions->student($student)->personId)
                    ->ownerUserId?->value !== $actor->value
            ) {
                throw new AccessDeniedException();
            }
        } elseif (in_array($channel, ["staff", "record"], true)) {
            $this->curriculum->authorizeRead($student, $pursuit, $actor);
            if ($channel === "record") {
                $this->access->requirePermission(self::PERMISSION);
            }
        } else {
            throw new AccessDeniedException();
        }
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function reason(string $reason): void
    {
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            trim($reason) === "" ||
            mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $reason)
        ) {
            throw new ValidationException([
                ["field" => "reason", "message" => "Enter a reason of 1–1,000 characters."],
            ]);
        }
    }

    private function intent(
        EntityId $student,
        EntityId $pursuit,
        string $revision,
        string $reason,
    ): string {
        return hash(
            "sha256",
            json_encode(
                [$student->value, $pursuit->value, $revision, trim($reason)],
                JSON_THROW_ON_ERROR,
            ),
        );
    }

    private function same(string $expected, string $actual): void
    {
        if (!hash_equals($expected, $actual)) {
            throw new ConcurrentModificationException("Progress inputs changed. Review again.");
        }
    }

    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(
            new DegreeProgressRecordId($id->value),
            "recorded_degree_progress",
        );
    }
}
