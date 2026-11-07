<?php

declare(strict_types=1);

namespace Domain\Catalog\Service;

use DateTimeImmutable;
use Domain\Catalog\Program;
use Domain\Catalog\Repository\CatalogRepository;
use Domain\Registration\Term;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;

final readonly class CatalogService
{
    public function __construct(private CatalogRepository $repository, private StudentAccess $access)
    {
    }

    public function createProgram(string $code, string $title): Program
    {
        $this->access->requirePermission('sis:catalog:manage');
        $program = new Program(EntityId::generate(), $code, $title);
        $this->repository->addProgram($program);

        return $program;
    }

    public function createTerm(string $code, string $start, string $end, string $deadline): Term
    {
        $this->access->requirePermission('sis:catalog:manage');
        $code = strtoupper(trim($code));
        if (preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,21}$~D', $code) !== 1) {
            throw new ValidationException([['field' => 'code',
                'message' => 'Use 1–22 letters or numbers, with dots, underscores, slashes or hyphens. '
                    . 'Start with a letter or number.']]);
        }
        $firstDay = $this->date($start, 'starts_on');
        $lastDay = $this->date($end, 'ends_on');
        $addDropDeadline = $this->date($deadline, 'add_drop_ends_on');
        if ($lastDay < $firstDay) {
            throw new ValidationException([['field' => 'ends_on',
                'message' => 'The last day must be on or after the first day.']]);
        }
        if ($addDropDeadline < $firstDay || $addDropDeadline > $lastDay) {
            throw new ValidationException([['field' => 'add_drop_ends_on',
                'message' => 'The add/drop deadline must fall between the first and last day of the term.']]);
        }
        $term = new Term(
            EntityId::generate(),
            new DateRange($firstDay, $lastDay),
            $addDropDeadline,
        );
        $this->repository->addTerm($code, $term);

        return $term;
    }

    private function date(string $value, string $field): DateTimeImmutable
    {
        $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value) === 1
        ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new ValidationException([['field' => $field,
                'message' => 'Enter a valid calendar date (YYYY-MM-DD).']]);
        }

        return $date;
    }
}
