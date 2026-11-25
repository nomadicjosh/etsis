<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\ValueObject\AcademicInputInventory;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAcademicInputRepository implements AcademicInputRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function inventory(EntityId $student): AcademicInputInventory
    {
        $definitions = [
            "credits" => [
                "sis_academic_credits",
                ["id", "credit_type", "source_id", "term_id"],
                ["term_id", "id"],
            ],
            "academic" => ["sis_academic_credit_streams", ["id", "enrollment_id", "section_id"], ["id"]],
            "registration" => ["sis_registration_streams", ["id", "section_id", "term_id"], ["id"]],
            "enrollments" => ["sis_enrollments", ["id", "section_id", "term_id"], ["term_id", "status"]],
            "transfer" => ["sis_transfer_credit_streams", ["id", "credit_id"], ["id"]],
            "awards" => [
                "sis_transfer_awards",
                ["id", "credit_id", "term_id"],
                ["equivalency_id", "completed_on"],
            ],
        ];
        $rows = [];
        $q = $this->sql->quote(...);
        foreach ($definitions as $kind => [$table, $fields, $order]) {
            $rows[$kind] = $this->sql->fetch(
                "SELECT " .
                    implode(", ", array_map($q, $fields)) .
                    " FROM " .
                    $q($table) .
                    " WHERE " .
                    $q("student_id") .
                    " = ? ORDER BY " .
                    implode(", ", array_map($q, $order)),
                [$student->value],
                AcademicInputInventory::LIMIT + 1,
            );
        }
        return new AcademicInputInventory($rows);
    }

    public function selected(EntityId $credit): ?array
    {
        $rows = [
            "credits" => $this->sql->rows(
                "sis_academic_credits",
                ["id" => $credit->value],
                2,
            ),
            "academic" => $this->sql->rows(
                "sis_academic_credit_streams",
                ["id" => $credit->value],
                2,
            ),
            "transfer" => $this->sql->rows(
                "sis_transfer_credit_streams",
                ["credit_id" => $credit->value],
                2,
            ),
            "registration" => [],
            "enrollments" => [],
            "awards" => [],
        ];
        if (
            $rows["credits"] === [] &&
            $rows["academic"] === [] &&
            $rows["transfer"] === []
        ) {
            return null;
        }
        $enrollments = $awards = [];
        foreach ($rows["credits"] as $row) {
            if ($row["credit_type"] === "institutional") {
                $enrollments[$row["source_id"]] = true;
            } elseif ($row["credit_type"] === "transfer") {
                $awards[$row["source_id"]] = true;
            } else {
                throw new CorruptHistoryException();
            }
        }
        foreach ($rows["academic"] as $row) {
            $enrollments[$row["enrollment_id"]] = true;
        }
        foreach ($rows["transfer"] as $row) {
            $awards[$row["id"]] = true;
        }
        $q = $this->sql->quote(...);
        $sourceTables = [
            "registration" => "sis_registration_streams",
            "enrollments" => "sis_enrollments",
            "awards" => "sis_transfer_awards",
        ];
        foreach ($sourceTables as $kind => $table) {
            $ids = array_keys($kind === "awards" ? $awards : $enrollments);
            sort($ids, SORT_STRING);
            if ($ids !== []) {
                $rows[$kind] = $this->sql->fetch(
                    "SELECT * FROM " .
                        $q($table) .
                        " WHERE " .
                        $q("id") .
                        " IN (" .
                        implode(", ", array_fill(0, count($ids), "?")) .
                        ") ORDER BY " .
                        $q("id"),
                    $ids,
                    3,
                );
            }
        }
        $students = [];
        foreach ($rows as $kind => $items) {
            if (
                count($items) >
                ($kind === "registration" ||
                $kind === "enrollments" ||
                $kind === "awards"
                    ? 2
                    : 1)
            ) {
                throw new CorruptHistoryException();
            }
            foreach ($items as $row) {
                $students[$row["student_id"]] = true;
            }
        }
        $inventory = new AcademicInputInventory($rows);
        if (
            count($students) !== 1 ||
            count($inventory->sources) !== 1 ||
            $inventory->sources[0]["credit_id"] !== $credit->value
        ) {
            throw new CorruptHistoryException();
        }
        $student = array_key_first($students);
        new EntityId($student);
        return [
            "student_id" => $student,
            "source" => $inventory->sources[0],
            "boundary" => $rows,
        ];
    }
}
