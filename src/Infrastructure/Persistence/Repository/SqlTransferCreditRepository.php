<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Catalog\ValueObject\PublishedCourseDefinition;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\Repository\TransferCreditRepository;
use Domain\Transfer\ValueObject\TransferCreditFacts;
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
        return $this->sql->one("sis_transfer_awards", ["id" => $award->value]);
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
    public function courseVersion(EntityId $credit): ?EntityId
    {
        $row = $this->sql->rows("sis_credit_course_versions", ["id" => $credit->value], 1)[0] ?? null;
        return $row === null ? null : new EntityId($row["course_version_id"]);
    }
    public function term(EntityId $term): array
    {
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
        $this->sql->insert("sis_transfer_awards", [
            "id" => $source["award_id"],
            "student_id" => $source["student_id"],
            "equivalency_id" => $source["equivalency_id"],
            "completed_on" => $source["completed_on"],
            "term_id" => $source["term_id"],
            "credit_id" => $credit["id"],
        ]);
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
    }
}
