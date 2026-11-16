<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Communications\Service\MessageCipher;
use Domain\Finance\Repository\BillingBatchRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlBillingBatchRepository implements BillingBatchRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }

    public function receipt(EntityId $id): ?array
    {
        $row = $this->sql->rows('sis_billing_batches', ['id' => $id->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        return ['actor_id' => $row['actor_id'], 'request_hash' => $row['request_hash'],
            'result' => json_decode($this->cipher->open($row['encrypted_result']), true, 32, JSON_THROW_ON_ERROR)];
    }

    public function save(
        EntityId $id,
        EntityId $actor,
        EntityId $term,
        string $hash,
        #[\SensitiveParameter] array $result,
        string $on,
    ): void {
        try {
            $this->sql->insert('sis_billing_batches', ['id' => $id->value, 'actor_id' => $actor->value,
                'term_id' => $term->value, 'request_hash' => $hash,
                'encrypted_result' => $this->cipher->seal(json_encode($result, JSON_THROW_ON_ERROR)),
                'completed_at' => $on]);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                throw new ConcurrentModificationException(
                    'This billing operation completed concurrently. Retry its original confirmation.'
                );
            }
            throw $error;
        }
    }
}
