<?php

declare(strict_types=1);

namespace Domain\Import\Service;

interface PersonCsvMapper
{
    /** @param array<string,int> $mapping
     * @return array{headers:list<string>,rows:list<array<string,mixed>>} */
    public function prepare(
        #[\SensitiveParameter] string $csv,
        array $mapping,
        string $delimiter,
    ): array;
}
