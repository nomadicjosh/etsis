<?php

declare(strict_types=1);

namespace Domain\Import\Repository;

use Domain\Catalog\Course;

interface CatalogImportRepository
{
    public function import(string $source, string $externalId, Course $course): bool;
}
