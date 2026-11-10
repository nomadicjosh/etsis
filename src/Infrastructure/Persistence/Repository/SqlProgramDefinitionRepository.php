<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Enum\ClassificationKind;
use Domain\Catalog\Repository\ProgramDefinitionRepository;
use Domain\Catalog\ValueObject\ProgramDefinition;
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
        return $this->sql->rows("sis_program_details", ["id" => $program->value], 1)[0] ?? [
            "id" => $program->value,
            "description" => null,
            "department_id" => null,
            "academic_level_id" => null,
            "award_id" => null,
        ];
    }

    public function saveDraft(EntityId $program, ?string $description, array $choices): void
    {
        $row = ["description" => $description];
        foreach ($choices as $kind => $id) {
            $row[$kind . "_id"] = $id?->value;
        }
        if ($this->sql->rows("sis_program_details", ["id" => $program->value], 1) === []) {
            $this->sql->insert("sis_program_details", ["id" => $program->value] + $row);
        } else {
            $this->sql->update("sis_program_details", $row, ["id" => $program->value]);
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
            foreach (ClassificationKind::programKinds() as $kind) {
                $id = $row[$kind->value . "_id"];
                $code = $row[$kind->value . "_code"];
                $title = $row[$kind->value . "_title"];
                if ($id === null && ($code !== null || $title !== null)) {
                    throw new CorruptHistoryException();
                }
                $choices[$kind->value] =
                $id === null ? null : ["id" => $id, "code" => $code, "title" => $title];
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
            return [
                "program_id" => $row["program_id"],
                "academic_year_id" => $catalog["year_id"],
                "academic_year_code" => $row["academic_year_code"],
                "catalog_revision" => $revision,
                "stream_version" => $position,
                "definition" => $definition->describe(),
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
    ): void {
        $row = [
            "id" => $version->value,
            "program_id" => $program->value,
            "academic_year_code" => $yearCode,
            "stream_version" => $position,
            "description" => $definition->description,
        ];
        foreach ($definition->classifications->describe() as $kind => $value) {
            $row[$kind . "_id"] = $value["id"] ?? null;
            $row[$kind . "_code"] = $value["code"] ?? null;
            $row[$kind . "_title"] = $value["title"] ?? null;
        }
        $catalog = $this->sql->one("sis_program_catalogs", ["id" => $version->value]);
        if (
            $catalog["program_id"] !== $program->value ||
            $catalog["program_code"] !== $definition->code ||
            $catalog["program_title"] !== $definition->title
        ) {
            throw new CorruptHistoryException();
        }
        $old = $this->sql->rows("sis_program_catalog_details", ["id" => $version->value], 1)[0] ?? null;
        if ($old === null) {
            $this->sql->insert("sis_program_catalog_details", $row);
        } else {
            $old["stream_version"] = (int) $old["stream_version"];
            if (ProtectedEventCodec::canonical($old) !== ProtectedEventCodec::canonical($row)) {
                throw new CorruptHistoryException();
            }
        }
    }
}
