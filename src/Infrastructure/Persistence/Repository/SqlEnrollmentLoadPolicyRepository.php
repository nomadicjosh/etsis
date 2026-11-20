<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\EnrollmentLoadPolicyRepository;
use Domain\Student\ValueObject\EnrollmentLoadBands;
use Domain\Student\ValueObject\EnrollmentLoadPolicyId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlEnrollmentLoadPolicyRepository implements EnrollmentLoadPolicyRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function program(EntityId $program): array
    {
        return array_intersect_key(
            $this->sql->one("sis_programs", ["id" => $program->value]),
            array_flip(["id", "code", "title"]),
        );
    }

    public function programs(string $search, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $like = "%" . str_replace(["!", "%", "_"], ["!!", "!%", "!_"], mb_strtolower($search)) . "%";
        $params = [$like, $like];
        if ($after !== null) {
            $params[] = $after->value;
        }
        $rows = $this->sql->fetch(
            "SELECT " .
                $q("id") .
                ", " .
                $q("code") .
                ", " .
                $q("title") .
                " FROM " .
                $q("sis_programs") .
                " WHERE (LOWER(" .
                $q("code") .
                ") LIKE ? ESCAPE '!' OR LOWER(" .
                $q("title") .
                ") LIKE ? ESCAPE '!')" .
                ($after === null ? "" : " AND " . $q("id") . " > ?") .
                " ORDER BY " .
                $q("id"),
            $params,
            21,
        );
        return [
            "items" => array_slice($rows, 0, 20),
            "next_after" => count($rows) > 20 ? $rows[19]["id"] : null,
        ];
    }

    public function lockScope(?EntityId $program): void
    {
        $this->sql->lock("sis_enrollment_load_policies", EnrollmentLoadPolicyId::GLOBAL, "position");
        if ($program !== null) {
            $this->sql->lock("sis_programs", $program->value, "id");
        }
    }

    public function current(?EntityId $program): array
    {
        $id = (string) EnrollmentLoadPolicyId::scope($program);
        $row = $this->sql->rows("sis_enrollment_load_policies", ["id" => $id], 1)[0] ?? null;
        if ($row === null) {
            if ($program === null) {
                throw new CorruptHistoryException();
            }
            return ["position" => 0, "operation_id" => null, "definition" => null];
        }
        try {
            if (
                $row["program_id"] !== $program?->value ||
                filter_var($row["position"], FILTER_VALIDATE_INT) === false ||
                (int) $row["position"] < 0
            ) {
                throw new CorruptHistoryException();
            }
            if ($row["operation_id"] !== null) {
                new EntityId($row["operation_id"]);
            }
            $definition = null;
            if ($row["definition"] !== null) {
                $decoded = json_decode($row["definition"], true, 8, JSON_THROW_ON_ERROR);
                $definition = new EnrollmentLoadBands($decoded)->definition;
            }
            return [
                "position" => (int) $row["position"],
                "operation_id" => $row["operation_id"],
                "definition" => $definition,
            ];
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function project(?EntityId $program, array $state): void
    {
        $id = (string) EnrollmentLoadPolicyId::scope($program);
        $row = [
            "program_id" => $program?->value,
            "position" => $state["position"],
            "operation_id" => $state["operation_id"],
            "definition" =>
                $state["definition"] === null ? null : json_encode($state["definition"], JSON_THROW_ON_ERROR),
        ];
        if ($this->sql->rows("sis_enrollment_load_policies", ["id" => $id], 1) === []) {
            $this->sql->insert("sis_enrollment_load_policies", ["id" => $id] + $row);
        } else {
            $this->sql->update("sis_enrollment_load_policies", $row, ["id" => $id]);
        }
    }
}
