<?php

declare(strict_types=1);

namespace Domain\Import\Service;

use Domain\Shared\ValueObject\EntityId;

interface ImportBatchProcessor
{
    public function authorize(EntityId $actor, string $target): void;
    /** Server-owned approved artifact; return only counts, never source data.
     * @param array<string,mixed> $request
     * @param list<string> $expected
     * @return array{imported:int,linked:int,skipped:int,omitted:int} */
    public function execute(
        string $target,
        #[\SensitiveParameter] array $request,
        array $expected,
        int $offset,
        int $limit,
        EntityId $actor,
    ): array;
}
