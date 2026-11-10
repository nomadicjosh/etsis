<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\AcademicWorkRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAcademicWorkRepository implements AcademicWorkRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function page(EntityId $student, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $parameters = [$student->value];
        $boundary = "";
        if ($after !== null) {
            $anchor =
            $this->sql->rows(
                "sis_academic_credits",
                ["id" => $after->value, "student_id" => $student->value],
                1,
            )[0] ??
            throw new \Domain\Shared\Exception\RecordNotFoundException(
                "This academic-work page changed. Start again from the first page.",
            );
            $boundary =
            " AND (c." .
            $q("term_id") .
            " < ? OR (c." .
            $q("term_id") .
            " = ? AND c." .
            $q("id") .
            " < ?))";
            array_push($parameters, $anchor["term_id"], $anchor["term_id"], $after->value);
        }
        $fields = array_map(static fn(string $field): string => "c." . $q($field), [
            "id",
            "credit_type",
            "source_id",
        ]);
        $rows = $this->sql->fetch(
            "SELECT " .
                implode(", ", $fields) .
                ", t." .
                $q("code") .
                " AS " .
                $q("term_code") .
                " FROM " .
                $q("sis_academic_credits") .
                " c LEFT JOIN " .
                $q("sis_terms") .
                " t ON t." .
                $q("id") .
                " = c." .
                $q("term_id") .
                " WHERE c." .
                $q("student_id") .
                " = ?" .
                $boundary .
                " ORDER BY c." .
                $q("term_id") .
                " DESC, c." .
                $q("id") .
                " DESC",
            $parameters,
            21,
        );
        return array_map(static function (array $row): array {
            if (!is_string($row["id"]) || !is_string($row["credit_type"]) || !is_string($row["source_id"])) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            if ($row["term_code"] !== null && !is_string($row["term_code"])) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            return [
                "id" => $row["id"],
                "credit_type" => $row["credit_type"],
                "source_id" => $row["source_id"],
                "term_code" => $row["term_code"],
            ];
        }, $rows);
    }
}
