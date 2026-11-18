<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\Repository\AdmissionCaseworkRepository;
use Domain\Admissions\Repository\AdmissionPolicyRepository;
use Domain\Admissions\ValueObject\AdmissionChecklistProof;
use Domain\Shared\ValueObject\EntityId;

/** Internal command collaborator: callers hold person/application locks and authorize. */
final readonly class AdmissionReadinessService
{
    public function __construct(
        private AdmissionCaseworkRepository $cases,
        private AdmissionPolicyRepository $policy,
    ) {
    }
    public function capture(EntityId $application): array
    {
        $this->policy->lock();
        return AdmissionChecklistProof::capture(
            $this->cases->find($application)["case"] ?? null,
            $this->policy->checklist(),
        );
    }
}
