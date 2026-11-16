<?php

declare(strict_types=1);

namespace Domain\Import\Repository;

use Domain\Import\ValueObject\CourseImportRow;
use Domain\Shared\ValueObject\EntityId;

interface CatalogImportRepository
{
    /** @param list<CourseImportRow> $rows
     * @return array{mappings:array<string,array<string,mixed>>,courses:array<string,list<array<string,mixed>>>} */
    public function inspect(string $source, array $rows): array;
    /** @param list<string> $ids
     * @return list<array<string,mixed>> */
    public function lockCourses(array $ids): array;
    public function create(string $source, CourseImportRow $row): EntityId;
    public function link(string $source, CourseImportRow $row, EntityId $course): void;
}
