<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\ValueObject;

/** Structural feasibility for supplied eligible inputs; never graduation eligibility. */
enum AllocationOutcome: string
{
    case Feasible = "feasible";
    case Infeasible = "infeasible";
    case Undetermined = "undetermined";
}
