<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class HiatusImpact
{
    /** @var list<EntityId> */
    public array $sections;

    /** @param array<string, mixed> $result Server-calculated outcomes, never taken from the HTTP body. */
    public function __construct(
        public bool $deactivate,
        public string $revision,
        public array $result,
        EntityId ...$sections
    ) {
        if (count($sections) > 100 || preg_match('/^[a-f0-9]{64}$/D', $revision) !== 1) {
            throw new ValidationException([['field' => 'sections', 'message' => 'Review up to 100 course changes.']]);
        }
        $ids = [];
        foreach ($sections as $id) {
            if (isset($ids[$id->value])) {
                throw new ValidationException([['field' => 'sections',
                    'message' => 'Choose distinct course sections.']]);
            }
            $ids[$id->value] = $id;
        }
        ksort($ids, SORT_STRING);
        $this->sections = array_values($ids);
    }

    /** @return array<string, mixed> */
    public function request(): array
    {
        return ['section_ids' => array_map(static fn (EntityId $id): string => $id->value, $this->sections),
            'deactivate' => $this->deactivate, 'revision' => $this->revision];
    }
}
