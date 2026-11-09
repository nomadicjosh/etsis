<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\StudentRequirementsRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlStudentRequirementsRepository implements StudentRequirementsRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function get(EntityId $assignment): ?array
    {
        $row = $this->sql->rows("sis_student_requirements", ["id" => $assignment->value], 1)[0] ?? null;
        if ($row !== null) {
            $row["position"] = (int) $row["position"];
            $row["cohort_binding_revision"] = (int) $row["cohort_binding_revision"];
        }
        return $row;
    }
    public function project(EntityId $assignment, array $selection, int $position): void
    {
        unset($selection["previous_decision_id"]);
        $row = $selection + ["position" => $position];
        if ($this->get($assignment) === null) {
            $this->sql->insert("sis_student_requirements", ["id" => $assignment->value] + $row);
        } else {
            $this->sql->update("sis_student_requirements", $row, ["id" => $assignment->value]);
        }
    }
}
