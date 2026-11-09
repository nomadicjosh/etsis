<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\DegreeAudit\Repository\RequirementAdjustmentRepository;
use Domain\DegreeAudit\RequirementAdjustments;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlRequirementAdjustmentRepository implements RequirementAdjustmentRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function active(EntityId $assignment): array
    {
        $rows = $this->sql->rows(
            "sis_requirement_adjustments",
            ["assignment_id" => $assignment->value],
            RequirementAdjustments::MAX_ACTIVE + 1,
        );
        $out = [];
        foreach ($rows as $r) {
            try {
                $p = json_decode($r["projection_json"], true, 16, JSON_THROW_ON_ERROR);
                if (
                    !is_array($p) ||
                    ($p["adjustment_id"] ?? null) !== $r["id"] ||
                    RequirementAdjustments::key($p) !== $r["scope_key"]
                ) {
                    throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
                }
                $out[$r["id"]] = $p;
            } catch (\Throwable) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
        }
        return $out;
    }
    public function grant(EntityId $assignment, array $payload): void
    {
        if ($payload["supersedes_adjustment_id"] !== null) {
            $this->revoke($assignment, new EntityId($payload["supersedes_adjustment_id"]));
        }
        $this->sql->insert("sis_requirement_adjustments", [
            "id" => $payload["adjustment_id"],
            "assignment_id" => $assignment->value,
            "scope_key" => RequirementAdjustments::key($payload),
            "projection_json" => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }
    public function revoke(EntityId $assignment, EntityId $id): void
    {
        $q = $this->sql->quote(...);
        $this->sql->execute(
            "DELETE FROM " .
                $q("sis_requirement_adjustments") .
                " WHERE " .
                $q("id") .
                " = ? AND " .
                $q("assignment_id") .
                " = ?",
            [$id->value, $assignment->value],
        );
    }
}
