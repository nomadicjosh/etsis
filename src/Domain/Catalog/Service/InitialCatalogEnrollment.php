<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\StudentProgram;

final readonly class InitialCatalogEnrollment implements CatalogEnrollment
{
    public function __construct(
        private CatalogYearRepository $catalogs,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    public function initialize(StudentProgram $assignment, EntityId $actor): void
    {
        $this->catalogs->lockProgram($assignment->programId);
        if ($this->catalogs->binding($assignment->id) !== null) {
            return;
        }
        $id = $this->catalogs->derived($assignment->programId, $assignment->entryTermId);
        if ($id === null) {
            return;
        } // Unmapped legacy terms require a calendar review; never infer from overlapping dates.
        $this->catalogs->bind($assignment->id, $id, -1);
        $this->catalogs->record(
            EntityId::generate(),
            $assignment->id,
            $actor,
            null,
            $id,
            null,
            $this->clock
                ->now()
                ->setTimezone(new DateTimeZone("UTC"))
                ->format(DATE_ATOM),
        );
        $this->audit->record($actor, "student.catalog_assigned_from_cohort", $assignment->id);
    }
}
