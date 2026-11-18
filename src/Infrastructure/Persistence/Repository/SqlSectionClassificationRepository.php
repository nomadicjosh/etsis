<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Repository\ClassificationRepository;
use Domain\Registration\Repository\SectionClassificationRepository;
use Domain\Registration\ValueObject\SectionClassifications;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlSectionClassificationRepository implements SectionClassificationRepository
{
    public function __construct(
        private SqlRecords $sql,
        private ClassificationRepository $references,
    ) {
    }

    public function lockReferences(
        EntityId $section,
        array $additional = [],
    ): void {
        $row =
        $this->sql->current(
            "sis_section_classifications",
            $section->value,
        ) ?? [];
        $ids = array_map(
            static fn(EntityId $id): string => $id->value,
            $additional,
        );
        foreach (["instructional_method_id", "campus_id"] as $key) {
            if (($row[$key] ?? null) !== null) {
                $ids[] = $row[$key];
            }
        }
        $ids = array_unique($ids);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $this->references->lock(new EntityId($id));
        }
    }

    public function current(EntityId $section): SectionClassifications
    {
        $row =
        $this->sql->current(
            "sis_section_classifications",
            $section->value,
        ) ?? [];
        $values = [];
        $kinds = [
            ClassificationKind::InstructionalMethod,
            ClassificationKind::Campus,
        ];
        foreach ($kinds as $kind) {
            $id = $row[$kind->value . "_id"] ?? null;
            $value =
            $id === null ? null : $this->references->get(new EntityId($id));
            if ($value !== null && $value["kind"] !== $kind->value) {
                throw new CorruptHistoryException();
            }
            $values[$kind->value] =
            $value === null
            ? null
            : array_intersect_key(
                $value,
                array_flip(["id", "code", "title"]),
            );
        }
        return SectionClassifications::fromArray($values);
    }

    public function assign(
        EntityId $section,
        SectionClassifications $values,
    ): void {
        $row = [
            "instructional_method_id" =>
                $values->instructionalMethod?->id->value,
            "campus_id" => $values->campus?->id->value,
        ];
        if (
            $this->sql->current(
                "sis_section_classifications",
                $section->value,
            ) === null
        ) {
            $this->sql->insert(
                "sis_section_classifications",
                ["id" => $section->value] + $row,
            );
        } else {
            $this->sql->update("sis_section_classifications", $row, [
                "id" => $section->value,
            ]);
        }
    }
}
