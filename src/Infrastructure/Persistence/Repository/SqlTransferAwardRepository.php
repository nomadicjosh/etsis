<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferAwardRepository;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;

final readonly class SqlTransferAwardRepository implements TransferAwardRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function get(EntityId $student, EntityId $award): array
    {
        $q = $this->sql->quote(...);
        $r = $this->sql->one("sis_transfer_awards", ["id" => $award->value, "student_id" => $student->value]);
        $credit = $this->sql->one("sis_academic_credits", [
            "id" => $r["credit_id"],
            "student_id" => $student->value,
            "source_id" => $award->value,
            "credit_type" => "transfer",
        ]);
        $context = new SqlTransferCatalogRepository($this->sql)->get(
            "equivalencies",
            new EntityId($r["equivalency_id"]),
        );
        if ($credit["term_id"] !== $r["term_id"] || $credit["course_id"] !== $context["course_id"]) {
            throw new RecordNotFoundException("Transfer award context does not match its credit.");
        }
        $identity =
        $this->sql->fetch(
            "SELECT p." .
                    $q("given_name") .
                    ", p." .
                    $q("family_name") .
                    ", i." .
                    $q("institutional_id") .
                    ", s." .
                    $q("person_id") .
                    " FROM " .
                    $q("sis_students") .
                    " s JOIN " .
                    $q("sis_persons") .
                    " p ON p." .
                    $q("id") .
                    " = s." .
                    $q("person_id") .
                    " JOIN " .
                    $q("sis_person_identifiers") .
                    " i ON i." .
                    $q("person_id") .
                    " = p." .
                    $q("id") .
                    " WHERE s." .
                    $q("id") .
                    " = ?",
            [$student->value],
            1,
        )[0] ?? throw new RecordNotFoundException("Transfer student not found.");
        $grade = $credit["grade_id"] === null ? null : $this->sql->one("sis_grades", ["id" => $credit["grade_id"]]);
        $term = $this->sql->one("sis_terms", ["id" => $r["term_id"]]);
        foreach (["attempted_milli", "completed_milli", "grade_points_milli", "version"] as $key) {
            $credit[$key] = (int) $credit[$key];
        }
        return [
            "id" => $r["id"],
            "student_id" => $r["student_id"],
            "equivalency_id" => $r["equivalency_id"],
            "completed_on" => $r["completed_on"],
            "term_id" => $r["term_id"],
            "term_code" => $term["code"],
            "institution_id" => $context["institution_id"],
            "external_course_id" => $context["external_course_id"],
            "course_id" => $context["course_id"],
            "institution_code" => $context["institution_code"],
            "institution_name" => $context["institution_name"],
            "external_code" => $context["external_code"],
            "external_title" => $context["external_title"],
            "credit" => $credit,
            "grade_code" => $grade["code"] ?? null,
            "counts_gpa" => (bool) ($grade["counts_gpa"] ?? false),
        ] + $identity;
    }

    public function lock(EntityId $award, EntityId $credit): void
    {
        $this->sql->lock("sis_transfer_awards", $award->value, "id");
        $this->sql->lock("sis_academic_credits", $credit->value, "id");
    }

    public function lockGrade(EntityId $grade): void
    {
        $this->sql->lock("sis_grades", $grade->value, "id");
    }

    public function grade(string $code): array
    {
        $r = $this->sql->rows("sis_grades", ["code" => $code], 1)[0] ?? null;
        if ($r === null || !(bool) $r["passes"]) {
            throw new ValidationException([
                ["field" => "awarded_grade", "message" => "Choose an existing passing grade."],
            ]);
        }
        foreach (["points_milli", "passes", "counts_gpa"] as $key) {
            $r[$key] = (int) $r[$key];
        }
        return $r;
    }

    public function apply(EntityId $credit, int $version, array $values): void
    {
        if (
            array_diff(array_keys($values), [
                "attempted_milli",
                "completed_milli",
                "grade_points_milli",
                "grade_id",
                "status",
            ]) !== []
        ) {
            throw new InvalidArgumentException("Unsupported transfer correction fields.");
        }
        if (
            $this->sql->update("sis_academic_credits", $values + ["version" => $version + 1], [
                "id" => $credit->value,
                "version" => $version,
                "credit_type" => "transfer",
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("The transfer award changed. Review it again.");
        }
    }

    public function action(EntityId $operation): ?array
    {
        return $this->sql->rows("sis_transfer_award_changes", ["id" => $operation->value], 1)[0] ?? null;
    }

    public function record(
        EntityId $operation,
        EntityId $award,
        EntityId $credit,
        EntityId $actor,
        int $version,
        string $kind,
        string $encryptedContent,
        string $createdAt,
    ): void {
        $this->sql->insert("sis_transfer_award_changes", [
            "id" => $operation->value,
            "award_id" => $award->value,
            "credit_id" => $credit->value,
            "actor_id" => $actor->value,
            "version" => $version,
            "kind" => $kind,
            "encrypted_content" => $encryptedContent,
            "created_at" => $createdAt,
        ]);
    }

    public function history(EntityId $award, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $cursor =
        $after === null
        ? null
        : $this->sql->one("sis_transfer_award_changes", ["id" => $after->value, "award_id" => $award->value]);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_transfer_award_changes") .
                " WHERE " .
                $q("award_id") .
                " = ?" .
                ($cursor === null ? "" : " AND " . $q("version") . " < ?") .
                " ORDER BY " .
                $q("version") .
                " DESC",
            $cursor === null ? [$award->value] : [$award->value, (int) $cursor["version"]],
            51,
        );
    }
}
