<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Gradebook\Repository\GradingScaleRepository;
use Domain\Gradebook\Service\GradingScaleService;
use Domain\Gradebook\ValueObject\PublishedGradeId;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlGradingScaleRepository implements GradingScaleRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function requireProgram(EntityId $program): void
    {
        $this->sql->one("sis_programs", ["id" => $program->value]);
    }
    public function lockScope(?EntityId $program): void
    {
        if ($program !== null) {
            $this->sql->lock("sis_programs", $program->value, "id");
            $this->requireProgram($program);
        } elseif ($this->sql->rows("sis_grading_scales", ["scope_key" => "global"], 1) !== []) {
            $this->sql->lock("sis_grading_scales", GradingScaleService::GLOBAL_ID, "id");
        }
    }
    public function current(?EntityId $program): ?array
    {
        $r =
        $this->sql->rows(
            "sis_grading_scales",
            ["scope_key" => $program === null ? "global" : $program->value],
            1,
        )[0] ?? null;
        if ($r === null) {
            return null;
        }
        if (
            $r["id"] !== ($program === null ? GradingScaleService::GLOBAL_ID : $program->value) ||
            $r["program_id"] !== $program?->value
        ) {
            throw new CorruptHistoryException();
        }
        $grades = $this->grades(new EntityId($r["version_id"]));
        $scale = $program === null ? GradingScaleService::GLOBAL_ID : $program->value;
        foreach ($grades as $g) {
            if ($g["scale_id"] !== $scale) {
                throw new CorruptHistoryException();
            }
        }
        return [
            "version_id" => $r["version_id"],
            "position" => self::integer($r["position"]),
            "definition" => $grades === [] ? null : ["grades" => array_map(self::facts(...), $grades)],
        ];
    }
    /** @param array<string,mixed> $g
     * @return array<string,mixed>
     */
    private static function facts(array $g): array
    {
        return array_intersect_key(
            $g,
            array_flip([
                "code",
                "min_score_milli",
                "max_score_milli",
                "points_milli",
                "passes",
                "counts_gpa",
            ]),
        );
    }
    public function grades(EntityId $version): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT * FROM " .
                $q("sis_grades") .
                " WHERE " .
                $q("scale_version_id") .
                " = ? ORDER BY " .
                $q("ordinal"),
            [$version->value],
            201,
        );
        if (count($rows) > 200) {
            throw new CorruptHistoryException();
        }
        foreach ($rows as $i => &$r) {
            foreach (["points_milli", "ordinal"] as $key) {
                $r[$key] = self::integer($r[$key]);
            }
            foreach (["min_score_milli", "max_score_milli"] as $key) {
                $r[$key] = $r[$key] === null ? null : self::integer($r[$key]);
            }
            foreach (["passes", "counts_gpa"] as $key) {
                $v = self::integer($r[$key]);
                if (!in_array($v, [0, 1], true)) {
                    throw new CorruptHistoryException();
                }
                $r[$key] = (bool) $v;
            }
            if (
                $r["ordinal"] !== $i ||
                $r["id"] !== PublishedGradeId::forVersion($version, $r["code"])->value
            ) {
                throw new CorruptHistoryException();
            }
        }
        unset($r);
        return $rows;
    }
    private static function integer(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^(0|[1-9][0-9]*)$/D', $value) !== 1) {
            throw new CorruptHistoryException();
        }
        return (int) $value;
    }
    public function save(?EntityId $program, EntityId $version, int $position, ?array $definition): void
    {
        $scope = $program === null ? "global" : $program->value;
        $id = $program === null ? GradingScaleService::GLOBAL_ID : $program->value;
        $values = ["version_id" => $version->value, "position" => $position];
        if ($this->sql->rows("sis_grading_scales", ["scope_key" => $scope], 1) === []) {
            $this->sql->insert(
                "sis_grading_scales",
                ["id" => $id, "scope_key" => $scope, "program_id" => $program?->value] + $values,
            );
        } else {
            $this->sql->update("sis_grading_scales", $values, ["scope_key" => $scope]);
        }
        $this->project($program, $version, $definition);
    }
    /** Idempotent projection of recorded definitions, never an event or academic award backfill.
     * @param array<string,mixed>|null $definition
     */
    public function project(?EntityId $program, EntityId $version, ?array $definition): void
    {
        $scale = $program === null ? GradingScaleService::GLOBAL_ID : $program->value;
        $grades = $definition["grades"] ?? [];
        foreach ($grades as $i => $g) {
            $id = PublishedGradeId::forVersion($version, $g["code"]);
            $expected =
            [
                    "id" => $id->value,
                    "scale_id" => $scale,
                    "scale_version_id" => $version->value,
                    "ordinal" => $i,
                ] + $g;
            $old = $this->sql->rows("sis_grades", ["id" => $id->value], 1)[0] ?? null;
            if ($old === null) {
                $this->sql->insert(
                    "sis_grades",
                    array_map(static fn($v) => is_bool($v) ? (int) $v : $v, $expected),
                );
            }
        }
        $actual = $this->grades($version);
        if (count($actual) !== count($grades)) {
            throw new CorruptHistoryException();
        }
        foreach ($actual as $i => $r) {
            if ($r["scale_id"] !== $scale || !self::same(self::facts($r), $grades[$i])) {
                throw new CorruptHistoryException();
            }
        }
    }
    private static function same(mixed $a, mixed $b): bool
    {
        if (!is_array($a) || !is_array($b)) {
            return $a === $b;
        }
        if (count($a) !== count($b)) {
            return false;
        }
        foreach ($a as $key => $value) {
            if (!array_key_exists($key, $b) || !self::same($value, $b[$key])) {
                return false;
            }
        }
        return true;
    }
}
