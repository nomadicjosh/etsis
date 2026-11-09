<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use DateTimeImmutable;
use Domain\Catalog\CatalogYear;
use Domain\Catalog\Repository\CatalogYearRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use PDOException;

final readonly class SqlCatalogYearRepository implements CatalogYearRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }
    public function derived(EntityId $program, EntityId $term): ?EntityId
    {
        // The caller holds the program lock. Cohorts follow explicit academic-year membership.
        $mapping = $this->sql->rows("sis_term_calendar", ["id" => $term->value], 1)[0] ?? null;
        if (($mapping["academic_year_id"] ?? null) === null) {
            return null;
        }
        $year = new EntityId($mapping["academic_year_id"]);
        $this->sql->one("sis_academic_years", ["id" => $year->value]);
        $current =
        $this->sql->rows(
            "sis_program_catalogs",
            ["program_id" => $program->value, "year_id" => $year->value, "published" => 1, "is_current" => 1],
            1,
        )[0] ?? null;
        if ($current !== null) {
            return new EntityId($current["id"]);
        }
        $label = $this->sql->one("sis_programs", ["id" => $program->value]);
        $id = EntityId::generate();
        $this->addRelease($id, [
            "program_id" => $program->value,
            "year_id" => $year->value,
            "revision" => $this->nextRevision($program, $year),
            "published" => 1,
            "is_current" => 1,
            "program_code" => $label["code"],
            "program_title" => $label["title"],
        ]);
        return $id;
    }

    public function lockYear(EntityId $id): void
    {
        $this->sql->lock("sis_catalog_years", $id->value, "id");
    }
    public function lockProgram(EntityId $id): void
    {
        $this->sql->lock("sis_programs", $id->value, "id");
    }

    public function year(EntityId $id): CatalogYear
    {
        $r = $this->sql->one("sis_catalog_years", ["id" => $id->value]);
        return new CatalogYear(
            $id,
            $r["code"],
            $r["title"],
            new DateRange(new DateTimeImmutable($r["starts_on"]), new DateTimeImmutable($r["ends_on"])),
            (bool) $r["published"],
        );
    }

    public function release(EntityId $id): array
    {
        return $this->releaseRows("c." . $this->sql->quote("id") . " = ?", [$id->value], 1)[0] ??
        throw new \Domain\Shared\Exception\RecordNotFoundException("Program catalog not found.");
    }

    public function releases(EntityId $program, ?EntityId $after, bool $publishedOnly = false): array
    {
        $q = $this->sql->quote(...);
        return $this->releaseRows(
            "c." .
                $q("program_id") .
                " = ?" .
                ($publishedOnly ? " AND c." . $q("published") . " = 1" : "") .
                ($after === null ? "" : " AND c." . $q("id") . " < ?"),
            $after === null ? [$program->value] : [$program->value, $after->value],
            51,
        );
    }

    /** @param list<scalar|null> $values
     * @return list<array<string, mixed>>
     */
    private function releaseRows(string $where, array $values, int $limit): array
    {
        $q = $this->sql->quote(...);
        $rows = $this->sql->fetch(
            "SELECT c.*, y." .
                $q("code") .
                " AS " .
                $q("year_code") .
                ", y." .
                $q("code") .
                " AS " .
                $q("year_title") .
                " FROM " .
                $q("sis_program_catalogs") .
                " c JOIN " .
                $q("sis_academic_years") .
                " y ON y." .
                $q("id") .
                " = c." .
                $q("year_id") .
                " WHERE " .
                $where .
                " ORDER BY c." .
                $q("id") .
                " DESC",
            $values,
            $limit,
        );
        foreach ($rows as &$r) {
            $r["revision"] = (int) $r["revision"];
            $r["published"] = (bool) $r["published"];
            $r["is_current"] = (bool) $r["is_current"];
        }
        unset($r);
        return $rows;
    }

    public function nextRevision(EntityId $program, EntityId $year): int
    {
        $q = $this->sql->quote(...);
        $r = $this->sql->fetch(
            "SELECT MAX(" .
                $q("revision") .
                ") AS " .
                $q("maximum") .
                " FROM " .
                $q("sis_program_catalogs") .
                " WHERE " .
                $q("program_id") .
                " = ? AND " .
                $q("year_id") .
                " = ?",
            [$program->value, $year->value],
            1,
        )[0];
        return (int) ($r["maximum"] ?? 0) + 1;
    }

    public function addRelease(EntityId $id, array $data): void
    {
        $this->sql->insert("sis_program_catalogs", ["id" => $id->value] + $data);
    }

    public function publishRelease(EntityId $id, EntityId $program): void
    {
        $current = $this->release($id);
        $this->sql->update(
            "sis_program_catalogs",
            ["is_current" => 0],
            ["program_id" => $program->value, "year_id" => $current["year_id"]],
        );
        $this->sql->update("sis_program_catalogs", ["published" => 1, "is_current" => 1], ["id" => $id->value]);
    }

    public function binding(EntityId $assignment): ?array
    {
        $r = $this->sql->rows("sis_student_catalogs", ["assignment_id" => $assignment->value], 1)[0] ?? null;
        return $r === null
        ? null
        : ["id" => $r["id"], "catalog_id" => $r["catalog_id"], "version" => (int) $r["version"]];
    }

    public function bind(EntityId $assignment, EntityId $catalog, int $expectedVersion): void
    {
        if ($expectedVersion === -1) {
            $this->sql->insert("sis_student_catalogs", [
                "id" => EntityId::generate()->value,
                "assignment_id" => $assignment->value,
                "catalog_id" => $catalog->value,
                "version" => 0,
            ]);
        } elseif (
            $this->sql->update(
                "sis_student_catalogs",
                ["catalog_id" => $catalog->value, "version" => $expectedVersion + 1],
                ["assignment_id" => $assignment->value, "version" => $expectedVersion],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The student catalog changed. Reload before saving.");
        }
    }

    public function action(EntityId $operation): ?array
    {
        return $this->sql->rows("sis_student_catalog_changes", ["id" => $operation->value], 1)[0] ?? null;
    }

    public function record(
        EntityId $operation,
        EntityId $assignment,
        EntityId $actor,
        ?EntityId $previous,
        EntityId $catalog,
        ?string $encryptedNote,
        string $on,
    ): void {
        $this->sql->insert("sis_student_catalog_changes", [
            "id" => $operation->value,
            "assignment_id" => $assignment->value,
            "actor_id" => $actor->value,
            "previous_catalog_id" => $previous?->value,
            "catalog_id" => $catalog->value,
            "encrypted_note" => $encryptedNote,
            "created_at" => $on,
        ]);
    }

    public function history(EntityId $assignment, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        return $this->sql->fetch(
            "SELECT a.*, y." .
                $q("code") .
                " AS " .
                $q("year_code") .
                ", c." .
                $q("revision") .
                " AS " .
                $q("catalog_revision") .
                " FROM " .
                $q("sis_student_catalog_changes") .
                " a JOIN " .
                $q("sis_program_catalogs") .
                " c ON c." .
                $q("id") .
                " = a." .
                $q("catalog_id") .
                " JOIN " .
                $q("sis_academic_years") .
                " y ON y." .
                $q("id") .
                " = c." .
                $q("year_id") .
                " WHERE a." .
                $q("assignment_id") .
                " = ?" .
                ($after === null ? "" : " AND a." . $q("id") . " < ?") .
                " ORDER BY a." .
                $q("id") .
                " DESC",
            $after === null ? [$assignment->value] : [$assignment->value, $after->value],
            51,
        );
    }
}
