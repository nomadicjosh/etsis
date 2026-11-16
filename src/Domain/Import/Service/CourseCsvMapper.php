<?php

declare(strict_types=1);

namespace Domain\Import\Service;

use Domain\Import\ValueObject\CourseImportRow;

interface CourseCsvMapper
{
    /**
     * @param array<string, int> $mapping
     * @return array{
     *     headers:list<string>, samples:list<list<string>>, row_count:int,
     *     errors:list<array{field:string,message:string}>, ready:bool, fingerprint:string,
     *     rows:list<CourseImportRow>
     * }
     */
    public function prepare(#[\SensitiveParameter] string $csv, array $mapping, string $delimiter): array;
}
