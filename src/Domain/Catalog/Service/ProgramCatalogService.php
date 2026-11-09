<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\StudentRecordRepository;

final readonly class ProgramCatalogService
{
    public function __construct(
        private CatalogYearRepository $catalogs,
        private StudentRecordRepository $records,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return array<string, mixed> */
    public function releases(EntityId $program, ?EntityId $after = null): array
    {
        $this->access->requirePermission("sis:catalog:manage");
        return $this->page($this->catalogs->releases($program, $after)) + [
            "program" => $this->records->program($program),
        ];
    }

    /** @return array<string, mixed> */
    public function createRelease(EntityId $id, EntityId $program, EntityId $year): array
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        return $this->transactions->run(function () use ($id, $program, $year, $actor): array {
            $this->catalogs->lockProgram($program);
            $this->catalogs->lockYear($year);
            try {
                $existing = $this->catalogs->release($id);
            } catch (RecordNotFoundException) {
                $existing = null;
            }
            if ($existing !== null) {
                if ($existing["program_id"] !== $program->value || $existing["year_id"] !== $year->value) {
                    throw new ConcurrentModificationException("This catalog reference belongs to another draft.");
                }
                return $existing;
            }
            $this->catalogs->year($year);
            $label = $this->records->program($program);
            $this->catalogs->addRelease($id, [
                "program_id" => $program->value,
                "year_id" => $year->value,
                "revision" => $this->catalogs->nextRevision($program, $year),
                "published" => 0,
                "is_current" => 0,
                "program_code" => $label["code"],
                "program_title" => $label["title"],
            ]);
            $this->audit->record($actor, "catalog.program_draft_created", $id);
            return $this->catalogs->release($id);
        });
    }

    public function publishRelease(EntityId $program, EntityId $id): void
    {
        $actor = $this->access->requirePermission("sis:catalog:manage");
        $routing = $this->catalogs->release($id);
        if ($routing["program_id"] !== $program->value) {
            throw new RecordNotFoundException("Program catalog not found.");
        }
        $this->transactions->run(function () use ($program, $id, $routing, $actor): void {
            $this->catalogs->lockProgram($program);
            $this->catalogs->lockYear(new EntityId($routing["year_id"]));
            $current = $this->catalogs->release($id);
            if ($current["published"]) {
                return;
            }
            if (!$this->catalogs->year(new EntityId($current["year_id"]))->published) {
                throw new ValidationException([
                    [
                        "field" => "year",
                        "message" => "This derived academic-year reference is unavailable. Review calendar setup.",
                    ],
                ]);
            }
            $this->catalogs->publishRelease($id, $program);
            $this->audit->record($actor, "catalog.program_published", $id);
        });
    }

    /** @param list<array<string, mixed>> $rows
     * @return array<string, mixed>
     */
    private function page(array $rows): array
    {
        return ["items" => array_slice($rows, 0, 50), "next_after" => count($rows) > 50 ? $rows[49]["id"] : null];
    }
}
