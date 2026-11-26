<?php

declare(strict_types=1);

namespace Domain\Import\Repository;

use Domain\Person\Person;
use Domain\Shared\ValueObject\EntityId;

interface PersonImportRepository
{
    /** @param list<string> $emails
     * @return list<string> */
    public function existingEmails(#[\SensitiveParameter] array $emails): array;
    /** @return array<string,mixed>|null */
    public function receipt(EntityId $operation): ?array;
    public function record(
        EntityId $operation,
        EntityId $actor,
        string $proofHash,
        string $encrypted,
        string $at,
    ): void;
    /** Current person/account/setup projection checks; never contains credentials or notice bodies.
     * @return array<string,scalar|null> */
    public function source(#[\SensitiveParameter] Person $person): array;
}
