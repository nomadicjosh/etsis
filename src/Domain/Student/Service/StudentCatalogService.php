<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Catalog\Service\PublishedProgramReader;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\StudentProgram;

/** Registrar cohort maintenance. Requirements and specializations retain their separate selections. */
final readonly class StudentCatalogService
{
    public function __construct(
        private CatalogYearRepository $catalogs,
        private StudentRecordRepository $records,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private StudentProgramStatusLedger $programs,
        private PublishedProgramReader $publications,
        private ReviewProof $reviews,
    ) {
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        if ($expected !== null && $actor->value !== $expected->value) {
            throw new AccessDeniedException("The cohort reviewer changed.");
        }
        return $actor;
    }

    /** @return array<string,mixed> */
    public function view(EntityId $student, EntityId $assignment): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $assignment, $actor): array {
            $p = $this->lock($student, $assignment, $actor);
            $context = $this->programs->cohortContext($p);
            $catalog = $context["catalog"];
            if ($catalog === null) {
                $program = $this->records->program($p->programId);
            } else {
                $program = [
                    "id" => $p->programId->value,
                    "code" => $catalog["program_code"],
                    "title" => $catalog["program_title"],
                ];
            }
            $this->authorize($actor);
            return [
                "assignment_id" => $assignment->value,
                "program" => $program,
                "entry_term" => $this->records->term($p->entryTermId),
                "status" => $p->status,
                "version" => $context["binding"]["version"] ?? -1,
                "current" => $context["catalog"],
                "history_recorded" => $context["history_recorded"],
                "earlier_history_unknown" => $context["earlier_history_unknown"],
            ];
        });
    }

    /** @return array<string,mixed> */
    public function choices(EntityId $student, EntityId $assignment, ?EntityId $after = null): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($student, $assignment, $after, $actor): array {
            $p = $this->lock($student, $assignment, $actor);
            $this->active($p);
            $this->programs->verify($p);
            $rows = $this->catalogs->releases($p->programId, $after, true);
            $more = count($rows) > 50;
            $rows = array_slice($rows, 0, 50);
            $items = array_map(StudentProgramStatusLedger::catalogSnapshot(...), $rows);
            $this->authorize($actor);
            return ["items" => $items, "next_after" => $more ? $rows[49]["id"] : null];
        });
    }

    /** @return array<string,mixed> */
    public function review(
        EntityId $student,
        EntityId $assignment,
        EntityId $catalog,
        int $version,
        #[\SensitiveParameter] string $reason,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $catalog,
            $version,
            $reason,
            $actor,
        ): array {
            $p = $this->lock($student, $assignment, $actor);
            $context = $this->context($p, $catalog, $version, $reason);
            $operation = EntityId::generate();
            $revision = $this->revision($context);
            $this->audit->record($actor, "student.catalog_change_reviewed", $assignment);
            $this->authorize($actor);
            return [
                "operation_id" => $operation->value,
                "version" => $version,
                "before" => $context["before"],
                "after" => $context["after"],
                "earlier_history_unknown" => $context["earlier_history_unknown"],
                "review_token" => $this->reviews->issue(
                    "student.cohort",
                    "change",
                    $assignment,
                    $actor,
                    $operation,
                    $revision,
                ),
            ];
        });
    }

    /** @return array<string,mixed> */
    public function commit(
        EntityId $student,
        EntityId $assignment,
        EntityId $catalog,
        EntityId $operation,
        int $version,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $reviewToken,
    ): array {
        $actor = $this->authorize();
        $reason = $this->reason($reason);
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $catalog,
            $operation,
            $version,
            $reason,
            $reviewToken,
            $actor,
        ): array {
            $p = $this->lock($student, $assignment, $actor);
            $previous = $this->catalogs->action($operation);
            if ($previous !== null) {
                $receipt =
                    $previous["encrypted_note"] === null
                        ? null
                        : json_decode(
                            $this->cipher->open($previous["encrypted_note"]),
                            true,
                            16,
                            JSON_THROW_ON_ERROR,
                        );
                if (
                    $previous["assignment_id"] !== $assignment->value ||
                    $previous["actor_id"] !== $actor->value ||
                    $previous["catalog_id"] !== $catalog->value ||
                    !is_array($receipt) ||
                    ($receipt["version"] ?? null) !== $version ||
                    ($receipt["note"] ?? null) !== $reason ||
                    !is_string($receipt["review_revision"] ?? null)
                ) {
                    throw new ConcurrentModificationException(
                        "The cohort operation belongs to another decision.",
                    );
                }
                $this->reviews->validate(
                    $reviewToken,
                    "student.cohort",
                    "change",
                    $assignment,
                    $actor,
                    $operation,
                    $receipt["review_revision"],
                    accepted: true,
                );
                $result =
                    $this->programs->receipt($p, $operation, $actor) ?? throw new CorruptHistoryException();
                if (
                    ($result["catalog"]["catalog_id"] ?? null) !== $catalog->value ||
                    ($result["binding_version"] ?? null) !== $version + 1
                ) {
                    throw new CorruptHistoryException();
                }
                $this->authorize($actor);
                return $result;
            }
            $context = $this->context($p, $catalog, $version, $reason);
            $revision = $this->revision($context);
            $this->reviews->validate(
                $reviewToken,
                "student.cohort",
                "change",
                $assignment,
                $actor,
                $operation,
                $revision,
            );
            $result = $this->programs->changeCohort(
                $p,
                $context["after"],
                $operation,
                $actor,
                $reason,
                $context["stream_version"],
                $context["binding_version"],
            );
            $this->catalogs->record(
                $operation,
                $assignment,
                $actor,
                $context["before"] === null ? null : new EntityId($context["before"]["catalog_id"]),
                $catalog,
                $this->cipher->seal(
                    json_encode(
                        ["version" => $version, "note" => $reason, "review_revision" => $revision],
                        JSON_THROW_ON_ERROR,
                    ),
                ),
                $result["effective_at"],
            );
            $this->audit->record($actor, "student.catalog_changed", $assignment);
            $this->programs->verify($p);
            $this->authorize($actor);
            $this->reviews->validate(
                $reviewToken,
                "student.cohort",
                "change",
                $assignment,
                $actor,
                $operation,
                $revision,
            );
            return $result;
        });
    }

    /** @return array<string,mixed> */
    public function history(
        EntityId $student,
        EntityId $assignment,
        ?string $cursor = null,
        ?EntityId $after = null,
    ): array {
        $actor = $this->authorize();
        return $this->transactions->run(function () use (
            $student,
            $assignment,
            $cursor,
            $after,
            $actor,
        ): array {
            $p = $this->lock($student, $assignment, $actor);
            $page = $this->programs->timeline($p, $cursor);
            $items = array_values(
                array_filter($page["items"], static fn(array $item): bool => $item["kind"] === "cohort"),
            );
            // Preserve earlier encrypted receipts in place. Never promote them to reconstructed events.
            $rows = $this->catalogs->history($assignment, $after);
            $more = count($rows) > 50;
            $rows = array_slice($rows, 0, 50);
            $receipts = [];
            foreach ($rows as $row) {
                $note =
                    $row["encrypted_note"] === null
                        ? null
                        : json_decode(
                            $this->cipher->open($row["encrypted_note"]),
                            true,
                            16,
                            JSON_THROW_ON_ERROR,
                        );
                if (is_array($note) && isset($note["review_revision"])) {
                    continue;
                }
                $receipts[] = [
                    "year_code" => $row["year_code"],
                    "catalog_revision" => $row["catalog_revision"],
                    "recorded_at" => $row["created_at"],
                ];
            }
            $this->audit->record($actor, "student.catalog_history_read", $assignment);
            $this->authorize($actor);
            return [
                "items" => $items,
                "next_cursor" => $page["next_cursor"],
                "earlier_receipts" => $receipts,
                "next_after" => $more ? $rows[49]["id"] : null,
            ];
        });
    }

    private function lock(EntityId $student, EntityId $assignment, EntityId $actor): StudentProgram
    {
        $this->records->lock($student);
        $this->authorize($actor);
        $p = $this->records->assignment($assignment);
        if ($p->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Student program not found.");
        }
        $this->catalogs->lockProgram($p->programId);
        $this->authorize($actor);
        return $p;
    }
    private function active(StudentProgram $p): void
    {
        if ($p->status !== "active") {
            throw new ValidationException([
                [
                    "field" => "catalog_id",
                    "message" => "This pursuit is closed. Its cohort history remains available.",
                ],
            ]);
        }
    }
    private function reason(#[\SensitiveParameter] string $reason): string
    {
        $reason = trim($reason);
        if (
            !mb_check_encoding($reason, "UTF-8") ||
            $reason === "" ||
            mb_strlen($reason) > 1000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $reason)
        ) {
            throw new ValidationException([
                ["field" => "note", "message" => "Enter a registrar reason of up to 1,000 characters."],
            ]);
        }
        return $reason;
    }
    /** @return array<string,mixed> */
    private function context(StudentProgram $p, EntityId $catalog, int $version, string $reason): array
    {
        $this->active($p);
        $budget = new HistoryReadBudget();
        $before = $this->programs->cohortContext($p, $budget);
        if (($before["binding"]["version"] ?? -1) !== $version) {
            throw new ConcurrentModificationException("The assigned cohort changed. Review it again.");
        }
        $release = $this->catalogs->release($catalog);
        if (
            $release["program_id"] !== $p->programId->value ||
            !$release["published"] ||
            ($before["catalog"]["catalog_id"] ?? null) === $catalog->value
        ) {
            throw new ValidationException([
                [
                    "field" => "catalog_id",
                    "message" => "Choose a different published curriculum for this program.",
                ],
            ]);
        }
        $publication = $this->publications->version(
            $p->programId,
            new EntityId($release["year_id"]),
            $catalog,
            $budget,
        );
        $after = StudentProgramStatusLedger::catalogSnapshot($release);
        if (
            $publication !== null &&
            ($publication["catalog_revision"] !== $after["revision"] ||
                $publication["academic_year_code"] !== $after["year_code"] ||
                $publication["definition"]["code"] !== $after["program_code"] ||
                $publication["definition"]["title"] !== $after["program_title"])
        ) {
            throw new CorruptHistoryException();
        }
        return [
            "student_id" => $p->studentId->value,
            "assignment_id" => $p->id->value,
            "status" => $p->status,
            "entry_term_id" => $p->entryTermId->value,
            "stream_version" => $before["stream_version"],
            "binding_version" => $version,
            "before" => $before["catalog"],
            "after" => $after,
            "publication_version" => $publication["stream_version"] ?? null,
            "earlier_history_unknown" => $before["earlier_history_unknown"],
            "reason" => $reason,
        ];
    }
    /** @param array<string,mixed> $context */
    private function revision(#[\SensitiveParameter] array $context): string
    {
        return hash("sha256", json_encode($context, JSON_THROW_ON_ERROR));
    }
}
