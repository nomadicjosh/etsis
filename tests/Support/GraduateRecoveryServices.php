<?php

declare(strict_types=1);

namespace Tests\Support;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\ValueObject\EntityId;
use SisFixture;

/** Forward private fictional commands to ordinary services; no alternate rules or fixture creation. */
final readonly class GraduateRecoveryServices
{
    public function __construct(private SisFixture $sis)
    {
    }

    public function publish(
        #[\SensitiveParameter] array $command,
        ?AuditTrail $audit = null,
        ?UnitOfWork $transactions = null,
    ): array {
        $review = $command['review'];
        return $this->sis->graduatePolicies($audit, $transactions)->publish(
            new EntityId($review['publication_id']),
            $review['rules'],
            $review['version'],
            $review['reason'],
            $review['review_token'],
        );
    }

    public function approve(
        #[\SensitiveParameter] array $command,
        ?AuditTrail $audit = null,
        ?UnitOfWork $transactions = null,
    ): array {
        $review = $command['review'];
        return $this->sis->graduateApprovals($audit, $transactions)->confirm(
            new EntityId($command['student']),
            new EntityId($command['section']),
            new EntityId($command['pursuit']),
            new EntityId($review['operation_id']),
            $review['action'],
            $review['version'],
            $review['reason'],
            $review['revision'],
            $review['effective_at'],
            $review['review_token'],
        );
    }
}
