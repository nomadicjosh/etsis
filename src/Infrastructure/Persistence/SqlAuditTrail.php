<?php

declare(strict_types=1);

namespace Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class SqlAuditTrail implements AuditTrail
{
    public function __construct(private SqlRecords $records)
    {
    }

    public function record(EntityId $actor, string $action, EntityId $subject): void
    {
        if (preg_match('/^[a-z][a-z._]{0,59}$/D', $action) !== 1) {
            throw new InvalidArgumentException('An audit action code is required.');
        }
        $this->records->insert('sis_audit', [
            'id' => EntityId::generate()->value,
            'actor_id' => $actor->value,
            'action' => $action,
            'subject_id' => $subject->value,
            'recorded_at' => new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
        ]);
    }

    public function forSubject(EntityId $subject): array
    {
        return $this->records->rows('sis_audit', ['subject_id' => $subject->value]);
    }
}
