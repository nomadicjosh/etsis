<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\GraduateApprovalState;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlGraduateApprovalState implements GraduateApprovalState
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function current(EntityId $pursuit): int
    {
        $row = $this->sql->rows("sis_graduate_course_approvals", ["id" => $pursuit->value], 1)[0] ?? null;
        if ($row === null) {
            return 0;
        }
        $version = filter_var($row["version"], FILTER_VALIDATE_INT);
        if ($version === false || $version < 1 || $version > 2000) {
            throw new CorruptHistoryException();
        }
        return $version;
    }

    public function advance(EntityId $pursuit, int $expected): void
    {
        if ($expected === 0) {
            $this->sql->insert("sis_graduate_course_approvals", ["id" => $pursuit->value, "version" => 1]);
        } elseif (
            $this->sql->update(
                "sis_graduate_course_approvals",
                ["version" => $expected + 1],
                ["id" => $pursuit->value, "version" => $expected],
            ) !== 1
        ) {
            throw new ConcurrentModificationException(
                \Codefy\Framework\Helpers\trans("Graduate-course approvals changed. Review again."),
            );
        }
    }
}
