<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferCreditRepository;
use Domain\Transfer\ValueObject\TransferCreditFacts;
use Domain\Transfer\ValueObject\InternalTransferSource;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlTransferCreditRepository implements TransferCreditRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function existing(EntityId $student, EntityId $equivalency, string $completed): ?array
    {
        return $this->sql->rows(
            "sis_transfer_awards",
            [
                "student_id" => $student->value,
                "equivalency_id" => $equivalency->value,
                "completed_on" => $completed,
            ],
            1,
        )[0] ?? null;
    }
    public function award(EntityId $award): array
    {
        $rows = $this->sql->rows('sis_transfer_sources', ['id' => $award->value], 2);
        if (count($rows) !== 1) {
            throw new CorruptHistoryException();
        }
        return $rows[0];
    }
    public function corrected(EntityId $student, EntityId $equivalency, string $completed): ?array
    {
        return $this->sql->rows(
            "sis_transfer_awards",
            [
                "student_id" => $student->value,
                "equivalency_id" => $equivalency->value,
                "corrected_completed_on" => $completed,
            ],
            1,
        )[0] ?? null;
    }
    public function mapping(EntityId $award): ?array
    {
        return $this->sql->rows("sis_transfer_credit_streams", ["id" => $award->value], 1)[0] ?? null;
    }
    public function credit(EntityId $credit): array
    {
        $r = $this->sql->one("sis_academic_credits", ["id" => $credit->value, "credit_type" => "transfer"]);
        foreach (["attempted_milli", "completed_milli", "grade_points_milli", "version"] as $key) {
            $v = $r[$key];
            if (!is_int($v) && (!is_string($v) || preg_match('/^(0|[1-9][0-9]*)$/D', $v) !== 1)) {
                throw new CorruptHistoryException();
            }
            $r[$key] = (int) $v;
        }
        return new TransferCreditFacts(
            array_intersect_key($r, array_flip(TransferCreditFacts::FIELDS)),
        )->facts;
    }
    public function internal(EntityId $award): ?array
    {
        return $this->sql->rows(
            "sis_internal_transfer_awards",
            ["id" => $award->value],
            1,
        )[0] ?? null;
    }
    public function internalExisting(
        EntityId $student,
        EntityId $assignment,
        EntityId $source,
    ): ?array {
        return $this->sql->rows(
            "sis_internal_transfer_awards",
            [
                "student_id" => $student->value,
                "receiving_assignment_id" => $assignment->value,
                "source_credit_id" => $source->value,
            ],
            1,
        )[0] ?? null;
    }
    public function internalPage(
        EntityId $student,
        EntityId $assignment,
        ?EntityId $after = null,
    ): array {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_internal_transfer_awards") .
                " WHERE " .
                $q("student_id") .
                " = ? AND " .
                $q("receiving_assignment_id") .
                " = ?" .
                ($after === null ? "" : " AND " . $q("id") . " < ?") .
                " ORDER BY " .
                $q("id") .
                " DESC",
            [
                $student->value,
                $assignment->value,
                ...$after === null ? [] : [$after->value],
            ],
            11,
        );
    }
    public function courseVersion(EntityId $credit): ?EntityId
    {
        $row = $this->sql->rows("sis_credit_course_versions", ["id" => $credit->value], 1)[0] ?? null;
        return $row === null ? null : new EntityId($row["course_version_id"]);
    }
    public function acceptCourse(
        EntityId $credit,
        PublishedCourseDefinition $definition,
        ?EntityId $previous,
        EntityId $previousCourse,
    ): void {
        $facts = $this->credit($credit);
        if (
            $facts["course_id"] !== $previousCourse->value ||
            $this->courseVersion($credit)?->value !== $previous?->value
        ) {
            throw new CorruptHistoryException();
        }
        new SqlCourseVersionProjection($this->sql)->retain($definition);
        $r = ["course_version_id" => $definition->versionId->value];
        if ($previous === null) {
            $this->sql->insert("sis_credit_course_versions", ["id" => $credit->value] + $r);
        } elseif (
            $this->sql->update("sis_credit_course_versions", $r, ["id" => $credit->value,
            "course_version_id" => $previous->value]) !== 1
        ) {
            throw new ConcurrentModificationException("Review the accepted course publication again.");
        }
    }
    public function term(EntityId $term, bool $lock = false): array
    {
        if ($lock) {
            $this->sql->lock("sis_terms", $term->value, "id");
        }
        return $this->sql->one("sis_terms", ["id" => $term->value]);
    }
    public function create(
        array $source,
        array $credit,
        ?PublishedCourseDefinition $definition = null,
    ): void {
        if ($definition !== null && $credit["course_id"] !== $definition->courseId->value) {
            throw new CorruptHistoryException();
        }
        if ($definition !== null) {
            new SqlCourseVersionProjection($this->sql)->retain($definition);
        }
        $this->sql->insert("sis_academic_credits", $credit);
        if ($definition !== null) {
            $this->sql->insert("sis_credit_course_versions", [
                "id" => $credit["id"],
                "course_version_id" => $definition->versionId->value,
            ]);
        }
        if (isset($source['internal'])) {
            $i = $source['internal'];
            $this->sql->insert('sis_internal_transfer_awards', ['id' => $source['award_id'],
                'student_id' => $source['student_id'], 'credit_id' => $credit['id'], 'term_id' => $source['term_id'],
                'completed_on' => $source['completed_on'], 'source_credit_id' => $i['source_credit_id'],
                'source_pursuit_id' => $i['source_pursuit_id'], 'source_credential_id' => $i['source_credential_id'],
                'receiving_assignment_id' => $i['receiving_assignment_id'],
                'lineage_hash' => InternalTransferSource::hash($i)]);
        } else {
            $this->sql->insert("sis_transfer_awards", [
            "id" => $source["award_id"],
            "student_id" => $source["student_id"],
            "equivalency_id" => $source["equivalency_id"],
            "completed_on" => $source["completed_on"],
            "term_id" => $source["term_id"],
            "credit_id" => $credit["id"],
            ]);
        }
        $this->track($source, $credit);
    }
    public function project(array $credit, int $expectedVersion): void
    {
        new TransferCreditFacts($credit);
        $id = $credit["id"];
        unset($credit["id"]);
        if (
            $this->sql->update("sis_academic_credits", $credit, [
                "id" => $id,
                "version" => $expectedVersion,
                "credit_type" => "transfer",
            ]) !== 1
        ) {
            throw new ConcurrentModificationException("The transfer credit changed. Review it again.");
        }
    }
    public function track(array $source, array $credit): void
    {
        $m = [
            "id" => $source["award_id"],
            "student_id" => $source["student_id"],
            "credit_id" => $credit["id"],
        ];
        $old = $this->mapping(new EntityId($source["award_id"]));
        if ($old === null) {
            $this->sql->insert("sis_transfer_credit_streams", $m);
        } elseif (count($old) !== 3 || array_diff_assoc($old, $m) !== []) {
            throw new CorruptHistoryException();
        }
        $award = $this->award(new EntityId($source["award_id"]));
        if ($source["term_id"] !== $credit["term_id"]) {
            throw new CorruptHistoryException();
        }
        if ($award["term_id"] !== $source["term_id"]) {
            if (
                $this->sql->update(
                    isset($source["internal"]) ? "sis_internal_transfer_awards" : "sis_transfer_awards",
                    ["term_id" => $source["term_id"]],
                    ["id" => $source["award_id"], "term_id" => $award["term_id"]]
                ) !== 1
            ) {
                throw new ConcurrentModificationException("Review the transfer placement again.");
            }
        }
        if (($award["corrected_completed_on"] ?? $award["completed_on"]) !== $source["completed_on"]) {
            $this->sql->update(
                "sis_transfer_awards",
                ["corrected_completed_on" => $source["completed_on"]],
                ["id" => $source["award_id"]]
            );
        }
    }
}
