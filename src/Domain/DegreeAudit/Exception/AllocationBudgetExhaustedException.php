<?php

declare(strict_types=1);

namespace Domain\DegreeAudit\Exception;

/** @internal Stop work rather than present an unproven placement as authoritative. */
final class AllocationBudgetExhaustedException extends \RuntimeException
{
}
