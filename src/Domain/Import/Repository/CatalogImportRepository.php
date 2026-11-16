<?php

declare(strict_types=1);

namespace Domain\Import\Repository;

use Domain\Import\ValueObject\CourseImportRow;
use Domain\Shared\ValueObject\EntityId;

interface CatalogImportRepository
{
    /** @param list<CourseImportRow> $rows
     * @return array{
     *     mappings:array<string,array<string,mixed>>,courses:array<string,list<array<string,mixed>>>,
     *     references:array<string,list<array<string,mixed>>>
     * } */
    public function inspect(string $source, array $rows): array;
    /** @param list<string> $ids
     * @return list<array<string,mixed>> */
    public function lockCourses(array $ids, bool $withReferences = false): array;
    /** @param list<array<string,mixed>> $references
     * @return list<array<string,mixed>> */
    public function lockReferences(array $references): array;
    /** @param array<string,array{id:string,code:string,title:string}> $references */
    public function create(string $source, CourseImportRow $row, array $references): EntityId;
    public function link(string $source, CourseImportRow $row, EntityId $course): void;
}
