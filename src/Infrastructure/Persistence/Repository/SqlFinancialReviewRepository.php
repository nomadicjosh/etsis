<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Finance\FinancialReview;
use Domain\Finance\Repository\FinancialReviewRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlFinancialReviewRepository implements FinancialReviewRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock("sis_financial_reviews", $id->value, "id");
    }

    public function get(EntityId $id): FinancialReview
    {
        return $this->hydrate($this->sql->one("sis_financial_reviews", ["id" => $id->value]));
    }

    public function forHiatus(EntityId $hiatus): ?FinancialReview
    {
        $row = $this->sql->rows("sis_financial_reviews", ["hiatus_id" => $hiatus->value], 1)[0] ?? null;
        return $row === null ? null : $this->hydrate($row);
    }

    public function add(FinancialReview $review): void
    {
        $this->sql->insert("sis_financial_reviews", [
            "id" => $review->id->value,
            "student_id" => $review->student->value,
            "hiatus_id" => $review->hiatus->value,
            "requested_by" => $review->requestedBy->value,
            "created_at" => $review->createdAt,
            "status" => $review->status,
            "assigned_to" => $review->assignedTo?->value,
            "version" => $review->version,
        ]);
    }

    public function save(FinancialReview $review, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                "sis_financial_reviews",
                [
                    "status" => $review->status,
                    "assigned_to" => $review->assignedTo?->value,
                    "version" => $review->version,
                ],
                ["id" => $review->id->value, "version" => $expectedVersion],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("Reload the finance review before changing it.");
        }
    }

    public function page(?string $status, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $where = [];
        $values = [];
        if ($status !== null) {
            $where[] = "r." . $q("status") . " = ?";
            $values[] = $status;
        }
        if ($after !== null) {
            $where[] = "r." . $q("id") . " < ?";
            $values[] = $after->value;
        }
        return $this->sql->fetch(
            "SELECT r.*, p." .
                $q("given_name") .
                ", p." .
                $q("family_name") .
                ", i." .
                $q("institutional_id") .
                " FROM " .
                $q("sis_financial_reviews") .
                " r JOIN " .
                $q("sis_students") .
                " s ON s." .
                $q("id") .
                " = r." .
                $q("student_id") .
                " JOIN " .
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
                ($where === [] ? "" : " WHERE " . implode(" AND ", $where)) .
                " ORDER BY r." .
                $q("id") .
                " DESC",
            $values,
            51,
        );
    }

    public function action(EntityId $operation): ?array
    {
        return $this->sql->rows("sis_financial_review_actions", ["id" => $operation->value], 1)[0] ?? null;
    }

    public function record(EntityId $operation, EntityId $review, EntityId $actor, string $encrypted, string $on): void
    {
        $this->sql->insert("sis_financial_review_actions", [
            "id" => $operation->value,
            "review_id" => $review->value,
            "actor_id" => $actor->value,
            "encrypted_content" => $encrypted,
            "created_at" => $on,
        ]);
    }

    public function history(EntityId $review, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT a.*, u." .
                $q("first_name") .
                " AS " .
                $q("given_name") .
                ", u." .
                $q("last_name") .
                " AS " .
                $q("family_name") .
                " FROM " .
                $q("sis_financial_review_actions") .
                " a JOIN " .
                $q("users") .
                " u ON u." .
                $q("user_id") .
                " = a." .
                $q("actor_id") .
                " WHERE a." .
                $q("review_id") .
                " = ?" .
                ($after === null ? "" : " AND a." . $q("id") . " < ?") .
                " ORDER BY a." .
                $q("id") .
                " DESC",
            $after === null ? [$review->value] : [$review->value, $after->value],
            51,
        );
    }

    /** @param array<string, mixed> $row */
    private function hydrate(array $row): FinancialReview
    {
        return new FinancialReview(
            new EntityId($row["id"]),
            new EntityId($row["student_id"]),
            new EntityId($row["hiatus_id"]),
            new EntityId($row["requested_by"]),
            $row["created_at"],
            $row["status"],
            $row["assigned_to"] === null ? null : new EntityId($row["assigned_to"]),
            (int) $row["version"],
        );
    }
}
