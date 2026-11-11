<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\AcademicInputRepository;
use Domain\DegreeAudit\ValueObject\AcademicInputInventory;
use Domain\Shared\ValueObject\EntityId;
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
}
