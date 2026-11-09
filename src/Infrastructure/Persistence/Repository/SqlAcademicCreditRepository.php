<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\ValueObject\CreditSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAcademicCreditRepository implements AcademicCreditRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function mapping(EntityId $credit): ?array
    {
        return $this->sql->rows("sis_academic_credit_streams", ["id" => $credit->value], 1)[0] ?? null;
    }
    public function forEnrollment(EntityId $enrollment): ?array
    {
        $r =
        $this->sql->rows("sis_academic_credit_streams", ["enrollment_id" => $enrollment->value], 1)[0] ??
        null;
        if ($r !== null) {
            return $r;
        }
        $credit =
        $this->sql->rows(
            "sis_academic_credits",
            ["source_id" => $enrollment->value, "credit_type" => "institutional"],
            1,
        )[0] ?? null;
        return $credit === null
        ? null
        : [
                "id" => $credit["id"],
                "enrollment_id" => $enrollment->value,
                "student_id" => $credit["student_id"],
                "section_id" => $this->sql->one("sis_enrollments", ["id" => $enrollment->value])[
                    "section_id"
                ],
            ];
    }
    public function projection(EntityId $credit): ?array
    {
        $r = $this->sql->rows("sis_academic_credits", ["id" => $credit->value], 1)[0] ?? null;
        if ($r === null) {
            return null;
        }
        foreach (["attempted_milli", "completed_milli", "grade_points_milli", "version"] as $key) {
            $v = $r[$key];
            if (!is_int($v) && (!is_string($v) || preg_match('/^(0|[1-9][0-9]*)$/D', $v) !== 1)) {
                throw new CorruptHistoryException();
            }
            $r[$key] = (int) $v;
        }
        $ref = $this->sql->rows("sis_credit_course_versions", ["id" => $credit->value], 1)[0] ?? null;
        return new CreditSnapshot(
            array_intersect_key($r, array_flip(CreditSnapshot::FIELDS)) + [
                "course_version_id" => $ref["course_version_id"] ?? null,
            ],
        )->facts;
    }
    public function project(array $facts, EntityId $section, ?int $expectedVersion): void
    {
        $id = new EntityId($facts["id"]);
        $mapping = $this->mapping($id);
        $expected = [
            "id" => $id->value,
            "enrollment_id" => $facts["source_id"],
            "student_id" => $facts["student_id"],
            "section_id" => $section->value,
        ];
        if ($mapping === null) {
            $this->sql->insert("sis_academic_credit_streams", $expected);
        } elseif (count($mapping) !== 4 || array_diff_assoc($mapping, $expected) !== []) {
            throw new CorruptHistoryException();
        }
        $version = $facts["course_version_id"];
        unset($facts["course_version_id"]);
        if ($facts["status"] === "D") {
            $q = $this->sql->quote(...);
            $this->sql->execute(
                "DELETE FROM " . $q("sis_credit_course_versions") . " WHERE " . $q("id") . " = ?",
                [$id->value],
            );
            if (
                $this->sql->execute(
                    "DELETE FROM " .
                        $q("sis_academic_credits") .
                        " WHERE " .
                        $q("id") .
                        " = ? AND " .
                        $q("version") .
                        " = ?",
                    [$id->value, $expectedVersion],
                ) !== 1
            ) {
                throw new ConcurrentModificationException("The academic record changed.");
            }
            return;
        }
        if ($expectedVersion === null) {
            $this->sql->insert("sis_academic_credits", $facts);
            if ($version !== null) {
                $this->sql->insert("sis_credit_course_versions", [
                    "id" => $id->value,
                    "course_version_id" => $version,
                ]);
            }
        } else {
            unset($facts["id"]);
            if (
                $this->sql->update("sis_academic_credits", $facts, [
                    "id" => $id->value,
                    "version" => $expectedVersion,
                ]) !== 1
            ) {
                throw new ConcurrentModificationException("The academic record changed.");
            }
        }
    }
    public function section(EntityId $section): array
    {
        return $this->sql->one("sis_sections", ["id" => $section->value]);
    }
    public function enrollment(EntityId $enrollment): ?array
    {
        return $this->sql->rows("sis_enrollments", ["id" => $enrollment->value], 1)[0] ?? null;
    }
    public function lockStudent(EntityId $student): void
    {
        $this->sql->lock("sis_students", $student->value, "id");
    }
    public function lockSection(EntityId $section): void
    {
        $this->sql->lock("sis_sections", $section->value, "id");
    }
}
