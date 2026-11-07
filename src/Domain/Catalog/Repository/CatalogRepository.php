<?php

declare(strict_types=1);

namespace Domain\Catalog\Repository;

use Domain\Catalog\Program;
use Domain\Registration\Term;

interface CatalogRepository
{
    public function addProgram(Program $program): void;

    public function addTerm(string $code, Term $term): void;
}
