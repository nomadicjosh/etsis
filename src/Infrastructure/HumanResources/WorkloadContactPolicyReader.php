<?php

declare(strict_types=1);

namespace Infrastructure\HumanResources;

use Domain\HumanResources\Service\TimeApprovalPolicyService;
use Domain\Registration\Repository\ContactHoursPolicyReader;

final readonly class WorkloadContactPolicyReader implements ContactHoursPolicyReader
{
    public function __construct(private TimeApprovalPolicyService $policies)
    {
    }
    public function current(): array
    {
        $p = $this->policies->current();
        return [
            "publication_id" => $p["publication_id"],
            "version" => $p["version"],
            "unit_minutes" => $p["rules"]["contact_unit_minutes"],
            "rounding" => $p["rules"]["contact_rounding"],
        ];
    }
}
