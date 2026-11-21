<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use InvalidArgumentException;

/** An explicitly retained department and its school at this publication. */
final readonly class DepartmentDefinition
{
    public function __construct(public array $data)
    {
        if (
            count($data) !== 4 ||
            array_diff(array_keys($data), ["id", "code", "title", "school"]) !== []
        ) {
            throw new InvalidArgumentException("Retain a complete department definition.");
        }
        ClassificationSnapshot::fromArray(
            array_intersect_key($data, array_flip(["id", "code", "title"])),
        );
        if ($data["school"] !== null) {
            ClassificationSnapshot::fromArray($data["school"]);
        }
    }
    public static function fromClassification(array $row): self
    {
        if ($row["kind"] !== "department") {
            throw new InvalidArgumentException("Choose a department.");
        }
        return new self(array_intersect_key($row, array_flip(["id", "code", "title", "school"])));
    }
}
