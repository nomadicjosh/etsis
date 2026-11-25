<?php

declare(strict_types=1);

namespace Domain\Finance\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Protected isolated output, including original projections for review; never a financial decision. */
final readonly class AccountProjectionStage implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] public array $data)
    {
        $keys = ['purpose', 'id', 'account_id', 'source', 'identity', 'storage_stamp', 'scope', 'created_at',
            'events_checked', 'postings_checked', 'before', 'after'];
        if (
            count($data) !== count($keys) || array_diff(array_keys($data), $keys) !== []
            || ($data['purpose'] ?? null) !== 'finance.account_projection_stage'
            || !is_string($data['created_at'])
            || !is_array($data['source']) || !is_array($data['identity'])
            || !is_array($data['before']) || !is_array($data['after'])
            || !is_string($data['storage_stamp']) || preg_match('/^[a-f0-9]{64}$/D', $data['storage_stamp']) !== 1
            || !is_string($data['scope']) || preg_match('/^[a-f0-9]{64}$/D', $data['scope']) !== 1
            || !is_int($data['events_checked']) || $data['events_checked'] < 1 || $data['events_checked'] > 2000
            || !is_int($data['postings_checked']) || $data['postings_checked'] < 0 || $data['postings_checked'] > 2000
            || strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 8_388_608
        ) {
            throw new InvalidArgumentException('Invalid protected projection stage.');
        }
        new EntityId($data['id']);
        new AccountId($data['account_id']);
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $data['created_at']);
        if (!$date || $date->format('Y-m-d\TH:i:s.u\Z') !== $data['created_at']) {
            throw new InvalidArgumentException('Invalid projection stage time.');
        }
    }

    public function describe(string $status, bool $changed = false): array
    {
        return ['status' => $status, 'stage_reference' => $this->data['id'],
            'events_checked' => $this->data['events_checked'], 'postings_checked' => $this->data['postings_checked'],
            'source_position' => (int) ($this->data['source']['version'] ?? 0), 'projections_changed' => $changed];
    }

    public function __debugInfo(): array
    {
        return ['stage' => '[protected account projection output]'];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }
}
