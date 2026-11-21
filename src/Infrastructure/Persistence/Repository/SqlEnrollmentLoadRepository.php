<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\EnrollmentLoadRepository;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlEnrollmentLoadRepository implements EnrollmentLoadRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lockTerm(EntityId $term): void
    {
        $this->sql->lock('sis_terms', $term->value, 'id');
    }
    public function terms(string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $like = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($search)) . "%";
        $where = "LOWER(" . $q("code") . ") LIKE ? ESCAPE '!'";
        $params = [$like];
        if ($after !== null) {
            $anchor = $this->term($after);
            $where .=
            " AND (" .
            $q("starts_on") .
            " < ? OR (" .
            $q("starts_on") .
            " = ? AND " .
            $q("id") .
            " < ?))";
            array_push($params, $anchor["starts_on"], $anchor["starts_on"], $after->value);
        }
        $rows = $this->sql->fetch(
            "SELECT " .
                implode(", ", array_map($q, ["id", "code", "starts_on", "ends_on"])) .
                " FROM " .
                $q("sis_terms") .
                " WHERE " .
                $where .
                " ORDER BY " .
                $q("starts_on") .
                " DESC, " .
                $q("id") .
                " DESC",
            $params,
            21,
        );
        return [
            "items" => array_slice($rows, 0, 20),
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }

    public function term(EntityId $term): array
    {
        return array_intersect_key(
            $this->sql->one("sis_terms", ["id" => $term->value]),
            array_flip(["id", "code", "starts_on", "ends_on"]),
        );
    }

    public function inventory(EntityId $student, EntityId $term): array
    {
        $q = $this->sql->quote(...);
        $select = static fn(string $table): string => "SELECT " .
            $q("id") .
            " FROM " .
            $q($table) .
            " WHERE " .
            $q("student_id") .
            " = ? AND " .
            $q("term_id") .
            " = ?";
        return $this->sql->fetch(
            "SELECT " .
                $q("id") .
                " FROM (" .
                $select("sis_enrollments") .
                " UNION " .
                $select("sis_registration_streams") .
                ") load_inventory ORDER BY " .
                $q("id"),
            [$student->value, $term->value, $student->value, $term->value],
            201,
        );
    }
}
