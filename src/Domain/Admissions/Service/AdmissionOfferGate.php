<?php

declare(strict_types=1);

namespace Domain\Admissions\Service;

use Domain\Admissions\AdmissionApplication;
use Domain\Admissions\Repository\AdmissionOfferPolicyRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Service\Clock;

/** Called under the application/person locks. Completed matriculation receipts bypass new eligibility rules. */
final readonly class AdmissionOfferGate
{
    public function __construct(
        private AdmissionOfferPolicyRepository $policy,
        private AdmissionDecisionLedger $ledger,
        private AdmissionsRepository $admissions,
        private Clock $clock,
    ) {
    }
    /** @return array<string,mixed> */
    public function proof(AdmissionApplication $a): array
    {
        $this->policy->lock();
        $policy = $this->policy->get();
        $record = $this->ledger->offer($a);
        $offer = $record['offer'] ?? null;
        $required = $offer['require_acceptance'] ?? $policy['require_acceptance'];
        if ($offer !== null) {
            $owner = $this->admissions->person($a->personId)->ownerUserId?->value;
            if (
                $owner !== $offer['owner_id'] || in_array($offer['status'], ['declined', 'cancelled'], true)
                || ($offer['status'] === 'pending' && $offer['respond_by'] !== null
                    && new \DateTimeImmutable($offer['respond_by']) < $this->clock->now())
            ) {
                throw new ValidationException([
                    ['field' => 'offer', 'message' => 'Review and reissue this admission offer before matriculation.'],
                ]);
            }
        }
        if ($required && ($offer['status'] ?? null) !== 'accepted') {
            throw new ValidationException([
                ['field' => 'offer', 'message' => 'The applicant must accept an admission offer before matriculation.'],
            ]);
        }
        return ['offer_id' => $offer['id'] ?? null, 'operation_id' => $record['operation_id'] ?? null,
            'require_acceptance' => $required, 'policy_revision' => $offer['policy_revision'] ?? $policy['revision']];
    }
}
