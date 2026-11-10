<?php

declare(strict_types=1);

namespace Domain\Registration\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class PublishedSectionDefinition
{
    public function __construct(
        public EntityId $section,
        public EntityId $publication,
        public int $position,
        public SectionDefinitionV1 $definition,
    ) {
        if ($position < 1 || $position > 2147483646) {
            throw new InvalidArgumentException('A valid publication position is required.');
        }
    }

    /** @return array<string, mixed> */
    public function describe(): array
    {
        return ['section_id' => $this->section->value, 'version_id' => $this->publication->value,
            'version' => $this->position, 'definition' => $this->definition->data];
    }
}
