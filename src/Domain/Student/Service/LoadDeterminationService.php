<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Advising\Service\AdvisorProgressAccess;
use Domain\Audit\Repository\AuditTrail;
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
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Event\EnrollmentLoadWasDetermined;
use Domain\Student\Repository\EnrollmentLoadPolicyRepository;
use Domain\Student\Repository\EnrollmentLoadRepository;
use Domain\Student\Repository\LoadDeterminationRepository;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\ValueObject\LoadDeterminationId;
use Domain\Student\ValueObject\LoadDeterminationSnapshot;

final readonly class LoadDeterminationService
{
    public const string PERMISSION = "sis:load:determine";
    public function __construct(
        private EnrollmentLoadService $loads,
        private LoadDeterminationRepository $records,
        private StudentRecordRepository $students,
        private AdmissionsRepository $admissions,
        private EnrollmentLoadRepository $terms,
        private EnrollmentLoadPolicyRepository $policies,
        private HistoricalEventStore $history,
        private StudentAccess $access,
        private AdvisorProgressAccess $advisors,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ReviewProof $proofs,
        private LoadDeterminationLedger $ledger,
    ) {
    }
    public function terms(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        string $search = "",
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        if (
            !mb_check_encoding($search, "UTF-8") ||
            mb_strlen($search) > 80 ||
            preg_match('/[\x00-\x1f\x7f]/', $search)
        ) {
            throw new ValidationException([
                ["field" => "search", "message" => "Use a term search of up to eighty characters."],
            ]);
        }
        $result = $this->terms->terms(trim($search), $after);
        $this->authorize($student, $pursuit, $channel, $actor);
        return $result;
    }
    public function review(
        EntityId $student,
        EntityId $pursuit,
        EntityId $term,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize($student, $pursuit, "staff");
        $this->reason($reason);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $term,
            $reason,
            $actor,
        ): array {
            $snapshot = $this->context($student, $pursuit, $term, new HistoryReadBudget());
            $operation = EntityId::generate();
            $revision = $snapshot->fingerprint();
            $intent = $this->intent($student, $pursuit, $term, $revision, $reason);
            $this->authorize($student, $pursuit, "staff", $actor);
            return [
                "operation_id" => $operation->value,
                "revision" => $revision,
                "reason" => trim($reason),
                "result" => $snapshot->view(),
                "review_token" => $this->proofs->issue(
                    "load-determination",
                    "registrar",
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
        EntityId $term,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token,
    ): array {
        $actor = $this->authorize($student, $pursuit, "staff");
        $this->reason($reason);
        $intent = $this->intent($student, $pursuit, $term, $revision, $reason);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $term,
            $operation,
            $revision,
            $reason,
            $token,
            $actor,
            $intent,
        ): array {
            $this->students->lock($student);
            $budget = new HistoryReadBudget();
            $stored = $this->ledger->restore($operation, $budget);
            $this->proofs->validate(
                $token,
                "load-determination",
                "registrar",
                $student,
                $actor,
                $operation,
                $intent,
                $stored !== null,
            );
            if ($stored !== null) {
                $payload = $stored->event->payload();
                $snapshot = new LoadDeterminationSnapshot($payload["snapshot"]);
                if (
                    $stored->context->actor?->value !== $actor->value ||
                    $payload["intent"] !== $intent ||
                    $snapshot->data["student_id"] !== $student->value ||
                    $snapshot->data["pursuit_id"] !== $pursuit->value ||
                    $snapshot->data["term"]["id"] !== $term->value
                ) {
                    throw new AccessDeniedException();
                }
                $this->authorize($student, $pursuit, "staff", $actor);
                return ["status" => "recorded", "operation_id" => $operation->value];
            }
            $snapshot = $this->context($student, $pursuit, $term, $budget);
            $this->same($revision, $snapshot->fingerprint());
            $this->audit->record($actor, "student.load_determined", $operation);
            $this->authorize($student, $pursuit, "staff", $actor);
            $this->proofs->validate(
                $token,
                "load-determination",
                "registrar",
                $student,
                $actor,
                $operation,
                $intent,
            );
            // Preserve the exact facts reviewed, including after the final required audit.
            $this->same(
                $revision,
                $this->context($student, $pursuit, $term, $budget)->fingerprint(),
            );
            $event = EnrollmentLoadWasDetermined::occur(
                new LoadDeterminationId($operation->value),
                [
                    "operation_id" => $operation->value,
                    "snapshot" => $snapshot->data,
                    "intent" => $intent,
                ],
                ["__aggregate_type" => "enrollment_load_determination"],
            )->withPlayhead(1);
            $at = $this->clock->now();
            if ($at->format("Y-m-d") !== $snapshot->data["as_of_date"]) {
                throw new ConcurrentModificationException(
                    "The determination date changed. Review again.",
                );
            }
            $context = new EventContext($actor, $at, $operation, reason: trim($reason));
            $this->history->append($this->stream($operation), $operation, 0, $context, [$event]);
            $this->records->insert($this->ledger->projection($operation, $snapshot, $context->effectiveAt));
            $this->authorize($student, $pursuit, "staff", $actor);
            $this->proofs->validate(
                $token,
                "load-determination",
                "registrar",
                $student,
                $actor,
                $operation,
                $intent,
            );
            return ["status" => "recorded", "operation_id" => $operation->value];
        });
    }
    public function listing(
        EntityId $student,
        EntityId $pursuit,
        EntityId $term,
        string $channel,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize($student, $pursuit, $channel);
        return $this->transactions->run(function () use (
            $student,
            $pursuit,
            $term,
            $channel,
            $after,
            $actor,
        ): array {
            $rows = $this->records->page($student, $pursuit, $term, $after);
            $budget = new HistoryReadBudget();
            $items = [];
            foreach (array_slice($rows, 0, 10) as $row) {
                $stored =
                    $this->ledger->restore(new EntityId($row["id"]), $budget) ??
                    throw new CorruptHistoryException();
                $snapshot = new LoadDeterminationSnapshot($stored->event->payload()["snapshot"]);
                if (
                    $snapshot->data["student_id"] !== $student->value ||
                    $snapshot->data["pursuit_id"] !== $pursuit->value ||
                    $snapshot->data["term"]["id"] !== $term->value
                ) {
                    throw new CorruptHistoryException();
                }
                $view = $snapshot->view();
                // A retained total does not reopen restricted early-drop coursework through a second reader.
                unset($view["items"]);
                if ($channel !== "staff") {
                    unset(
                        $view["term"]["id"],
                        $view["program"]["id"],
                        $view["policy"]["version_id"],
                    );
                }
                $items[] = $view + ($channel === "staff" ? ["id" => $row["id"]] : []) + [
                    "recorded_at" => $stored->context->effectiveAt->format(DATE_ATOM),
                ];
            }
            $this->audit->record($actor, "student.load_determinations_read", $student);
            $this->authorize($student, $pursuit, $channel, $actor);
            return ["items" => $items, "next_after" => count($rows) > 10 ? $rows[9]["id"] : null];
        });
    }
    private function context(
        EntityId $student,
        EntityId $pursuit,
        EntityId $term,
        HistoryReadBudget $budget,
    ): LoadDeterminationSnapshot {
        $this->students->lock($student);
        $this->terms->lockTerm($term);
        $assignment = $this->students->assignment($pursuit);
        if ($assignment->studentId->value !== $student->value) {
            throw new AccessDeniedException();
        }
        $this->policies->lockScope($assignment->programId);
        $data = $this->loads->determinationContext($student, $pursuit, $term, $budget);
        if (
            !in_array($data["status"], ["ready", "not_registered"], true) ||
            $data["term_timing"] !== "current" ||
            $data["policy"]["definition"] === null ||
            count($data["sources"] ?? []) > 50
        ) {
            throw new ValidationException([
                [
                    "field" => "term_id",
                    "message" =>
                        "Record a verified current-term load with a published policy " .
                        "and at most fifty active registrations.",
                ],
            ]);
        }
        $snapshot = [
            "student_id" => $student->value,
            "pursuit_id" => $pursuit->value,
            "term" => $data["term"],
            "program" => array_intersect_key($data["program"], array_flip(["id", "code", "title"])),
            "as_of_date" => $this->clock->now()->format("Y-m-d"),
            "policy" => $data["policy"],
            "sources" => $data["sources"],
        ];
        if (strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 55000) {
            throw new ValidationException([
                [
                    "field" => "term_id",
                    "message" => "The load evidence exceeds the retained-record size limit.",
                ],
            ]);
        }
        return new LoadDeterminationSnapshot($snapshot);
    }
    private function authorize(
        EntityId $student,
        EntityId $pursuit,
        string $channel,
        ?EntityId $expected = null,
    ): EntityId {
        $actor = $this->access->authenticatedUserId();
        if ($channel === "staff") {
            if (
                $this->access->requirePermission("sis:student:read")->value !== $actor->value ||
                $this->access->requirePermission(self::PERMISSION)->value !== $actor->value
            ) {
                throw new AccessDeniedException();
            }
        } elseif ($channel === "advisor") {
            if ($this->advisors->requireAdvisor($student, $pursuit)->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        } elseif ($channel !== "owner") {
            throw new AccessDeniedException();
        }
        $s = $this->admissions->student($student);
        if ($this->students->assignment($pursuit)->studentId->value !== $student->value) {
            throw new AccessDeniedException();
        }
        if (
            $channel === "owner" &&
            $this->admissions->person($s->personId)->ownerUserId?->value !== $actor->value
        ) {
            throw new AccessDeniedException();
        }
        if ($expected !== null && $expected->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return $actor;
    }
    private function reason(#[\SensitiveParameter] string $reason): void
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
        EntityId $term,
        string $revision,
        string $reason,
    ): string {
        return hash(
            "sha256",
            json_encode(
                [$student->value, $pursuit->value, $term->value, $revision, trim($reason)],
                JSON_THROW_ON_ERROR,
            ),
        );
    }
    private function same(string $expected, string $actual): void
    {
        if (!hash_equals($expected, $actual)) {
            throw new ConcurrentModificationException("Load inputs changed. Review them again.");
        }
    }
    private function stream(EntityId $id): StreamReference
    {
        return new StreamReference(
            new LoadDeterminationId($id->value),
            "enrollment_load_determination",
        );
    }
}
