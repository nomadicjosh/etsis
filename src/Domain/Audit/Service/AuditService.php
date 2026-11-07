<?php

declare(strict_types=1);

namespace Domain\Audit\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class AuditService
{
    public function __construct(private AuditTrail $repository, private StudentAccess $access)
    {
    }

    /** @return list<array<string, mixed>> */
    public function forSubject(EntityId $id): array
    {
        $this->access->requirePermission('sis:audit:read');

        return $this->repository->forSubject($id);
    }
}
