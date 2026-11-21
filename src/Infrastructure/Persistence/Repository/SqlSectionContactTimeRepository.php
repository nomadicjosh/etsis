<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\SectionContactTimeRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlSectionContactTimeRepository implements SectionContactTimeRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function current(EntityId $section): array
    {
        $row = $this->sql->current("sis_section_contact_time", $section->value, "section_id");
        if ($row !== null) {
            $version = filter_var($row["version"], FILTER_VALIDATE_INT);
            $minutes =
            $row["weekly_minutes"] === null
            ? null
            : filter_var($row["weekly_minutes"], FILTER_VALIDATE_INT);
            if (
                $version === false ||
                $version < 1 ||
                $version > 2147483646 ||
                $minutes === false ||
                ($minutes !== null && ($minutes < 0 || $minutes > 10080))
            ) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
        }
        return [
            "weekly_minutes" => isset($row["weekly_minutes"]) ? (int) $row["weekly_minutes"] : null,
            "version" => (int) ($row["version"] ?? 0),
        ];
    }
    public function save(EntityId $section, ?int $minutes, int $version): void
    {
        if ($version === 0) {
            $this->sql->insert("sis_section_contact_time", [
                "section_id" => $section->value,
                "weekly_minutes" => $minutes,
                "version" => 1,
            ]);
            return;
        }
        if (
            $this->sql->update(
                "sis_section_contact_time",
                ["weekly_minutes" => $minutes, "version" => $version + 1],
                ["section_id" => $section->value, "version" => $version],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("Section contact time changed. Reload it.");
        }
    }
}
