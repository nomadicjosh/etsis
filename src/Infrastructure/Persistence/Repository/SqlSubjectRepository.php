<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Repository\SubjectRepository;
use Domain\Catalog\ValueObject\SubjectSnapshot;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlSubjectRepository implements SubjectRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function get(EntityId $id): array
    {
        $r = $this->sql->one("sis_subjects", ["id" => $id->value]);
        $r["active"] = (bool) $r["active"];
        return $r;
    }
    public function lock(EntityId $id): void
    {
        $this->sql->lock("sis_subjects", $id->value, "id");
    }
    public function page(string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $params = [];
        if ($search !== "") {
            $where[] = "(" . $q("code") . " LIKE ? OR " . $q("title") . " LIKE ?)";
            $params = ["%" . $search . "%", "%" . $search . "%"];
        }
        if ($after !== null) {
            $where[] = $q("id") . " > ?";
            $params[] = $after->value;
        }
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_subjects") .
                ($where === [] ? "" : " WHERE " . implode(" AND ", $where)) .
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
    public function save(SubjectSnapshot $subject, bool $active, bool $create): void
    {
        $other = $this->sql->rows("sis_subjects", ["code" => $subject->code], 1)[0] ?? null;
        if ($other !== null && $other["id"] !== $subject->id->value) {
            throw new \Domain\Shared\Exception\ValidationException([
                ["field" => "code", "message" => "That subject code is already in use."],
            ]);
        }
        $r = $subject->describe() + ["active" => (int) $active];
        if ($create) {
            $this->sql->insert("sis_subjects", $r);
        } else {
            unset($r["id"]);
            $this->sql->update("sis_subjects", $r, ["id" => $subject->id->value]);
        }
    }
    public function lockForCourse(EntityId $course): void
    {
        $row = $this->sql->rows("sis_course_subjects", ["id" => $course->value], 1)[0] ?? null;
        if ($row !== null) {
            $this->lock(new EntityId($row["subject_id"]));
        }
    }
    public function course(EntityId $course): ?SubjectSnapshot
    {
        $row = $this->sql->rows("sis_course_subjects", ["id" => $course->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        $s = $this->get(new EntityId($row["subject_id"]));
        return new SubjectSnapshot(new EntityId($s["id"]), $s["code"], $s["title"]);
    }
    public function assign(EntityId $course, ?EntityId $subject): void
    {
        $this->sql->execute(
            "DELETE FROM " .
                $this->sql->quote("sis_course_subjects") .
                " WHERE " .
                $this->sql->quote("id") .
                " = ?",
            [$course->value],
        );
        if ($subject !== null) {
            $this->sql->insert("sis_course_subjects", [
                "id" => $course->value,
                "subject_id" => $subject->value,
            ]);
        }
    }
}
