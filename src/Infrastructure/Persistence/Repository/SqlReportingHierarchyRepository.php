<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Repository\ReportingHierarchyRepository;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

use function Codefy\Framework\Helpers\trans_html;

final readonly class SqlReportingHierarchyRepository implements ReportingHierarchyRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lock(): void
    {
        $this->sql->lock("sis_reporting_hierarchy_state", self::STATE_ID, "id");
    }

    public function version(): int
    {
        $row = $this->sql->current("sis_reporting_hierarchy_state", self::STATE_ID);
        $version = filter_var($row["version"] ?? null, FILTER_VALIDATE_INT);
        if ($version === false || $version < 0) {
            throw new CorruptHistoryException();
        }
        return $version;
    }

    public function advance(int $expected): void
    {
        if (
            $this->sql->update(
                "sis_reporting_hierarchy_state",
                ["version" => $expected + 1],
                ["id" => self::STATE_ID, "version" => $expected],
            ) !== 1
        ) {
            throw new ConcurrentModificationException(
                trans_html("Reload the reporting hierarchy before saving."),
            );
        }
    }

    public function parent(EntityId $group): ?EntityId
    {
        $row = $this->sql->current("sis_reporting_hierarchy", $group->value);
        return ($row["parent_id"] ?? null) === null ? null : new EntityId($row["parent_id"]);
    }

    public function peekParent(EntityId $group): ?EntityId
    {
        $row = $this->sql->rows('sis_reporting_hierarchy', ['id' => $group->value], 1)[0] ?? null;
        return ($row['parent_id'] ?? null) === null ? null : new EntityId($row['parent_id']);
    }

    public function assign(EntityId $group, ?EntityId $parent): void
    {
        if ($this->sql->current("sis_reporting_hierarchy", $group->value) === null) {
            $this->sql->insert("sis_reporting_hierarchy", [
                "id" => $group->value,
                "parent_id" => $parent?->value,
            ]);
        } else {
            $this->sql->update(
                "sis_reporting_hierarchy",
                ["parent_id" => $parent?->value],
                ["id" => $group->value],
            );
        }
    }

    public function relations(): array
    {
        $rows = $this->sql->rows("sis_reporting_hierarchy", [], 501);
        if (count($rows) > 500) {
            throw new ConcurrentModificationException(
                trans_html("The reporting hierarchy exceeds the bounded setup limit."),
            );
        }
        $relations = array_column($rows, "parent_id", "id");
        ksort($relations, SORT_STRING);
        return $relations;
    }
}
