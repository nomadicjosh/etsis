<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** Explicit joint curriculum owners; these references grant no operational access. */
final readonly class AdditionalDepartments
{
    public const int LIMIT = 5;
    public array $definitions;

    public function __construct(array $definitions, ?EntityId $primary = null)
    {
        if (!array_is_list($definitions) || count($definitions) > self::LIMIT) {
            self::invalid();
        }
        $ids = [];
        $values = [];
        foreach ($definitions as $row) {
            if (!is_array($row)) {
                throw new \InvalidArgumentException(
                    "Retain complete additional department definitions.",
                );
            }
            $definition = new DepartmentDefinition($row);
            $id = new EntityId($definition->data["id"]);
            $ids[] = $id;
            $values[$id->value] = $definition->data;
        }
        self::ids($ids, $primary);
        ksort($values, SORT_STRING);
        $this->definitions = array_values($values);
    }

    /** @param list<EntityId> $ids
     * @return list<EntityId> */
    public static function ids(array $ids, ?EntityId $primary = null): array
    {
        if (!array_is_list($ids) || count($ids) > self::LIMIT) {
            self::invalid();
        }
        $values = [];
        foreach ($ids as $id) {
            if (
                !($id instanceof EntityId) ||
                isset($values[$id->value]) ||
                $id->value === $primary?->value
            ) {
                self::invalid();
            }
            $values[$id->value] = $id;
        }
        ksort($values, SORT_STRING);
        return array_values($values);
    }

    private static function invalid(): never
    {
        throw new ValidationException([
            [
                "field" => "additional_department_ids",
                "message" =>
                    "Choose up to five distinct additional departments, separate from the responsible department.",
            ],
        ]);
    }
}
