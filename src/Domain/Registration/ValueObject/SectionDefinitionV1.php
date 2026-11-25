<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** One current definition; optional later fields remain absent in earlier retained publications. */
final readonly class SectionDefinitionV1
{
    public const array FIELDS = [
        "course_id",
        "course_version_id",
        "term_id",
        "term_code",
        "term_starts_on",
        "term_ends_on",
        "code",
        "capacity",
        "faculty_user_id",
        "faculty_person_id",
        "delivery_mode",
        "fee_minor",
        "currency",
        "instruction_starts_on",
        "instruction_ends_on",
    ];

    /** @param array<string, mixed> $data */
    public function __construct(public array $data)
    {
        if (
            count($data) < count(self::FIELDS) || count($data) > count(self::FIELDS) + 7 ||
            array_diff(array_keys($data), [...self::FIELDS, 'web_registration', 'minimum_enrollment',
                'contact_hours', 'credits_milli', 'continuing_education', 'eligible_program_ids',
                'require_program_campus']) !== [] ||
            array_diff(self::FIELDS, array_keys($data)) !== [] ||
            (array_key_exists('web_registration', $data) && !is_bool($data['web_registration']))
        ) {
            throw new InvalidArgumentException(
                "A complete section definition is required.",
            );
        }
        if (array_key_exists('eligible_program_ids', $data)) {
            if (!is_array($data['eligible_program_ids'])) {
                throw new InvalidArgumentException('Use the complete section program eligibility policy.');
            }
            new SectionProgramPolicy($data['eligible_program_ids']);
        }
        if (array_key_exists('require_program_campus', $data) && !is_bool($data['require_program_campus'])) {
            throw new InvalidArgumentException('Retain an explicit program campus eligibility choice.');
        }
        if (
            array_key_exists('credits_milli', $data) && (!is_int($data['credits_milli'])
            || $data['credits_milli'] < 0 || $data['credits_milli'] > 999999)
        ) {
            throw new InvalidArgumentException('Use retained academic-credit thousandths.');
        }
        if (
            array_key_exists('minimum_enrollment', $data) &&
            (!is_int($data['minimum_enrollment']) || $data['minimum_enrollment'] < 0 ||
                $data['minimum_enrollment'] > $data['capacity'])
        ) {
            throw new InvalidArgumentException('Use an enrollment target from zero through section capacity.');
        }
        if (isset($data['contact_hours'])) {
            if (!is_array($data['contact_hours'])) {
                throw new InvalidArgumentException('Use a retained contact-hours calculation.');
            }
            new ContactHours($data['contact_hours']);
        }
        foreach (["course_id", "course_version_id", "term_id"] as $field) {
            if (
                !is_string($data[$field]) ||
                new EntityId($data[$field])->value !== $data[$field]
            ) {
                throw new InvalidArgumentException(
                    "Valid retained identities are required.",
                );
            }
        }
        if (($data["faculty_user_id"] === null) !== ($data["faculty_person_id"] === null)) {
            throw new InvalidArgumentException("Retain the person and account together for a teaching assignment.");
        }
        if ($data["faculty_person_id"] !== null) {
            if (!is_string($data["faculty_person_id"])) {
                throw new InvalidArgumentException("A valid faculty person reference is required.");
            }
            new EntityId($data["faculty_person_id"]);
        }
        if ($data["faculty_user_id"] !== null) {
            if (
                !is_string($data["faculty_user_id"]) ||
                new EntityId($data["faculty_user_id"])->value !==
                    $data["faculty_user_id"]
            ) {
                throw new InvalidArgumentException(
                    "A valid teaching account reference is required.",
                );
            }
        }
        foreach (["code", "term_code"] as $field) {
            if (
                !is_string($data[$field]) ||
                trim($data[$field]) === "" ||
                strlen($data[$field]) > 80 ||
                !mb_check_encoding($data[$field], "UTF-8") ||
                preg_match('/[\x00-\x1F\x7F]/', $data[$field])
            ) {
                throw new InvalidArgumentException(
                    "A valid section and term code are required.",
                );
            }
        }
        if (
            !is_int($data["capacity"]) ||
            $data["capacity"] < 1 ||
            $data["capacity"] > 100000 ||
            !is_int($data["fee_minor"]) ||
            $data["fee_minor"] < 0 ||
            $data["fee_minor"] > 2000000000 ||
            !is_string($data["currency"]) ||
            preg_match('/^[A-Z]{3}$/D', $data["currency"]) !== 1 ||
            !in_array(
                $data["delivery_mode"],
                ["unspecified", "in_person", "online", "hybrid"],
                true,
            )
        ) {
            throw new InvalidArgumentException(
                "Valid capacity, delivery and fee values are required.",
            );
        }
        if (array_key_exists('continuing_education', $data)) {
            if (!is_array($data['continuing_education'])) {
                throw new InvalidArgumentException('Use the complete offering CEU definition.');
            }
            \Domain\Catalog\ValueObject\ContinuingEducationUnits::fromArray($data['continuing_education']);
        }
        $dateFields = ["term_starts_on", "term_ends_on", "instruction_starts_on", "instruction_ends_on"];
        foreach ($dateFields as $field) {
            self::assertDate($data[$field]);
        }
        if (
            $data["term_ends_on"] < $data["term_starts_on"] ||
            $data["instruction_ends_on"] < $data["instruction_starts_on"]
        ) {
            throw new InvalidArgumentException(
                "The end date must be on or after the start date.",
            );
        }
    }

    public static function assertDate(mixed $value): void
    {
        if (
            !is_string($value) ||
            preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) !== 1 ||
            !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
        ) {
            throw new InvalidArgumentException(
                "Use a valid calendar date in YYYY-MM-DD format.",
            );
        }
    }
}
