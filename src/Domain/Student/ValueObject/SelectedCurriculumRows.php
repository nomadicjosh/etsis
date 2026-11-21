<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Catalog\ValueObject\ProgramSpecializationSnapshot;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Disposable report rows from exact retained selections; never current reference labels. */
final class SelectedCurriculumRows
{
    public static function from(array $selection, array $program, int $position): array
    {
        $p = StudentSpecializationSchema::read($selection);
        if (
            $position < 1 ||
            $program["program_id"] !== $p["program_id"] ||
            $program["academic_year_id"] !== $p["academic_year_id"]
        ) {
            throw new CorruptHistoryException();
        }
        $snapshot = new ProgramSpecializationSnapshot($program["specializations"])->data;
        if (
            count($p["specialization_ids"]) < $snapshot["effective"]["minimum"] ||
            count($p["specialization_ids"]) > $snapshot["effective"]["maximum"]
        ) {
            throw new CorruptHistoryException();
        }
        if (array_key_exists("kind_limits", $snapshot)) {
            new \Domain\Catalog\ValueObject\ComponentSelectionRules(
                $snapshot["kind_limits"],
            )->selection($snapshot["offered"], $p["specialization_ids"]);
        } else {
            new \Domain\Catalog\ValueObject\ComponentMajorRules()->selection(
                $snapshot["offered"],
                $p["specialization_ids"],
            );
        }
        $offered = array_column($snapshot["offered"], null, "id");
        $rows = [];
        foreach ($p["specialization_ids"] as $id) {
            $c = $offered[$id] ?? throw new CorruptHistoryException();
            $department = $c["department"] ?? null;
            $school = $department["school"] ?? null;
            $major = $c["parent_major"] ?? null;
            $row = [
                "id" => PublishedGradeId::forVersion(
                    new EntityId($p["assignment_id"]),
                    "curriculum:" . $id,
                )->value,
                "assignment_id" => $p["assignment_id"],
                "student_id" => $p["student_id"],
                "program_id" => $p["program_id"],
                "program_version_id" => $p["program_version_id"],
                "academic_year_id" => $p["academic_year_id"],
                "selection_decision_id" => $p["decision_id"],
                "selection_position" => $position,
                "cohort_binding_revision" => $p["cohort_binding_revision"],
                "program_code" => $program["definition"]["code"],
                "program_title" => $program["definition"]["title"],
                "academic_year_code" => $program["academic_year_code"],
                "catalog_revision" => $program["catalog_revision"],
                "component_id" => $id,
                "component_code" => $c["code"],
                "component_title" => $c["title"],
                "component_kind" => $c["kind"] ?? null,
                "kind_recorded" => (int) array_key_exists("kind", $c),
                "department_recorded" => (int) array_key_exists("department", $c),
                "parent_major_recorded" => (int) array_key_exists("parent_major", $c),
            ];
            foreach (
                ["department" => $department, "school" => $school, "parent_major" => $major] as $prefix => $reference
            ) {
                foreach (["id", "code", "title"] as $field) {
                    $row[$prefix . "_" . $field] = $reference[$field] ?? null;
                }
            }
            ksort($row);
            $rows[$row["id"]] = $row;
        }
        ksort($rows);
        return array_values($rows);
    }
}
