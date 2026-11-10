<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Repository\ClassificationRepository;
use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Catalog\ValueObject\CourseClassifications;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlClassificationRepository implements ClassificationRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function get(EntityId $id): array
    {
        $row = $this->sql->one("sis_catalog_classifications", ["id" => $id->value]);
        $row["active"] = (bool) $row["active"];
        return $row;
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock("sis_catalog_classifications", $id->value, "id");
    }

    public function page(ClassificationKind $kind, string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $where = [$q("kind") . " = ?"];
        $params = [$kind->value];
        if ($search !== "") {
            $where[] = "(" . $q("code") . " LIKE ? OR " . $q("title") . " LIKE ?)";
            $params[] = "%" . $search . "%";
            $params[] = "%" . $search . "%";
        }
        if ($after !== null) {
            $where[] = $q("id") . " > ?";
            $params[] = $after->value;
        }
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_catalog_classifications") .
                " WHERE " .
                implode(" AND ", $where) .
                " ORDER BY " .
                $q("id"),
            $params,
            51,
        );
        foreach ($rows as &$row) {
            $row["active"] = (bool) $row["active"];
        }
        unset($row);
        return $rows;
    }

    public function save(
        ClassificationKind $kind,
        ClassificationSnapshot $value,
        bool $active,
        bool $create,
    ): void {
        $other =
        $this->sql->rows(
            "sis_catalog_classifications",
            ["kind" => $kind->value, "code" => $value->code],
            1,
        )[0] ?? null;
        if ($other !== null && $other["id"] !== $value->id->value) {
            throw new ValidationException([
                ["field" => "code", "message" => "That code is already used in this category."],
            ]);
        }
        $row = $value->describe() + ["kind" => $kind->value, "active" => (int) $active];
        if ($create) {
            $this->sql->insert("sis_catalog_classifications", $row);
        } else {
            unset($row["id"]);
            $this->sql->update("sis_catalog_classifications", $row, ["id" => $value->id->value]);
        }
    }

    public function lockForCourse(EntityId $course, array $additional = []): void
    {
        $row = $this->sql->rows("sis_course_classifications", ["id" => $course->value], 1)[0] ?? [];
        $ids = array_map(static fn(EntityId $id): string => $id->value, $additional);
        foreach (ClassificationKind::courseKinds() as $kind) {
            if (($row[$kind->value . "_id"] ?? null) !== null) {
                $ids[] = $row[$kind->value . "_id"];
            }
        }
        $ids = array_unique($ids);
        sort($ids, SORT_STRING);
        foreach ($ids as $id) {
            $this->lock(new EntityId($id));
        }
    }

    public function course(EntityId $course): CourseClassifications
    {
        $row = $this->sql->rows("sis_course_classifications", ["id" => $course->value], 1)[0] ?? [];
        $values = [];
        foreach (ClassificationKind::courseKinds() as $kind) {
            $id = $row[$kind->value . "_id"] ?? null;
            $value = $id === null ? null : $this->get(new EntityId($id));
            if ($value !== null && $value["kind"] !== $kind->value) {
                throw new CorruptHistoryException();
            }
            $values[$kind->value] =
            $value === null ? null : array_intersect_key($value, array_flip(["id", "code", "title"]));
        }
        return CourseClassifications::fromArray($values);
    }

    public function assign(EntityId $course, CourseClassifications $values): void
    {
        $row = [];
        foreach ($values->describe() as $kind => $value) {
            $row[$kind . "_id"] = $value["id"] ?? null;
        }
        if ($this->sql->rows("sis_course_classifications", ["id" => $course->value], 1) === []) {
            $this->sql->insert("sis_course_classifications", ["id" => $course->value] + $row);
        } else {
            $this->sql->update("sis_course_classifications", $row, ["id" => $course->value]);
        }
    }
}
