<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\ReplacementCourseRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlReplacementCourseRepository implements ReplacementCourseRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function page(string $search, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $params = [];
        if ($after !== null) {
            $where[] = $q("id") . " < ?";
            $params[] = $after->value;
        }
        if ($search !== "") {
            $where[] =
            "(LOWER(" .
            $q("code") .
            ") LIKE ? ESCAPE '!' OR LOWER(" .
            $q("title") .
            ") LIKE ? ESCAPE '!')";
            $pattern = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($search)) . "%";
            array_push($params, $pattern, $pattern);
        }
        return $this->sql->fetch(
            "SELECT " .
                implode(", ", array_map($q, ["id", "code", "title"])) .
                " FROM " .
                $q("sis_courses") .
                ($where === [] ? "" : " WHERE " . implode(" AND ", $where)) .
                " ORDER BY " .
                $q("id") .
                " DESC",
            $params,
            21,
        );
    }
}
