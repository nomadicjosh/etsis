<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;

/** An explicit publication choice for one new determination, never a pursuit default. */
final readonly class EnrollmentLoadPolicySelection
{
    public function __construct(public string $source, public EntityId $versionId)
    {
        if (!in_array($source, ['global', 'program'], true)) {
            throw new ValidationException([
                ['field' => 'policy_selection', 'message' => 'Choose an institution or program publication.'],
            ]);
        }
    }

    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 2 ||
            array_diff(array_keys($data), ['source', 'version_id']) !== [] ||
            !is_string($data['source'] ?? null) ||
            !is_string($data['version_id'] ?? null)
        ) {
            throw new ValidationException([
                ['field' => 'policy_selection', 'message' => 'Provide the publication source and identifier.'],
            ]);
        }
        try {
            return new self($data['source'], new EntityId($data['version_id']));
        } catch (\InvalidArgumentException) {
            throw new ValidationException([
                ['field' => 'policy_selection', 'message' => 'Provide a valid publication identifier.'],
            ]);
        }
    }

    public function describe(): array
    {
        return ['source' => $this->source, 'version_id' => $this->versionId->value];
    }
}
