<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Program;
use Domain\Catalog\Repository\CatalogRepository;
use Domain\Registration\Term;
use Qubus\Expressive\Database;

final readonly class DbalCatalogRepository implements CatalogRepository
{
    public function __construct(private Database $db)
    {
    }

    public function addProgram(Program $program): void
    {
        $this->db->table('sis_programs')->insert([
            'id' => $program->id->value,
            'code' => $program->code,
            'title' => $program->title,
        ]);
    }

    public function addTerm(string $code, Term $term): void
    {
        $end = $term->dates->end ?? throw new \LogicException('A term must have an end date.');
        $this->db->table('sis_terms')->insert([
            'id' => $term->id->value,
            'code' => $code,
            'starts_on' => $term->dates->start->format('Y-m-d'),
            'ends_on' => $end->format('Y-m-d'),
            'add_drop_ends_on' => $term->addDropEndsOn->format('Y-m-d'),
        ]);
    }
}
