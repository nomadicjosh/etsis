<?php

declare(strict_types=1);

namespace Domain\Transcript\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

final readonly class TranscriptReleaseConfirmation
{
    /** @var list<string> */
    public array $checks;

    /** @param list<string> $checks */
    public function __construct(public int $revision, array $checks)
    {
        if (
            $revision < 0 || $revision > 2000000000 || !array_is_list($checks) || count($checks) > 12 ||
            !array_all($checks, static fn(mixed $id): bool => is_string($id)) ||
            count(array_unique($checks)) !== count($checks)
        ) {
            throw new ValidationException([['field' => 'release_checks',
                'message' => 'Review the release checks again.']]);
        }
        foreach ($checks as $id) {
            new EntityId($id);
        }
        sort($checks, SORT_STRING);
        $this->checks = $checks;
    }

    /** @return array{revision:int,checks:list<string>} */
    public function describe(): array
    {
        return ['revision' => $this->revision, 'checks' => $this->checks];
    }

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        if (count($data) !== 2 || !is_int($data['revision'] ?? null) || !is_array($data['checks'] ?? null)) {
            throw new ValidationException([['field' => 'release_checks',
                'message' => 'Review the release checks again.']]);
        }
        return new self($data['revision'], $data['checks']);
    }
}
