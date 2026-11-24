<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DomainException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferRepository;
use Domain\Transfer\TransferEquivalency;
use Infrastructure\Persistence\SqlRecords;
use Domain\Shared\Exception\ValidationException;
use PDOException;

final readonly class SqlTransferRepository implements TransferRepository
{
    public function __construct(
        private SqlRecords $sql,
        private \Domain\Gradebook\Service\AcademicGradeCatalog $grades,
    ) {
    }

    public function addInstitution(EntityId $id, string $code, string $name): void
    {
        $this->insertLabel("sis_external_institutions", [
            "id" => $id->value,
            "code" => $code,
            "name" => $name,
        ]);
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
        $grade = $this->grades->byCode($equivalency->awardedGrade);
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

    public function transcript(EntityId $student): array
    {
        $q = $this->sql->quote(...);
        $credits = $this->sql->fetch(
            "SELECT c.*, offered." . $q('continuing_education') . ' AS ' . $q('accepted_ceu_declaration') .
                ', ceu.' . $q('earned_ceus') . ', e.' .
                $q("section_id") .
                ", i." .
                $q("institutional_id") .
                ", g." .
                $q("code") .
                " AS " .
                $q("grade_code") .
                ", g." .
                $q("counts_gpa") .
                ", COALESCE(pt." .
                $q("term_code") .
                ", atp." .
                $q("code") .
                ") AS " .
                $q("term_code") .
                ", CASE WHEN pt." .
                $q("id") .
                " IS NULL THEN 0 ELSE 1 END AS " .
                $q("term_label_retained") .
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
                " LEFT JOIN " .
                $q("sis_enrollments") .
                " e ON e." .
                $q("id") .
                " = c." .
                $q("source_id") .
                " AND c." .
                $q("credit_type") .
                " = 'institutional' AND e." .
                $q("student_id") .
                " = c." .
                $q("student_id") .
                " LEFT JOIN " .
                $q("sis_credit_posting_terms") .
                " pt ON pt." .
                $q("id") .
                " = c." .
                $q("id") .
                " LEFT JOIN " .
                $q("sis_terms") .
                " atp ON atp." .
                $q("id") .
                " = c." .
                $q("term_id") .
                ' LEFT JOIN ' . $q('sis_enrollment_section_versions') . ' ev ON ev.' . $q('id') .
                    ' = e.' . $q('id') . ' LEFT JOIN ' . $q('sis_section_versions') . ' offered ON offered.' .
                    $q('id') . ' = ev.' . $q('publication_id') .
                ' LEFT JOIN ' . $q('sis_academic_credit_streams') . ' ceu ON ceu.' . $q('id') .
                    ' = c.' . $q('id') . ' AND c.' . $q('credit_type') . " = 'institutional'" .
                " WHERE c." .
                $q("student_id") .
                " = ? ORDER BY c." .
                $q("term_id") .
                ", c." .
                $q("id"),
            [$student->value],
        );
        foreach ($credits as &$credit) {
            $declared = \Domain\Catalog\ValueObject\ContinuingEducationUnits::stored(
                $credit['accepted_ceu_declaration']
            );
            $credit['continuing_education_only'] = $credit['credit_type'] === 'institutional' &&
            (int) $credit['attempted_milli'] === 0 && (int) $credit['completed_milli'] === 0 &&
            $declared?->milli !== null;
            unset($credit['accepted_ceu_declaration']);
            $award = \Domain\AcademicRecord\ValueObject\EarnedCeus::stored($credit['earned_ceus']);
            $credit['earned_ceus'] = $award === null ? null : array_intersect_key(
                $award->facts,
                array_flip(['earned_milli', 'completed_on'])
            );
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
                throw new ValidationException([
                    ["field" => "code", "message" => "This code is already assigned."],
                ]);
            }
            throw $error;
        }
    }
}
