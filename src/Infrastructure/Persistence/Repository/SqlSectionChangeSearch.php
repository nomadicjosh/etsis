<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Registration\Repository\SectionChangeSearch;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlSectionChangeSearch implements SectionChangeSearch
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function page(
        EntityId $course,
        EntityId $term,
        EntityId $from,
        string $search,
        ?EntityId $after = null,
    ): array {
        $q = $this->sql->quote(...);
        $where = [
            $q("course_id") . " = ?",
            $q("term_id") . " = ?",
            $q("id") . " <> ?",
            $q("active") . " = 1",
        ];
        $params = [$course->value, $term->value, $from->value];
        if ($after !== null) {
            $where[] = $q("id") . " < ?";
            $params[] = $after->value;
        }
        if ($search !== "") {
            $where[] = "LOWER(" . $q("code") . ") LIKE ? ESCAPE '!'";
            $params[] = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($search)) . "%";
        }
        return $this->sql->fetch(
            "SELECT " .
                implode(", ", array_map($q, ["id", "code", "capacity", "fee_minor", "currency"])) .
                " FROM " .
                $q("sis_sections") .
                " WHERE " .
                implode(" AND ", $where) .
                " ORDER BY " .
                $q("id") .
                " DESC",
            $params,
            21,
        );
    }
}
