<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Catalog\Enum\ClassificationKind;
use InvalidArgumentException;

final readonly class SectionClassifications
{
    public function __construct(
        public ?ClassificationSnapshot $instructionalMethod = null,
        public ?ClassificationSnapshot $campus = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $values = [];
        if (count($data) !== 2) {
            throw new InvalidArgumentException(
                "Provide both section classification fields.",
            );
        }
        $kinds = [ClassificationKind::InstructionalMethod, ClassificationKind::Campus];
        foreach ($kinds as $kind) {
            if (!array_key_exists($kind->value, $data)) {
                throw new InvalidArgumentException(
                    "Provide both section classification fields.",
                );
            }
            $value = $data[$kind->value];
            if ($value !== null && !is_array($value)) {
                throw new InvalidArgumentException(
                    "Use a classification definition or an explicit blank.",
                );
            }
            $values[] =
            $value === null
            ? null
            : ClassificationSnapshot::fromArray($value);
        }
        return new self(...$values);
    }

    /** @return array<string, array{id: string, code: string, title: string}|null> */
    public function describe(): array
    {
        return [
            "instructional_method" => $this->instructionalMethod?->describe(),
            "campus" => $this->campus?->describe(),
        ];
    }
}
