<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

/** Calculations for the supplied input set; not a retained audit result or a credential decision. */
final readonly class AcademicPolicyResult
{
    /** @param list<array<string,mixed>> $creditPools Equal-credit alternatives are retained without guessing a winner.
     * @param list<array<string,mixed>> $gpaGroups Numerically equivalent alternatives contribute only once.
     * @param list<array{id:string,credit:string,gpa:string}> $dispositions
     * @param list<array{component:string,code:string,course_id:?string,work_ids:list<string>}> $issues
     */
    public function __construct(
        public AuditCalculationPolicy $policy,
        public ?int $earnedMilli,
        public ?int $residentMinimumMilli,
        public ?int $residentMaximumMilli,
        public ?GpaTotals $gpa,
        public array $creditPools,
        public array $gpaGroups,
        public array $dispositions,
        public array $issues,
    ) {
    }
}
