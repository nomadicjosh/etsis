<?php

declare(strict_types=1);

namespace Domain\Admissions;

use Domain\Admissions\ValueObject\CaseworkChecklist;
use Domain\Shared\ValueObject\EntityId;

/** Mutable setup; applying it copies values so later preset edits cannot rewrite application casework. */
final readonly class AdmissionChecklistPreset
{
    /** @param list<array<string,mixed>> $items */
    private function __construct(
        public EntityId $id,
        public string $name,
        public ?EntityId $program,
        public bool $enabled,
        public array $items,
        public int $version,
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(EntityId $id, #[\SensitiveParameter] array $input, int $version): self
    {
        if (
            array_diff(array_keys($input), ['name', 'program_id', 'enabled', 'items']) !== []
            || !is_bool($input['enabled'] ?? null) || !is_array($input['items'] ?? null) || $version < 1
        ) {
            CaseworkChecklist::invalid('preset', 'Review the checklist preset fields.');
        }
        $program = $input['program_id'] ?? null;
        if ($program !== null && !is_string($program)) {
            CaseworkChecklist::invalid('program_id', 'Choose a program or all programs.');
        }
        return new self(
            $id,
            CaseworkChecklist::text($input['name'] ?? '', 'name', 191, true),
            $program === null ? null : new EntityId($program),
            $input['enabled'],
            CaseworkChecklist::items($input['items'], true),
            $version
        );
    }

    /** @return array<string,mixed> */
    public function values(): array
    {
        return ['name' => $this->name, 'program_id' => $this->program?->value,
            'enabled' => $this->enabled, 'items' => $this->items];
    }
    public function __debugInfo(): array
    {
        return ['preset' => '[protected checklist definition]'];
    }
}
