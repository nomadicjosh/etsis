<?php

declare(strict_types=1);

namespace Domain\Student\Query;

use Codefy\QueryBus\Query;
use Codefy\QueryBus\QueryHandler;
use Domain\Transfer\Service\TransferService;
use InvalidArgumentException;

final readonly class StudentTranscriptQueryHandler implements QueryHandler
{
    public function __construct(private TransferService $service)
    {
    }

    /** @return list<array<string, mixed>> */
    public function handle(Query $query): array
    {
        if (!$query instanceof StudentTranscriptQuery) {
            throw new InvalidArgumentException('Unsupported query.');
        }

        return $this->service->transcript($query->studentId);
    }
}
