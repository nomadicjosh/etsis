<?php

declare(strict_types=1);

namespace Domain\Transfer\Service;

use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferCatalogRepository;
use InvalidArgumentException;

/** Acquire the complete catalog context before starting consistent reads. */
final class TransferCatalogLock
{
    /** @param array<string, mixed> $record */
    public static function acquire(TransferCatalogRepository $repository, string $kind, array $record): void
    {
        $locks = match ($kind) {
            'institutions' => [['institutions', 'id']],
            'external' => [['institutions', 'institution_id'], ['external', 'id']],
            'equivalencies' => [['institutions', 'institution_id'], ['external', 'external_course_id'],
                ['equivalencies', 'id'], ['courses', 'course_id']],
            default => throw new InvalidArgumentException('Unknown transfer catalog record.'),
        };
        foreach ($locks as [$table, $field]) {
            $repository->lock($table, new EntityId((string) $record[$field]));
        }
    }
}
