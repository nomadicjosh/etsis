<?php

declare(strict_types=1);

namespace Domain\AcademicCalendar\ValueObject;

use DateTimeImmutable;
use Domain\Shared\Exception\ValidationException;

final readonly class AcademicTerm
{
    public string $code;
    public function __construct(
        string $code,
        public string $name,
        public string $kind,
        public string $startsOn,
        public string $endsOn,
        public string $addDropEndsOn,
    ) {
        $this->code = strtoupper(trim($code));
        if (preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,21}$~D', $this->code) !== 1) {
            self::invalid("code", "Use 1–22 letters/numbers with dots, underscores, slashes or hyphens.");
        }
        if (trim($name) === "" || mb_strlen($name) > 120 || preg_match('/[\x00-\x1F\x7F]/', $name)) {
            self::invalid("name", "Enter a term name of up to 120 characters.");
        }
        if (!in_array($kind, ["standard", "non_standard"], true)) {
            self::invalid("kind", "Choose standard or non-standard.");
        }
        $dates = ["starts_on" => $startsOn, "ends_on" => $endsOn, "add_drop_ends_on" => $addDropEndsOn];
        foreach ($dates as $field => $value) {
            $date = DateTimeImmutable::createFromFormat("!Y-m-d", $value);
            if ($date === false || $date->format("Y-m-d") !== $value) {
                self::invalid($field, "Enter a valid calendar date.");
            }
        }
        if ($endsOn < $startsOn) {
            self::invalid("ends_on", "The last day must be on or after the first day.");
        }
        if ($addDropEndsOn < $startsOn || $addDropEndsOn > $endsOn) {
            self::invalid("add_drop_ends_on", "The drop/add deadline must fall within the term.");
        }
    }

    /** @return array{code: string, name: string, kind: string, starts_on: string, ends_on: string, add_drop_ends_on: string} */
    public function describe(): array
    {
        return [
            "code" => $this->code,
            "name" => $this->name,
            "kind" => $this->kind,
            "starts_on" => $this->startsOn,
            "ends_on" => $this->endsOn,
            "add_drop_ends_on" => $this->addDropEndsOn,
        ];
    }

    private static function invalid(string $field, string $message): never
    {
        throw new ValidationException([["field" => $field, "message" => $message]]);
    }
}
