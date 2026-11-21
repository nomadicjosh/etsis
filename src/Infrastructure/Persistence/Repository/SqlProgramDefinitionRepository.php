<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Repository\ProgramDefinitionRepository;
use Domain\Catalog\ValueObject\ProgramDefinition;
use Domain\Catalog\ValueObject\ProgramSpecializationSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\EventSourcing\ProtectedEventCodec;
use Infrastructure\Persistence\SqlRecords;
use Throwable;

final readonly class SqlProgramDefinitionRepository implements ProgramDefinitionRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function draft(EntityId $program): array
    {
        $row = $this->sql->rows("sis_program_details", ["id" => $program->value], 1)[0] ?? [
            "id" => $program->value,
            "description" => null,
            "department_id" => null,
            "academic_level_id" => null,
            "award_id" => null,
            "school_id" => null,
            "field_of_study_id" => null,
            "campus_id" => null,
        ];
        $sites = $this->sql->rows("sis_program_sites", ["program_id" => $program->value], 21);
        if (count($sites) > 20) {
            throw new CorruptHistoryException();
        }
        $row["additional_campus_ids"] = array_column($sites, "campus_id");
        sort($row["additional_campus_ids"], SORT_STRING);
        return $row;
    }

    public function saveDraft(
        EntityId $program,
        ?string $description,
        array $choices,
        array $additionalCampuses = [],
    ): void {
        $row = ["description" => $description];
        foreach ($choices as $kind => $id) {
            $row[$kind . "_id"] = $id?->value;
        }
        if ($this->sql->rows("sis_program_details", ["id" => $program->value], 1) === []) {
            $this->sql->insert("sis_program_details", ["id" => $program->value] + $row);
        } else {
            $this->sql->update("sis_program_details", $row, ["id" => $program->value]);
        }
        $ids = array_map(static fn(EntityId $id): string => $id->value, $additionalCampuses);
        $current = $this->sql->rows("sis_program_sites", ["program_id" => $program->value], 21);
        if (count($current) > 20 || count($ids) > 20 || count(array_unique($ids)) !== count($ids)) {
            throw new CorruptHistoryException();
        }
        foreach ($current as $site) {
            if (!in_array($site["campus_id"], $ids, true)) {
                $this->sql->execute(
                    "DELETE FROM " .
                        $this->sql->quote("sis_program_sites") .
                        " WHERE " .
                        $this->sql->quote("id") .
                        " = ?",
                    [$site["id"]],
                );
            }
        }
        $existing = array_column($current, "campus_id");
        foreach ($ids as $id) {
            if (!in_array($id, $existing, true)) {
                $this->sql->insert("sis_program_sites", [
                    "id" => EntityId::generate()->value,
                    "program_id" => $program->value,
                    "campus_id" => $id,
                ]);
            }
        }
    }

    public function lockYear(EntityId $year): void
    {
        $this->sql->lock("sis_academic_years", $year->value, "id");
    }

    public function current(EntityId $program, EntityId $year): ?array
    {
        $rows = $this->sql->rows(
            "sis_program_catalogs",
            ["program_id" => $program->value, "year_id" => $year->value, "published" => 1, "is_current" => 1],
            2,
        );
        if (count($rows) > 1) {
            throw new CorruptHistoryException();
        }
        return $rows[0] ?? null;
    }

    public function retained(EntityId $version): ?array
    {
        try {
            $row = $this->sql->rows("sis_program_catalog_details", ["id" => $version->value], 1)[0] ?? null;
            if ($row === null) {
                return null;
            }
            $catalog = $this->sql->one("sis_program_catalogs", ["id" => $version->value]);
            $choices = [];
            $extended = match ($row["school_field_recorded"] ?? null) {
                true, 1, "1", "t" => true,
                false, 0, "0", "f" => false,
                default => throw new CorruptHistoryException(),
            };
            $campusRecorded = match ($row["campus_recorded"] ?? null) {
                true, 1, "1", "t" => true,
                false, 0, "0", "f" => false,
                default => throw new CorruptHistoryException(),
            };
            foreach (ClassificationKind::programKinds() as $kind) {
                $id = $row[$kind->value . "_id"];
                $code = $row[$kind->value . "_code"];
                $title = $row[$kind->value . "_title"];
                if ($id === null && ($code !== null || $title !== null)) {
                    throw new CorruptHistoryException();
                }
                if (
                    !$extended &&
                    in_array($kind, [ClassificationKind::School, ClassificationKind::FieldOfStudy], true)
                ) {
                    if ($id !== null || $code !== null || $title !== null) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                if ($kind === ClassificationKind::Campus && !$campusRecorded) {
                    if ($id !== null || $code !== null || $title !== null) {
                        throw new CorruptHistoryException();
                    }
                    continue;
                }
                $choices[$kind->value] =
                $id === null ? null : ["id" => $id, "code" => $code, "title" => $title];
            }
            $sitesRecorded = match ($row["additional_campuses_recorded"] ?? null) {
                true, 1, "1", "t" => true,
                false, 0, "0", "f" => false,
                default => throw new CorruptHistoryException(),
            };
            $sites = $this->retainedSites($version);
            if ($sitesRecorded) {
                $choices["additional_campuses"] = $sites;
            } elseif ($sites !== []) {
                throw new CorruptHistoryException();
            }
            $definition = ProgramDefinition::fromArray([
                "code" => $catalog["program_code"],
                "title" => $catalog["program_title"],
                "description" => $row["description"],
                "classifications" => $choices,
            ]);
            $position = filter_var($row["stream_version"], FILTER_VALIDATE_INT);
            $revision = filter_var($catalog["revision"], FILTER_VALIDATE_INT);
            if (
                $position === false ||
                $position < 1 ||
                $revision === false ||
                $revision < 1 ||
                $row["program_id"] !== $catalog["program_id"]
            ) {
                throw new CorruptHistoryException();
            }
            $specialization = $this->sql->one("sis_program_specialization_contexts", [
                "id" => $version->value,
            ]);
            $snapshot = new ProgramSpecializationSnapshot(
                json_decode($specialization["snapshot_json"], true, 16, JSON_THROW_ON_ERROR),
            );
            if (
                $specialization["program_id"] !== $row["program_id"] ||
                filter_var($specialization["stream_version"], FILTER_VALIDATE_INT) !== $position ||
                $snapshot->data["program_id"] !== $row["program_id"] ||
                !hash_equals(
                    $specialization["snapshot_hash"],
                    hash("sha256", ProtectedEventCodec::canonical($snapshot->data)),
                )
            ) {
                throw new CorruptHistoryException();
            }
            return [
                "program_id" => $row["program_id"],
                "academic_year_id" => $catalog["year_id"],
                "academic_year_code" => $row["academic_year_code"],
                "catalog_revision" => $revision,
                "stream_version" => $position,
                "definition" => $definition->describe(),
                "specializations" => $snapshot->data,
            ];
        } catch (Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function retain(
        EntityId $program,
        EntityId $version,
        string $yearCode,
        int $position,
        ProgramDefinition $definition,
        ProgramSpecializationSnapshot $specializations,
    ): void {
        $row = [
            "id" => $version->value,
            "program_id" => $program->value,
            "academic_year_code" => $yearCode,
            "stream_version" => $position,
            "description" => $definition->description,
            "school_field_recorded" => (int) $definition->classifications->schoolAndFieldRecorded,
            "campus_recorded" => (int) $definition->classifications->campusRecorded,
            "additional_campuses_recorded" => (int) $definition->classifications->additionalCampusesRecorded,
        ];
        $values = $definition->classifications->describe();
        foreach (ClassificationKind::programKinds() as $category) {
            $kind = $category->value;
            $value = $values[$kind] ?? null;
            $row[$kind . "_id"] = $value["id"] ?? null;
            $row[$kind . "_code"] = $value["code"] ?? null;
            $row[$kind . "_title"] = $value["title"] ?? null;
        }
        $catalog = $this->sql->one("sis_program_catalogs", ["id" => $version->value]);
        if (
            $catalog["program_id"] !== $program->value ||
            $catalog["program_code"] !== $definition->code ||
            $catalog["program_title"] !== $definition->title ||
            $specializations->data["program_id"] !== $program->value
        ) {
            throw new CorruptHistoryException();
        }
        $old = $this->sql->rows("sis_program_catalog_details", ["id" => $version->value], 1)[0] ?? null;
        if ($old === null) {
            $this->sql->insert("sis_program_catalog_details", $row);
            foreach ($definition->classifications->additionalCampuses as $site) {
                $this->sql->insert("sis_program_catalog_sites", [
                    "id" => EntityId::generate()->value,
                    "catalog_id" => $version->value,
                    "campus_id" => $site->id->value,
                    "campus_code" => $site->code,
                    "campus_title" => $site->title,
                ]);
            }
        } else {
            $old["stream_version"] = (int) $old["stream_version"];
            $old["school_field_recorded"] = (int) $old["school_field_recorded"];
            $old["campus_recorded"] = (int) $old["campus_recorded"];
            $old["additional_campuses_recorded"] = (int) $old["additional_campuses_recorded"];
            if (ProtectedEventCodec::canonical($old) !== ProtectedEventCodec::canonical($row)) {
                throw new CorruptHistoryException();
            }
        }
        $expectedSites = array_map(
            static fn(\Domain\Catalog\ValueObject\ClassificationSnapshot $site): array => $site->describe(),
            $definition->classifications->additionalCampuses,
        );
        if ($this->retainedSites($version) !== $expectedSites) {
            throw new CorruptHistoryException();
        }
        $context = [
            "id" => $version->value,
            "program_id" => $program->value,
            "stream_version" => $position,
            "snapshot_json" => ProtectedEventCodec::canonical($specializations->data),
            "snapshot_hash" => hash("sha256", ProtectedEventCodec::canonical($specializations->data)),
        ];
        $previous =
        $this->sql->rows("sis_program_specialization_contexts", ["id" => $version->value], 1)[0] ?? null;
        if ($previous === null) {
            $this->sql->insert("sis_program_specialization_contexts", $context);
        } else {
            $previous["stream_version"] = filter_var($previous["stream_version"], FILTER_VALIDATE_INT);
            if ($previous !== $context) {
                throw new CorruptHistoryException();
            }
        }
    }
    /** @return list<array{id:string,code:string,title:string}> */
    private function retainedSites(EntityId $version): array
    {
        $rows = $this->sql->rows("sis_program_catalog_sites", ["catalog_id" => $version->value], 21);
        if (count($rows) > 20) {
            throw new CorruptHistoryException();
        }
        $sites = [];
        foreach ($rows as $row) {
            if (isset($sites[$row["campus_id"]])) {
                throw new CorruptHistoryException();
            }
            $sites[$row["campus_id"]] = [
                "id" => $row["campus_id"],
                "code" => $row["campus_code"],
                "title" => $row["campus_title"],
            ];
        }
        ksort($sites, SORT_STRING);
        return array_values($sites);
    }
}
