<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\RegistrationBatchRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlRegistrationBatchRepository implements RegistrationBatchRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function receipt(EntityId $id): ?array
    {
        $rows = $this->sql->rows('sis_registration_batches', ['id' => $id->value], 1);
        if ($rows === []) {
            return null;
        }
        return ['actor_id' => $rows[0]['actor_id'], 'request_hash' => $rows[0]['request_hash'],
            'result' => json_decode($rows[0]['result_json'], true, 32, JSON_THROW_ON_ERROR)];
    }

    public function save(
        EntityId $id,
        EntityId $actor,
        EntityId $section,
        string $hash,
        array $result,
        string $on
    ): void {
        try {
            $this->sql->insert('sis_registration_batches', ['id' => $id->value, 'actor_id' => $actor->value,
                'section_id' => $section->value, 'request_hash' => $hash,
                'result_json' => json_encode($result, JSON_THROW_ON_ERROR), 'completed_at' => $on]);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ['23000', '23505'], true)) {
                // Let the transaction roll back before a caller checks the existing receipt.
                throw new ConcurrentModificationException('Check the batch receipt before retrying this operation.');
            }
            throw $error;
        }
    }
}
