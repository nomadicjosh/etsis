<?php

declare(strict_types=1);

namespace Domain\Import\Service;

use Domain\Import\ValueObject\CatalogImportRow;
use Domain\Import\Enum\CatalogImportTarget;

interface CatalogCsvMapper
{
    /**
     * @param array<string, int> $mapping
     * @return array{
     *     headers:list<string>, samples:list<list<string>>, row_count:int,
     *     errors:list<array{field:string,message:string}>, ready:bool, fingerprint:string,
     *     rows:list<CatalogImportRow>
     * }
     */
    public function prepare(
        #[\SensitiveParameter] string $csv,
        array $mapping,
        string $delimiter,
        CatalogImportTarget $target = CatalogImportTarget::Course,
    ): array;
}
