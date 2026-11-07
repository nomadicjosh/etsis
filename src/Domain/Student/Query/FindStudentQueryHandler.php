<?php

declare(strict_types=1);

namespace Domain\Student\Query;

use Codefy\QueryBus\Query;
use Codefy\QueryBus\QueryHandler;
use Domain\Admissions\Service\AdmissionsService;
use Domain\Student\Student;
use InvalidArgumentException;

final readonly class FindStudentQueryHandler implements QueryHandler
{
    public function __construct(private AdmissionsService $service)
    {
    }

    public function handle(Query $query): Student
    {
        if (!$query instanceof FindStudentQuery) {
            throw new InvalidArgumentException('Unsupported query.');
        }

        return $this->service->student($query->studentId);
    }
}
