<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Communications\Service\MessageCipher;
use Domain\HumanResources\Repository\PrimaryPositionRepository;
use Domain\HumanResources\ValueObject\PrimaryPosition;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlPrimaryPositionRepository implements PrimaryPositionRepository
{
    public function __construct(private SqlRecords $sql, private MessageCipher $cipher)
    {
    }
    public function find(EntityId $employee): ?PrimaryPosition
    {
        $row =
        $this->sql->rows("sis_primary_positions", ["employee_id" => $employee->value], 1)[0] ??
        null;
        if ($row === null) {
            return null;
        }
        try {
            if (
                !is_string($row["encrypted_designation"]) ||
                strlen($row["encrypted_designation"]) > 16384
            ) {
                throw new CorruptHistoryException();
            }
            $data = json_decode(
                $this->cipher->open($row["encrypted_designation"]),
                true,
                10,
                JSON_THROW_ON_ERROR,
            );
            if (
                !is_array($data) ||
                count($data) !== 2 ||
                ($data["purpose"] ?? null) !== "hr.primary-position"
            ) {
                throw new CorruptHistoryException();
            }
            $designation = PrimaryPosition::restore($data["facts"]);
            if (
                $designation->employee->value !== $employee->value ||
                (string) $designation->version !== (string) $row["version"]
            ) {
                throw new CorruptHistoryException();
            }
            return $designation;
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }
    public function save(PrimaryPosition $designation, int $expectedVersion): void
    {
        if ($designation->version !== $expectedVersion + 1) {
            throw new ConcurrentModificationException();
        }
        $data = [
            "encrypted_designation" => $this->cipher->seal(
                json_encode(
                    ["purpose" => "hr.primary-position", "facts" => $designation->facts()],
                    JSON_THROW_ON_ERROR,
                ),
            ),
            "version" => $designation->version,
        ];
        if ($expectedVersion === 0) {
            $this->sql->insert(
                "sis_primary_positions",
                ["employee_id" => $designation->employee->value] + $data,
            );
        } elseif (
            $this->sql->update("sis_primary_positions", $data, [
                "employee_id" => $designation->employee->value,
                "version" => $expectedVersion,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("Review the primary position again.");
        }
    }
}
