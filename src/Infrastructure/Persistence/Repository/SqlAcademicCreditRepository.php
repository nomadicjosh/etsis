<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\AcademicRecord\Repository\AcademicCreditRepository;
use Domain\AcademicRecord\ValueObject\CreditSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAcademicCreditRepository implements AcademicCreditRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function ceuRecords(EntityId $student, ?EntityId $after = null): array
    {
        $q = $this->sql->quote(...);
        $params = [$student->value];
        if ($after !== null) {
            $params[] = $after->value;
        }
        $rows = $this->sql->fetch('SELECT r.' . $q('id') . ', r.' . $q('enrollment_id') . ', r.' . $q('section_id') .
            ', r.' . $q('earned_ceus') . ', c.' . $q('course_code') . ', c.' . $q('course_title') .
            ', v.' . $q('continuing_education') . ' FROM ' . $q('sis_academic_credit_streams') . ' r JOIN ' .
            $q('sis_academic_credits') . ' c ON c.' . $q('id') . ' = r.' . $q('id') . ' AND c.' .
            $q('student_id') . ' = r.' . $q('student_id') . ' LEFT JOIN ' . $q('sis_enrollment_section_versions') .
            ' e ON e.' . $q('id') . ' = r.' . $q('enrollment_id') . ' LEFT JOIN ' . $q('sis_section_versions') .
            ' v ON v.' . $q('id') . ' = e.' . $q('publication_id') . ' WHERE r.' . $q('student_id') . ' = ?' .
            ($after === null ? '' : ' AND r.' . $q('id') . ' < ?') . ' ORDER BY r.' . $q('id') . ' DESC', $params, 21);
        $next = count($rows) > 20 ? $rows[19]['id'] : null;
        $items = [];
        foreach (array_slice($rows, 0, 20) as $row) {
            $offered = \Domain\Catalog\ValueObject\ContinuingEducationUnits::stored($row['continuing_education']);
            if ($offered?->milli !== null || $row['earned_ceus'] !== null) {
                $items[] = array_intersect_key(
                    $row,
                    array_flip(['id', 'enrollment_id', 'section_id', 'course_code', 'course_title'])
                );
            }
        }
        return ['items' => $items, 'next_after' => $next];
    }

    public function earnedCeus(EntityId $credit): ?array
    {
        try {
            $row = $this->sql->rows('sis_academic_credit_streams', ['id' => $credit->value], 1)[0] ?? null;
            return \Domain\AcademicRecord\ValueObject\EarnedCeus::stored($row['earned_ceus'] ?? null)?->facts;
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function recordEarnedCeus(EntityId $credit, array $award): void
    {
        $facts = new \Domain\AcademicRecord\ValueObject\EarnedCeus($award)->facts;
        if (
            $this->sql->update('sis_academic_credit_streams', [
            'earned_ceus' => json_encode($facts, JSON_THROW_ON_ERROR),
            ], ['id' => $credit->value]) !== 1
        ) {
            throw new ConcurrentModificationException('The earned CEU projection changed.');
        }
    }

    public function mapping(EntityId $credit): ?array
    {
        $row = $this->sql->rows("sis_academic_credit_streams", ["id" => $credit->value], 1)[0] ?? null;
        return $row === null ? null : array_intersect_key(
            $row,
            array_flip(["id", "enrollment_id", "student_id", "section_id"])
        );
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
    public function term(EntityId $term): array
    {
        return array_intersect_key(
            $this->sql->one("sis_terms", ["id" => $term->value]),
            array_flip(["id", "code"]),
        );
    }

    public function postingTerm(EntityId $credit): ?array
    {
        $row = $this->sql->rows("sis_credit_posting_terms", ["id" => $credit->value], 1)[0] ?? null;
        if ($row === null) {
            return null;
        }
        unset($row["id"]);
        return $row;
    }

    public function recordPostingTerm(EntityId $credit, array $reference, ?array $previous): void
    {
        if ($this->postingTerm($credit) != $previous) {
            throw new CorruptHistoryException();
        }
        if ($previous === null) {
            $this->sql->insert("sis_credit_posting_terms", ["id" => $credit->value] + $reference);
        } elseif (
            $this->sql->update("sis_credit_posting_terms", $reference, [
                "id" => $credit->value,
                "decision_id" => $previous["decision_id"],
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("The academic posting term changed.");
        }
    }
    public function project(array $facts, EntityId $section, ?int $expectedVersion): void
    {
        $id = new EntityId($facts["id"]);
        $this->track($facts, $section);
        $version = $facts["course_version_id"];
        unset($facts["course_version_id"]);
        if ($facts["status"] === "D") {
            $q = $this->sql->quote(...);
            $this->sql->execute(
                "DELETE FROM " . $q("sis_credit_posting_terms") . " WHERE " . $q("id") . " = ?",
                [$id->value],
            );
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
    public function track(array $facts, EntityId $section): void
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
    }
    public function acceptCourse(
        EntityId $credit,
        PublishedCourseDefinition $definition,
        ?EntityId $previous,
        EntityId $previousCourse,
    ): void {
        $facts = $this->projection($credit) ?? throw new CorruptHistoryException();
        if (
            $facts["course_id"] !== $previousCourse->value ||
            $facts["course_version_id"] !== $previous?->value
        ) {
            throw new CorruptHistoryException();
        }
        new SqlCourseVersionProjection($this->sql)->retain($definition);
        $r = ["course_version_id" => $definition->versionId->value];
        if ($previous === null) {
            $this->sql->insert("sis_credit_course_versions", ["id" => $credit->value] + $r);
        } elseif (
            $this->sql->update("sis_credit_course_versions", $r, [
                "id" => $credit->value,
                "course_version_id" => $previous->value,
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("Review the accepted course publication again.");
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
