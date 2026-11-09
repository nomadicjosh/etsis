<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use DomainException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferRepository;
use Domain\Transfer\TransferEquivalency;
use Infrastructure\Persistence\SqlRecords;
use Domain\Shared\Exception\ValidationException;
use PDOException;

final readonly class SqlTransferRepository implements TransferRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function addInstitution(EntityId $id, string $code, string $name): void
    {
        $this->insertLabel("sis_external_institutions", ["id" => $id->value, "code" => $code, "name" => $name]);
    }

    public function addExternalCourse(EntityId $id, EntityId $institution, string $code, string $title): void
    {
        $this->insertLabel("sis_external_courses", [
            "id" => $id->value,
            "institution_id" => $institution->value,
            "code" => $code,
            "title" => $title,
        ]);
    }

    public function addEquivalency(TransferEquivalency $equivalency): void
    {
        $course = $this->sql->one("sis_external_courses", ["id" => $equivalency->externalCourseId->value]);
        if ($course["institution_id"] !== $equivalency->externalInstitutionId->value) {
            throw new DomainException("The external institution does not match the course.");
        }
        $grade = $this->sql->one("sis_grades", ["code" => $equivalency->awardedGrade]);
        if (!(bool) $grade["passes"]) {
            throw new DomainException("Transfer credit requires a passing awarded grade.");
        }
        $this->sql->insert("sis_transfer_equivalencies", [
            "id" => $equivalency->id->value,
            "external_course_id" => $equivalency->externalCourseId->value,
            "course_id" => $equivalency->institutionalCourseId->value,
            "starts_on" => $equivalency->effectiveDates->start->format("Y-m-d"),
            "ends_on" => $equivalency->effectiveDates->end?->format("Y-m-d"),
            "minimum_grade_rank" => $equivalency->minimumGradeRank,
            "credits_milli" => $equivalency->creditThousandths,
            "grade_id" => $grade["id"],
        ]);
    }

    public function award(
        EntityId $id,
        EntityId $student,
        TransferEquivalency $equivalency,
        EntityId $term,
        DateTimeImmutable $completedOn,
    ): EntityId {
        $criteria = [
            "student_id" => $student->value,
            "equivalency_id" => $equivalency->id->value,
            "completed_on" => $completedOn->format("Y-m-d"),
        ];
        $existing = $this->sql->rows("sis_transfer_awards", $criteria);
        if ($existing !== []) {
            if ($existing[0]["term_id"] !== $term->value) {
                throw new DomainException("This transfer award already belongs to another term.");
            }
            $credit = $this->sql->one("sis_academic_credits", ["id" => $existing[0]["credit_id"]]);
            if ($credit["status"] === "V") {
                throw new DomainException("This award is voided. Use the reviewed reinstatement workflow.");
            }
            return new EntityId($existing[0]["credit_id"]);
        }
        $grade = $this->sql->one("sis_grades", ["code" => $equivalency->awardedGrade]);
        $course = $this->sql->one("sis_courses", ["id" => $equivalency->institutionalCourseId->value]);
        $creditId = EntityId::generate();
        $this->sql->insert("sis_academic_credits", [
            "id" => $creditId->value,
            "student_id" => $student->value,
            "course_id" => $equivalency->institutionalCourseId->value,
            "term_id" => $term->value,
            "source_id" => $id->value,
            "credit_type" => "transfer",
            "course_code" => $course["code"],
            "course_title" =>
                $this->sql->rows(
                    "sis_course_descriptions",
                    ["course_id" => $equivalency->institutionalCourseId->value],
                    1,
                )[0]["published_transcript_title"] ?? $course["title"],
            "attempted_milli" => $equivalency->creditThousandths,
            "completed_milli" => $equivalency->creditThousandths,
            "grade_points_milli" => (bool) $grade["counts_gpa"]
                ? intdiv($equivalency->creditThousandths * (int) $grade["points_milli"], 1000)
                : 0,
            "grade_id" => $grade["id"],
            "status" => "A",
            "version" => 0,
        ]);
        $this->sql->insert(
            "sis_transfer_awards",
            ["id" => $id->value] + $criteria + ["term_id" => $term->value, "credit_id" => $creditId->value],
        );

        return $creditId;
    }

    public function transcript(EntityId $student): array
    {
        $q = $this->sql->quote(...);
        $credits = $this->sql->fetch(
            "SELECT c.*, i." .
                $q("institutional_id") .
                ", g." .
                $q("code") .
                " AS " .
                $q("grade_code") .
                ", g." .
                $q("counts_gpa") .
                " FROM " .
                $q("sis_academic_credits") .
                " c JOIN " .
                $q("sis_students") .
                " s ON s." .
                $q("id") .
                " = c." .
                $q("student_id") .
                " JOIN " .
                $q("sis_person_identifiers") .
                " i ON i." .
                $q("person_id") .
                " = s." .
                $q("person_id") .
                " LEFT JOIN " .
                $q("sis_grades") .
                " g ON g." .
                $q("id") .
                " = c." .
                $q("grade_id") .
                " WHERE c." .
                $q("student_id") .
                " = ? ORDER BY c." .
                $q("term_id") .
                ", c." .
                $q("id"),
            [$student->value],
        );
        foreach ($credits as &$credit) {
            $credit["grade_code"] =
            $credit["status"] === "V"
            ? "Voided"
            : $credit["grade_code"] ?? ($credit["status"] === "W" ? "W" : null);
            $credit["counts_gpa"] = (bool) ($credit["counts_gpa"] ?? false);
        }
        unset($credit);

        return $credits;
    }

    /** @param array<string, scalar|null> $values */
    private function insertLabel(string $table, array $values): void
    {
        try {
            $this->sql->insert($table, $values);
        } catch (PDOException $error) {
            if (in_array((string) $error->getCode(), ["23000", "23505"], true)) {
                throw new ValidationException([["field" => "code", "message" => "This code is already assigned."]]);
            }
            throw $error;
        }
    }
}
