<?php

declare(strict_types=1);

namespace Domain\Import\Repository;

use Domain\Import\ValueObject\CatalogImportRow;
use Domain\Import\Enum\CatalogImportTarget;
use Domain\Shared\ValueObject\EntityId;

interface CatalogImportRepository
{
    /** @param list<CatalogImportRow> $rows
     * @return array{
     *     mappings:array<string,array<string,mixed>>,records:array<string,list<array<string,mixed>>>,
     *     references:array<string,list<array<string,mixed>>>
     * } */
    public function inspect(string $source, array $rows): array;
    /** @param list<string> $ids
     * @return list<array<string,mixed>> */
    public function lockRecords(
        array $ids,
        bool $withReferences = false,
        CatalogImportTarget $target = CatalogImportTarget::Course,
    ): array;
    /** @param list<array<string,mixed>> $references
     * @return list<array<string,mixed>> */
    public function lockReferences(array $references): array;
    /** @param array<string,array{id:string,code:string,title:string}> $references */
    public function create(string $source, CatalogImportRow $row, array $references): EntityId;
    public function link(string $source, CatalogImportRow $row, EntityId $course): void;
}
