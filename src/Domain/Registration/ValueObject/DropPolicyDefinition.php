<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Frozen dates and grade periods governing a term's subsequent drop decisions. */
final readonly class DropPolicyDefinition
{
    public const array FIELDS = [
        "term_id",
        "term_code",
        "starts_on",
        "ends_on",
        "grace_ends_on",
        "timezone",
        "windows",
    ];

    /** @param array<string,mixed> $data */
    public function __construct(public array $data)
    {
        if (
            !in_array(count($data), [count(self::FIELDS), count(self::FIELDS) + 3], true) ||
            array_diff(array_keys($data), [...self::FIELDS, 'section_id', 'section_code', 'uses_term_policy']) !== []
        ) {
            throw new InvalidArgumentException(
                "A complete drop policy is required.",
            );
        }
        if (
            !is_string($data["term_id"]) ||
            !is_string($data["term_code"]) ||
            trim($data["term_code"]) === "" ||
            mb_strlen($data["term_code"]) > 40 ||
            !is_string($data["timezone"]) ||
            !is_array($data["windows"])
        ) {
            throw new InvalidArgumentException(
                "Valid term and policy details are required.",
            );
        }
        new EntityId($data["term_id"]);
        if (count($data) !== count(self::FIELDS)) {
            if (
                !is_string($data['section_id'] ?? null) || !is_string($data['section_code'] ?? null) ||
                trim($data['section_code']) === '' || mb_strlen($data['section_code']) > 80 ||
                !mb_check_encoding($data['section_code'], 'UTF-8') ||
                preg_match('/[\x00-\x1F\x7F]/', $data['section_code']) ||
                !is_bool($data['uses_term_policy'] ?? null)
            ) {
                throw new InvalidArgumentException('Complete section policy details are required.');
            }
            new EntityId($data['section_id']);
        }
        new DateTimeZone($data["timezone"]);
        foreach (["starts_on", "ends_on", "grace_ends_on"] as $key) {
            $date = is_string($data[$key])
            ? DateTimeImmutable::createFromFormat("!Y-m-d", $data[$key])
            : false;
            if ($date === false || $date->format("Y-m-d") !== $data[$key]) {
                throw new InvalidArgumentException(
                    "Valid policy dates are required.",
                );
            }
        }
        if ($data["starts_on"] > $data["ends_on"]) {
            throw new InvalidArgumentException(
                "The term dates must be ordered.",
            );
        }
        foreach (new DropWindows($data["windows"])->windows as $window) {
            if (
                $window["through"] !== null &&
                $window["through"] <= $data["grace_ends_on"]
            ) {
                throw new InvalidArgumentException(
                    "Grade periods must follow the drop/add deadline.",
                );
            }
        }
    }

    public function hash(): string
    {
        $ordered = [];
        foreach (self::FIELDS as $field) {
            $ordered[$field] =
            $field === "windows"
            ? new DropWindows($this->data[$field])->windows
            : $this->data[$field];
        }
        foreach (['section_id', 'section_code', 'uses_term_policy'] as $field) {
            if (array_key_exists($field, $this->data)) {
                $ordered[$field] = $this->data[$field];
            }
        }
        return hash("sha256", json_encode($ordered, JSON_THROW_ON_ERROR));
    }
}
