<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Communications\Repository\CampaignDispatchRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlCampaignDispatchRepository implements CampaignDispatchRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function get(EntityId $campaign): ?array
    {
        return $this->sql->rows('sis_campaign_dispatches', ['id' => $campaign->value], 1)[0] ?? null;
    }

    public function save(array $record, int $expectedVersion): void
    {
        if ($expectedVersion === 0) {
            $this->sql->insert('sis_campaign_dispatches', $record);
        } elseif (
            $this->sql->update(
                'sis_campaign_dispatches',
                $record,
                ['id' => $record['id'], 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('Reload background delivery before continuing.');
        }
    }

    public function pending(DateTimeImmutable $dueThrough): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            'SELECT j.* FROM ' . $q('sis_campaign_dispatches') . ' j JOIN '
            . $q('sis_campaigns') . ' c ON c.' . $q('id') . ' = j.' . $q('id')
            . ' WHERE j.' . $q('status') . ' = ? AND c.' . $q('status') . ' IN (?, ?)'
            . ' AND (j.' . $q('not_before_at') . ' IS NULL OR j.' . $q('not_before_at') . ' <= ?)'
            . ' ORDER BY j.' . $q('updated_at') . ', j.' . $q('id'),
            ['active', 'queued', 'completed', $dueThrough->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.u\Z')],
            10
        );
    }
}
