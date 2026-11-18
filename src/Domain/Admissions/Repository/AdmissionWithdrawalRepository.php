<?php

declare(strict_types=1);

namespace Domain\Admissions\Repository;

use Domain\Shared\ValueObject\EntityId;

/** Encrypted operational requests and bounded receipts; not another domain event journal. */
interface AdmissionWithdrawalRepository
{
    public function revision(EntityId $application): int;
    public function find(EntityId $id): ?array;
    public function pending(EntityId $application): ?array;
    /** @return list<array<string,mixed>> */
    public function page(EntityId $application, ?EntityId $after = null): array;
    /** @return list<array<string,mixed>> */
    public function queue(?EntityId $after = null): array;
    /** Under the person/application lock and caller's transaction. */
    public function save(#[\SensitiveParameter] array $request, int $expectedRevision, bool $new): void;
}
