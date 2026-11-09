<?php

declare(strict_types=1);

namespace Domain\AcademicCalendar\ValueObject;

use DateTimeImmutable;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class TermType
{
    /** @param array<string, array{year: string, month_day: string}> $dates */
    public function __construct(
        public EntityId $id,
        public string $code,
        public string $namePattern,
        public string $codePattern,
        public string $kind,
        public array $dates,
        public bool $active = true,
        public int $version = 0,
    ) {
        if (
            preg_match('/^[A-Z0-9][A-Z0-9._-]{0,21}$/D', $code) !== 1 ||
            !in_array($kind, ["standard", "non_standard"], true)
        ) {
            self::invalid("code", "Use a stable term-type code and choose standard or non-standard.");
        }
        foreach (["name_pattern" => $namePattern, "code_pattern" => $codePattern] as $field => $pattern) {
            $literal = str_replace(
                ["{{START_YEAR}}", "{{END_YEAR}}", "{{START_YEAR_SHORT}}", "{{END_YEAR_SHORT}}"],
                "",
                $pattern,
            );
            if (trim($pattern) === "" || mb_strlen($pattern) > 120 || preg_match('/[{}\x00-\x1F\x7F]/', $literal)) {
                self::invalid($field, "Use text and the supported year placeholders only.");
            }
        }
        if (
            array_diff(array_keys($dates), ["starts_on", "ends_on", "add_drop_ends_on"]) !== [] ||
            count($dates) !== 3
        ) {
            self::invalid("dates", "Define the start, end and drop/add deadline.");
        }
        foreach ($dates as $field => $definition) {
            if (!in_array($definition["year"], ["START_YEAR", "END_YEAR"], true)) {
                self::invalid($field, "Choose the start or end year.");
            }
            $day = DateTimeImmutable::createFromFormat("!Y-m-d", "2000-" . $definition["month_day"]);
            if ($day === false || $day->format("m-d") !== $definition["month_day"]) {
                self::invalid($field, "Use a valid month and day (MM-DD).");
            }
        }
        if ($version < 0) {
            self::invalid("version", "Use the current term-type version.");
        }
    }

    /** @return array<string, mixed> */
    public function generate(int $start): array
    {
        if ($start < 1000 || $start > 2998) {
            self::invalid("start_year", "Use a four-digit start year from 1000 to 2998.");
        }
        $replace = [
            "{{START_YEAR}}" => (string) $start,
            "{{END_YEAR}}" => (string) ($start + 1),
            "{{START_YEAR_SHORT}}" => substr((string) $start, -2),
            "{{END_YEAR_SHORT}}" => substr((string) ($start + 1), -2),
        ];
        $dates = [];
        foreach ($this->dates as $field => $definition) {
            $year = $definition["year"] === "START_YEAR" ? $start : $start + 1;
            $value = $year . "-" . $definition["month_day"];
            $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
            if ($date === false || $date->format("Y-m-d") !== $value) {
                self::invalid($field, "This date is not valid in the generated year. Review the term type.");
            }
            $dates[$field] = $value;
        }
        $term = new AcademicTerm(
            strtoupper(strtr($this->codePattern, $replace)),
            strtr($this->namePattern, $replace),
            $this->kind,
            $dates["starts_on"],
            $dates["ends_on"],
            $dates["add_drop_ends_on"],
        );
        return $term->describe() + [
            "term_type_id" => $this->id->value,
            "term_type_version" => $this->version,
        ];
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return [
            "id" => $this->id->value,
            "code" => $this->code,
            "name_pattern" => $this->namePattern,
            "code_pattern" => $this->codePattern,
            "kind" => $this->kind,
            "dates" => $this->dates,
            "active" => $this->active,
            "version" => $this->version,
        ];
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
