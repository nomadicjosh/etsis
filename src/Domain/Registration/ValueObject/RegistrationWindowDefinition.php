<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Registration\RegistrationWindow;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;

final readonly class RegistrationWindowDefinition
{
    public const array FIELDS = [
        "term_id",
        "term_code",
        "opens_at",
        "closes_at",
        "course_limit",
        "timezone",
    ];
    public function __construct(public array $data)
    {
        if (
            count($data) !== 6 ||
            array_diff(array_keys($data), self::FIELDS) !== [] ||
            !is_string($data["term_id"]) ||
            !is_string($data["term_code"]) ||
            trim($data["term_code"]) === "" ||
            mb_strlen($data["term_code"]) > 80 ||
            !mb_check_encoding($data["term_code"], "UTF-8") ||
            preg_match('/[\x00-\x1F\x7F]/', $data["term_code"]) ||
            !is_string($data["timezone"]) ||
            !is_int($data["course_limit"])
        ) {
            throw new \InvalidArgumentException(
                "Provide complete registration-window details.",
            );
        }
        new EntityId($data["term_id"]);
        new DateTimeZone($data["timezone"]);
        foreach (["opens_at", "closes_at"] as $key) {
            EnrollmentFacts::timestamp($data[$key]);
        }
        $this->window();
    }
    public function window(?array $reference = null): RegistrationWindow
    {
        return new RegistrationWindow(
            new DateRange(
                new DateTimeImmutable(
                    $this->data["opens_at"],
                    new DateTimeZone("UTC"),
                ),
                new DateTimeImmutable(
                    $this->data["closes_at"],
                    new DateTimeZone("UTC"),
                ),
            ),
            $this->data["course_limit"],
            $reference,
        );
    }
    public function fingerprint(): string
    {
        $data = [];
        foreach (self::FIELDS as $field) {
            $data[$field] = $this->data[$field];
        }
        return hash("sha256", json_encode($data, JSON_THROW_ON_ERROR));
    }
    public static function reference(mixed $ref): void
    {
        if (
            !is_array($ref) ||
            count($ref) !== 4 ||
            array_diff(array_keys($ref), [
                "term_id",
                "publication_id",
                "version",
                "definition_hash",
            ]) !== [] ||
            !is_int($ref["version"] ?? null) ||
            $ref["version"] < 1 ||
            $ref["version"] > 1000 ||
            !is_string($ref["definition_hash"] ?? null) ||
            !preg_match('/^[a-f0-9]{64}$/D', $ref["definition_hash"])
        ) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        foreach (["term_id", "publication_id"] as $key) {
            if (!is_string($ref[$key] ?? null)) {
                throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
            }
            new EntityId($ref[$key]);
        }
    }
}
