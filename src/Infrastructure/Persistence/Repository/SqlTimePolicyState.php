<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\HumanResources\Repository\TimePolicyState;
use Domain\HumanResources\TimeApprovalPolicy;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlTimePolicyState implements TimePolicyState
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function current(): int
    {
        $row =
        $this->sql->current("sis_time_policy_state", TimeApprovalPolicy::ID) ??
        throw new CorruptHistoryException();
        $version = filter_var($row["version"], FILTER_VALIDATE_INT);
        if ($version === false || $version < 0 || $version > 2000) {
            throw new CorruptHistoryException();
        }
        return $version;
    }
    public function advance(int $expected): void
    {
        if (
            $this->sql->update(
                "sis_time_policy_state",
                ["version" => $expected + 1],
                ["id" => TimeApprovalPolicy::ID, "version" => $expected],
            ) !== 1
        ) {
            throw new ConcurrentModificationException(
                "The institutional workload policy changed. Review again.",
            );
        }
    }
}
