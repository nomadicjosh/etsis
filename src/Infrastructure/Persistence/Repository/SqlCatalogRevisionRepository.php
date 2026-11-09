<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Catalog\Repository\CatalogRevisionRepository;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;
use InvalidArgumentException;

final readonly class SqlCatalogRevisionRepository implements CatalogRevisionRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    private function table(string $kind): string
    {
        return match ($kind) {
            "courses" => "sis_courses",
            "sections" => "sis_sections",
            "programs" => "sis_programs",
            "terms" => "sis_terms",
            default => throw new InvalidArgumentException("Unknown catalog record."),
        };
    }

    public function get(string $kind, EntityId $id): array
    {
        $row = $this->sql->one($this->table($kind), ["id" => $id->value]);
        if ($kind === "courses") {
            $row += new \Infrastructure\Persistence\SqlCourseText($this->sql)->get($id);
        }
        if ($kind === "sections") {
            $delivery = $this->sql->rows("sis_section_delivery", ["section_id" => $id->value], 1);
            $row["delivery_mode"] = $delivery[0]["mode"] ?? "unspecified";
        }
        return $row;
    }

    public function lock(string $kind, EntityId $id): void
    {
        $this->sql->lock($this->table($kind), $id->value, "id");
    }

    public function save(string $kind, EntityId $id, array $values): void
    {
        if (
            $kind === "courses" &&
            (array_key_exists("transcript_title", $values) || array_key_exists("description", $values))
        ) {
            $text = new \Infrastructure\Persistence\SqlCourseText($this->sql);
            $current = $text->get($id);
            $text->save($id, \Domain\Catalog\ValueObject\CourseText::fromArray(array_replace($current, $values)));
            unset($values["transcript_title"], $values["description"]);
        }
        if ($values === []) {
            return;
        }
        if ($kind === "sections" && isset($values["delivery_mode"])) {
            $mode = \Domain\Registration\Enum\DeliveryMode::parse((string) $values["delivery_mode"]);
            unset($values["delivery_mode"]);
            if ($this->sql->rows("sis_section_delivery", ["section_id" => $id->value], 1) === []) {
                $this->sql->insert("sis_section_delivery", [
                    "id" => EntityId::generate()->value,
                    "section_id" => $id->value,
                    "mode" => $mode->value,
                ]);
            } else {
                $this->sql->update("sis_section_delivery", ["mode" => $mode->value], ["section_id" => $id->value]);
            }
        }
        $this->sql->update($this->table($kind), $values, ["id" => $id->value]);
    }
}
