<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\AcademicCalendar\Repository\AcademicCalendarRepository;
use Domain\AcademicCalendar\ValueObject\TermType;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAcademicCalendarRepository implements AcademicCalendarRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function lockCalendar(): void
    {
        $this->sql->lock("sis_academic_calendar", "00000000000000000000000001", "version");
    }

    public function revision(): int
    {
        return (int) $this->sql->one("sis_academic_calendar", ["id" => "00000000000000000000000001"])["version"];
    }

    public function touch(): void
    {
        $this->sql->update(
            "sis_academic_calendar",
            ["version" => $this->revision() + 1],
            ["id" => "00000000000000000000000001"],
        );
    }

    public function type(EntityId $id): TermType
    {
        $row = $this->sql->one("sis_term_types", ["id" => $id->value]);
        $d = json_decode($row["definition"], true, 16, JSON_THROW_ON_ERROR);
        return new TermType(
            $id,
            $row["code"],
            $d["name_pattern"],
            $d["code_pattern"],
            $d["kind"],
            $d["dates"],
            (bool) $row["active"],
            (int) $row["version"],
        );
    }

    public function types(?EntityId $after, bool $activeOnly = false): array
    {
        $q = $this->sql->quote(...);
        $where = $activeOnly ? $q("active") . " = 1" : "1 = 1";
        $args = [];
        if ($after !== null) {
            $where .= " AND " . $q("id") . " > ?";
            $args[] = $after->value;
        }
        $rows = $this->sql->fetch(
            "SELECT * FROM " . $q("sis_term_types") . " WHERE " . $where . " ORDER BY " . $q("id"),
            $args,
            $activeOnly ? 101 : 51,
        );
        return array_map(static function (array $row): array {
            $definition = json_decode($row["definition"], true, 16, JSON_THROW_ON_ERROR);
            return $definition + [
                "id" => $row["id"],
                "code" => $row["code"],
                "version" => (int) $row["version"],
                "active" => (bool) $row["active"],
            ];
        }, $rows);
    }

    public function saveType(TermType $type, int $expectedVersion): void
    {
        $values = [
            "code" => $type->code,
            "version" => $expectedVersion + 1,
            "active" => (int) $type->active,
            "definition" => json_encode(
                [
                    "name_pattern" => $type->namePattern,
                    "code_pattern" => $type->codePattern,
                    "kind" => $type->kind,
                    "dates" => $type->dates,
                ],
                JSON_THROW_ON_ERROR,
            ),
        ];
        try {
            if ($expectedVersion === -1) {
                $this->sql->insert("sis_term_types", ["id" => $type->id->value] + $values);
            } elseif (
                $this->sql->update("sis_term_types", $values, [
                    "id" => $type->id->value,
                    "version" => $expectedVersion,
                ]) !== 1
            ) {
                throw new ConcurrentModificationException("The term type changed. Reload it.");
            }
        } catch (\PDOException $e) {
            if (in_array((string) $e->getCode(), ["23000", "23505"], true)) {
                throw new \Domain\Shared\Exception\ValidationException([
                    ["field" => "code", "message" => "This term-type code already exists."],
                ]);
            }
            throw $e;
        }
    }

    public function years(?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT y.*, (SELECT COUNT(*) FROM " .
                $q("sis_term_calendar") .
                " t WHERE t." .
                $q("academic_year_id") .
                " = y." .
                $q("id") .
                ") AS " .
                $q("term_count") .
                " FROM " .
                $q("sis_academic_years") .
                " y" .
                ($after ? " WHERE y." . $q("id") . " > ?" : "") .
                " ORDER BY y." .
                $q("id"),
            $after ? [$after->value] : [],
            51,
        );
        return array_map($this->normalizeYear(...), $rows);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeYear(array $row): array
    {
        $row["start_year"] = (int) $row["start_year"];
        $row["end_year"] = (int) $row["end_year"];
        $row["legacy"] = (bool) $row["legacy"];
        if (isset($row["term_count"])) {
            $row["term_count"] = (int) $row["term_count"];
        }
        return $row;
    }

    public function year(EntityId $id): array
    {
        return $this->normalizeYear($this->sql->one("sis_academic_years", ["id" => $id->value]));
    }

    public function yearByStart(int $start): ?array
    {
        $row = $this->sql->rows("sis_academic_years", ["start_year" => $start], 1)[0] ?? null;
        return $row === null ? null : $this->normalizeYear($row);
    }

    public function addYear(array $year): void
    {
        $this->sql->insert("sis_catalog_years", [
            "id" => $year["id"],
            "code" => (string) $year["start_year"],
            "title" => $year["code"],
            "starts_on" => $year["starts_on"],
            "ends_on" => $year["ends_on"],
            "published" => 1,
        ]);
        $this->sql->insert("sis_academic_years", $year + ["legacy" => 0]);
    }

    private function termSelect(): string
    {
        $q = $this->sql->quote(...);
        return "SELECT t.*, c." .
        $q("academic_year_id") .
        ", c." .
        $q("term_type_id") .
        ", c." .
        $q("term_type_version") .
        ", c." .
        $q("kind") .
        ", c." .
        $q("name") .
        ", c." .
        $q("version") .
        " AS " .
        $q("calendar_version") .
        " FROM " .
        $q("sis_terms") .
        " t LEFT JOIN " .
        $q("sis_term_calendar") .
        " c ON c." .
        $q("id") .
        " = t." .
        $q("id");
    }

    public function term(EntityId $id): array
    {
        $q = $this->sql->quote(...);
        $row =
        $this->sql->fetch($this->termSelect() . " WHERE t." . $q("id") . " = ?", [$id->value], 1)[0] ??
        throw new \Domain\Shared\Exception\RecordNotFoundException("Term not found.");
        $row["calendar_version"] = (int) ($row["calendar_version"] ?? -1);
        $row["kind"] ??= "unclassified";
        $row["name"] ??= $row["code"];
        $row["term_type_version"] = $row["term_type_version"] === null ? null : (int) $row["term_type_version"];
        return $row;
    }

    public function termByCode(string $code): ?array
    {
        $q = $this->sql->quote(...);
        $r = $this->sql->fetch(
            "SELECT " . $q("id") . " FROM " . $q("sis_terms") . " WHERE UPPER(" . $q("code") . ") = UPPER(?)",
            [$code],
            1,
        );
        return $r === [] ? null : $this->term(new EntityId($r[0]["id"]));
    }

    public function saveTerm(EntityId $id, ?EntityId $year, array $term, bool $insert): void
    {
        $values = array_intersect_key($term, array_flip(["code", "starts_on", "ends_on", "add_drop_ends_on"]));
        if ($insert) {
            $this->sql->insert("sis_terms", ["id" => $id->value] + $values);
        } else {
            $this->sql->update("sis_terms", $values, ["id" => $id->value]);
        }
        $current = $this->sql->rows("sis_term_calendar", ["id" => $id->value], 1)[0] ?? null;
        $metadata = [
            "academic_year_id" => $year?->value,
            "kind" => $term["kind"],
            "name" => $term["name"],
            "version" => $current === null ? 0 : (int) $current["version"] + 1,
        ];
        if ($current === null) {
            $this->sql->insert(
                "sis_term_calendar",
                [
                    "id" => $id->value,
                    "term_type_id" => $term["term_type_id"] ?? null,
                    "term_type_version" => $term["term_type_version"] ?? null,
                ] + $metadata,
            );
        } else {
            $this->sql->update(
                "sis_term_calendar",
                $metadata + [
                    "term_type_id" => $term["term_type_id"] ?? $current["term_type_id"],
                    "term_type_version" => $term["term_type_version"] ?? $current["term_type_version"],
                ],
                ["id" => $id->value],
            );
        }
        if ($year !== null) {
            $q = $this->sql->quote(...);
            $span = $this->sql->fetch(
                "SELECT MIN(t." .
                    $q("starts_on") .
                    ") AS " .
                    $q("starts_on") .
                    ", MAX(t." .
                    $q("ends_on") .
                    ") AS " .
                    $q("ends_on") .
                    " FROM " .
                    $q("sis_terms") .
                    " t JOIN " .
                    $q("sis_term_calendar") .
                    " c ON c." .
                    $q("id") .
                    " = t." .
                    $q("id") .
                    " WHERE c." .
                    $q("academic_year_id") .
                    " = ?",
                [$year->value],
                1,
            )[0];
            $this->sql->update("sis_academic_years", $span, ["id" => $year->value]);
            $this->sql->update("sis_catalog_years", $span, ["id" => $year->value]);
        }
    }

    public function overlaps(string $start, string $end, ?EntityId $except, array $excluding = []): array
    {
        $q = $this->sql->quote(...);
        $extra =
        $excluding === []
        ? ""
        : " AND t." . $q("id") . " NOT IN (" . implode(", ", array_fill(0, count($excluding), "?")) . ")";
        return $this->sql->fetch(
            $this->termSelect() .
                " WHERE t." .
                $q("starts_on") .
                " <= ? AND t." .
                $q("ends_on") .
                " >= ? AND (c." .
                $q("kind") .
                " IS NULL OR c." .
                $q("kind") .
                " IN ('standard', 'unclassified'))" .
                ($except ? " AND t." . $q("id") . " <> ?" : "") .
                $extra,
            [...$except ? [$end, $start, $except->value] : [$end, $start], ...$excluding],
            1,
        );
    }

    public function operation(EntityId $id): ?array
    {
        return $this->sql->rows("sis_academic_year_actions", ["id" => $id->value], 1)[0] ?? null;
    }

    public function record(
        EntityId $id,
        EntityId $year,
        EntityId $actor,
        string $hash,
        string $encrypted,
        string $at,
    ): void {
        $this->sql->insert("sis_academic_year_actions", [
            "id" => $id->value,
            "year_id" => $year->value,
            "actor_id" => $actor->value,
            "request_hash" => $hash,
            "encrypted_content" => $encrypted,
            "created_at" => $at,
        ]);
    }
}
