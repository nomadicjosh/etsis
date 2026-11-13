<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Rules\Repository\CourseRuleRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;
use PDOException;

final readonly class SqlCourseRuleRepository implements CourseRuleRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function append(array $record): void
    {
        try {
            $this->sql->insert("sis_course_rules", $record);
        } catch (PDOException $e) {
            if (in_array((string) $e->getCode(), ["23000", "23505", "40001", "40P01"], true)) {
                throw new ConcurrentModificationException(
                    "The rule code or revision changed. Reload before saving.",
                );
            }
            throw $e;
        }
    }
    public function revision(EntityId $version): array
    {
        return $this->sql->one("sis_course_rules", ["id" => $version->value]);
    }
    public function latest(EntityId $rule): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                implode(", ", array_map($q, ["id", "rule_id", "code", "title", "revision"])) .
                " FROM " .
                $q("sis_course_rules") .
                " WHERE " .
                $q("rule_id") .
                " = ? ORDER BY " .
                $q("revision") .
                " DESC",
            [$rule->value],
            1,
        )[0] ?? throw new RecordNotFoundException("Course rule not found.");
    }
    public function roots(string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $where = [$q("revision") . " = 1"];
        $parameters = [];
        if ($after !== null) {
            $where[] = $q("id") . " > ?";
            $parameters[] = $after->value;
        }
        if ($search !== "") {
            $where[] = $q("code") . " LIKE ? ESCAPE '!'";
            $parameters[] = "%" . strtr($search, ["!" => "!!", "%" => "!%", "_" => "!_"]) . "%";
        }
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                " FROM " .
                $q("sis_course_rules") .
                " WHERE " .
                implode(" AND ", $where) .
                " ORDER BY " .
                $q("id"),
            $parameters,
            21,
        );
    }
    public function reference(string $field, EntityId $id): array
    {
        $table = match ($field) {
            "course_id" => "sis_courses",
            "subject_id" => "sis_subjects",
            "department_id", "academic_level_id", "course_level_id" => "sis_catalog_classifications",
            default => throw new InvalidArgumentException("Unsupported course rule reference."),
        };
        $row = $this->sql->one($table, ["id" => $id->value]);
        if ($table === "sis_catalog_classifications" && $row["kind"] !== substr($field, 0, -3)) {
            throw new InvalidArgumentException("Select the matching classification category.");
        }
        return ["id" => $row["id"], "code" => $row["code"], "title" => $row["title"]];
    }
    public function versions(EntityId $rule, ?int $before = null): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT " .
                implode(", ", array_map($q, ["id", "rule_id", "code", "title", "revision"])) .
                " FROM " .
                $q("sis_course_rules") .
                " WHERE " .
                $q("rule_id") .
                " = ?" .
                ($before === null ? "" : " AND " . $q("revision") . " < ?") .
                " ORDER BY " .
                $q("revision") .
                " DESC",
            $before === null ? [$rule->value] : [$rule->value, $before],
            21,
        );
    }
}
