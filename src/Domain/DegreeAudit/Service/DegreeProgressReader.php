<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Service;

use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\ValueObject\EntityId;

/** Authorized current progress from retained requirements and verified academic inputs. */
interface DegreeProgressReader
{
    /** @return array<string,mixed> */
    public function preview(
        EntityId $student,
        EntityId $assignment,
        ?HistoryReadBudget $budget = null,
    ): array;
    /** Owner/staff curriculum review; degree-only pursuits keep their ordinary result shape.
     * @return array<string,mixed>
     */
    public function student(EntityId $student, EntityId $assignment, ?HistoryReadBudget $budget = null): array;
}
