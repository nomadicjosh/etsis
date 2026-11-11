<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

/**
 * @phpstan-type CoursePlacement array{
 *     course_id:string,group_id:string,target_course_id:string,credits_milli:int,work_ids:list<string>
 * }
 */
final readonly class AllocationResult
{
    /** @param list<CoursePlacement> $placements */
    public function __construct(
        public AllocationOutcome $outcome,
        public array $placements,
        public int $steps,
    ) {
        if ($steps < 0 || ($outcome !== AllocationOutcome::Feasible && $placements !== [])) {
            throw new \InvalidArgumentException("Only a verified feasible result may contain placements.");
        }
    }
}
