<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Frozen approval used at acceptance; later revocation never rewrites an accepted registration. */
final readonly class PrerequisitePermissionReference
{
    public const array FIELDS = [
        "stream_id",
        "operation_id",
        "position",
        "student_id",
        "pursuit_id",
        "section_id",
        "term_id",
        "course_version_id",
        "expires_at",
    ];

    public function __construct(public array $data)
    {
        try {
            if (
                count($data) !== count(self::FIELDS) ||
                array_diff(array_keys($data), self::FIELDS) !== [] ||
                !is_int($data["position"]) ||
                $data["position"] < 1 ||
                $data["position"] > 2147483646
            ) {
                throw new CorruptHistoryException();
            }
            foreach (array_diff(self::FIELDS, ["position", "expires_at"]) as $key) {
                if (!is_string($data[$key])) {
                    throw new CorruptHistoryException();
                }
                new EntityId($data[$key]);
            }
            if (
                !is_string($data["expires_at"]) ||
                \DateTimeImmutable::createFromFormat(
                    "!Y-m-d\TH:i:s\Z",
                    $data["expires_at"],
                    new \DateTimeZone("UTC"),
                )?->format("Y-m-d\TH:i:s\Z") !== $data["expires_at"]
            ) {
                throw new CorruptHistoryException();
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }
}
