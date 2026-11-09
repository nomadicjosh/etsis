<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;
use Domain\Student\StudentProgram;

final readonly class StudentCatalogService
{
    public function __construct(
        private CatalogYearRepository $catalogs,
        private StudentRecordRepository $records,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string, mixed> */
    public function view(EntityId $student, EntityId $assignment, ?EntityId $after = null): array
    {
        $this->access->requirePermission("sis:registration:manage");
        $program = $this->owned($student, $assignment);
        $binding = $this->catalogs->binding($assignment);
        return [
            "assignment_id" => $assignment->value,
            "program" => $this->records->program($program->programId),
            "entry_term" => $this->records->term($program->entryTermId),
            "version" => $binding["version"] ?? -1,
            "current" => $binding === null ? null : $this->catalogs->release(new EntityId($binding["catalog_id"])),
            "choices" => [],
            "next_after" => null,
        ];
    }

    public function change(
        EntityId $student,
        EntityId $assignment,
        EntityId $catalog,
        EntityId $operation,
        int $version,
        #[\SensitiveParameter] string $reason,
    ): void {
        $actor = $this->access->requirePermission("sis:registration:manage");
        $note = trim($reason);
        if (
            !mb_check_encoding($note, "UTF-8") ||
            $note === "" ||
            mb_strlen($note) > 10000 ||
            preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $note)
        ) {
            throw new ValidationException([
                ["field" => "note", "message" => "Enter a registrar reason of up to 10,000 characters."],
            ]);
        }
        $request = ["version" => $version, "note" => $note];
        $this->transactions->run(function () use (
            $student,
            $assignment,
            $catalog,
            $operation,
            $version,
            $actor,
            $request,
        ): void {
            $this->records->lock($student);
            $program = $this->owned($student, $assignment);
            $this->catalogs->lockProgram($program->programId);
            $previous = $this->catalogs->action($operation);
            if ($previous !== null) {
                if (
                    $previous["assignment_id"] !== $assignment->value ||
                    $previous["actor_id"] !== $actor->value ||
                    $previous["catalog_id"] !== $catalog->value ||
                    $previous["encrypted_note"] === null ||
                    json_decode($this->cipher->open($previous["encrypted_note"]), true, 16, JSON_THROW_ON_ERROR) !==
                        $request
                ) {
                    throw new ConcurrentModificationException("The catalog operation belongs to another change.");
                }
                return;
            }
            $binding = $this->catalogs->binding($assignment);
            if (($binding["version"] ?? -1) !== $version) {
                throw new ConcurrentModificationException("The assigned catalog changed. Review it again.");
            }
            $release = $this->catalogs->release($catalog);
            if ($release["program_id"] !== $program->programId->value || !$release["published"]) {
                throw new ValidationException([
                    ["field" => "catalog_id", "message" => "Choose a published catalog for this program."],
                ]);
            }
            $this->catalogs->bind($assignment, $catalog, $version);
            $this->catalogs->record(
                $operation,
                $assignment,
                $actor,
                $binding === null ? null : new EntityId($binding["catalog_id"]),
                $catalog,
                $this->cipher->seal(json_encode($request, JSON_THROW_ON_ERROR)),
                $this->clock
                    ->now()
                    ->setTimezone(new DateTimeZone("UTC"))
                    ->format(DATE_ATOM),
            );
            $this->audit->record($actor, "student.catalog_changed", $assignment);
        });
    }

    /** @return array<string, mixed> */
    public function history(EntityId $student, EntityId $assignment, ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission("sis:registration:manage");
        $this->owned($student, $assignment);
        $rows = $this->catalogs->history($assignment, $after);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        foreach ($rows as &$r) {
            $r["note"] =
            $r["encrypted_note"] === null
            ? "Assigned from the published matriculation cohort."
            : json_decode($this->cipher->open($r["encrypted_note"]), true, 16, JSON_THROW_ON_ERROR)["note"];
            unset($r["encrypted_note"]);
        }
        unset($r);
        $this->audit->record($actor, "student.catalog_history_read", $assignment);
        return ["items" => $rows, "next_after" => $more ? $rows[49]["id"] : null];
    }

    private function owned(EntityId $student, EntityId $assignment): StudentProgram
    {
        $program = $this->records->assignment($assignment);
        if ($program->studentId->value !== $student->value) {
            throw new RecordNotFoundException("Student program not found.");
        }
        return $program;
    }
}
