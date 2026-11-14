<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\AcademicRecord\Repository\WorkPursuitRepository;
use Domain\AcademicRecord\WorkPursuit;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlWorkPursuitRepository implements WorkPursuitRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function projection(EntityId $credit): ?array
    {
        $row = $this->sql->rows('sis_work_pursuit_scopes', ['id' => $credit->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        try {
            $p = json_decode($row['projection_json'], true, 8, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) || ($p['source']['credit_id'] ?? null) !== $row['id'] ||
                ($p['source']['student_id'] ?? null) !== $row['student_id'] ||
                ($p['version'] ?? null) !== (int) $row['version']
            ) {
                throw new CorruptHistoryException();
            }
            return $p;
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function save(WorkPursuit $root, int $expectedVersion): void
    {
        $p = $root->projection() ?? throw new CorruptHistoryException();
        $row = ['id' => (string) $root->aggregateId(), 'student_id' => $p['source']['student_id'],
            'version' => $root->playhead(), 'projection_json' => json_encode($p, JSON_THROW_ON_ERROR)];
        if ($expectedVersion === 0) {
            $this->sql->insert('sis_work_pursuit_scopes', $row);
        } elseif (
            $this->sql->update(
                'sis_work_pursuit_scopes',
                $row,
                ['id' => $row['id'], 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The academic-work pursuit changed. Review it again.');
        }
    }
}
