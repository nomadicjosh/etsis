<?php

declare(strict_types=1);

namespace Domain\Reporting\ValueObject;

use Domain\Shared\Exception\ValidationException;

/** Select whole related rows by approved dates, never by user-supplied SQL or lexical business codes. */
final class ReportRowSelection
{
    /** @param array<string,mixed> $schema
     * @return array<string,list<string>> Date field => safe single-record descendant path. */
    public static function dates(array $schema, string $relation): array
    {
        $selected = $schema["relationships"][$relation] ?? null;
        if ($selected === null || !($selected["many"] ?? false) || isset($selected["source"])) {
            return [];
        }
        $result = [];
        foreach ($schema["fields"] as $id => $field) {
            if (
                !in_array($field["type"], ["date", "datetime"], true) ||
                (isset($field['expression']) && $field['expression'] !== 'transfer_completion_date') ||
                isset($field["scale"])
            ) {
                continue;
            }
            $path = [];
            $current = $field["relation"];
            while ($current !== $relation) {
                $step = $schema["relationships"][$current] ?? null;
                if (
                    $step === null ||
                    ($step["many"] ?? false) ||
                    (isset($step['source']) &&
                        !in_array($step['source'], ['transfer_award', 'institutional_dates'], true)) ||
                    count($path) >= 4
                ) {
                    continue 2;
                }
                array_unshift($path, $current);
                $current = $step["parent"];
            }
            $result[$id] = $path;
        }
        return $result;
    }

    /** @param array<string,mixed> $schema
     * @return array<string,array{date_field:string,direction:string,ties:string}> */
    public static function validate(mixed $selections, array $schema): array
    {
        if (!is_array($selections) || count($selections) > 6) {
            self::invalid();
        }
        foreach ($selections as $relation => $selection) {
            if (
                !is_string($relation) ||
                !is_array($selection) ||
                count($selection) !== 3 ||
                !is_string($selection["date_field"] ?? null) ||
                !isset(self::dates($schema, $relation)[$selection["date_field"]]) ||
                !in_array($selection["direction"] ?? null, ["latest", "earliest"], true) ||
                !in_array($selection["ties"] ?? null, ["all", "one"], true)
            ) {
                self::invalid();
            }
        }
        return $selections;
    }

    private static function invalid(): never
    {
        throw new ValidationException([
            [
                "field" => "record_selection",
                "message" =>
                    "Choose an approved related date, Latest or Earliest, and how equal dates should be handled.",
            ],
        ]);
    }
}
