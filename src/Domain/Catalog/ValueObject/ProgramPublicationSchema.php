<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final class ProgramPublicationSchema
{
    public const array FIELDS = [
        "version_id",
        "previous_version_id",
        "academic_year_id",
        "academic_year_code",
        "catalog_revision",
        "previous_catalog_id",
        "definition",
        'specializations',
    ];

    /** @param array<mixed> $payload */
    public static function validate(array $payload): void
    {
        if (
            count($payload) !== count(self::FIELDS) ||
            array_diff(array_keys($payload), self::FIELDS) !== []
        ) {
            throw new InvalidArgumentException("Provide a complete program publication.");
        }
        $ids = ["version_id", "previous_version_id", "academic_year_id", "previous_catalog_id"];
        foreach ($ids as $field) {
            if (
                in_array($field, ["previous_version_id", "previous_catalog_id"], true) &&
                $payload[$field] === null
            ) {
                continue;
            }
            if (!is_string($payload[$field])) {
                throw new InvalidArgumentException("Use program publication identities.");
            }
            new EntityId($payload[$field]);
        }
        if (
            !is_string($payload["academic_year_code"]) ||
            preg_match('/^(\d{4})-(\d{4})$/D', $payload["academic_year_code"], $years) !== 1 ||
            (int) $years[2] !== (int) $years[1] + 1 ||
            !is_int($payload["catalog_revision"]) ||
            $payload["catalog_revision"] < 1 ||
            $payload["catalog_revision"] > 2000000000 ||
            $payload["version_id"] === $payload["previous_version_id"] ||
            $payload["version_id"] === $payload["previous_catalog_id"] ||
            !is_array($payload["definition"])
        ) {
            throw new InvalidArgumentException("Use a valid academic year, revision and predecessor.");
        }
        ProgramDefinition::fromArray($payload["definition"]);
        if (!is_array($payload['specializations'])) {
            throw new InvalidArgumentException('Provide the specialization publication.');
        }
        new ProgramSpecializationSnapshot($payload['specializations']);
        if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 49152) {
            throw new \Domain\Shared\Exception\ValidationException([
                ['field' => 'specializations',
                    'message' => 'Shorten program or specialization descriptions to fit one publication.'],
            ]);
        }
    }
}
