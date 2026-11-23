<?php

declare(strict_types=1);

namespace Domain\Admissions\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class DocumentRetentionBasis
{
    private function __construct(private array $data)
    {
    }

    public static function fromArray(array $data): self
    {
        if (
            count($data) !== 5 || !in_array($data['source'] ?? null, ['deployment', 'category'], true)
            || !array_key_exists('policy_id', $data) || !is_int($data['policy_revision'] ?? null)
            || !is_int($data['days'] ?? null) || $data['days'] < 1 || $data['days'] > 3650
            || !array_key_exists('category', $data) || $data['policy_revision'] < 0
            || $data['policy_revision'] > 2000000000
            || ($data['policy_id'] === null) !== ($data['policy_revision'] === 0)
        ) {
            throw new InvalidArgumentException('Use a complete retained document policy basis.');
        }
        if ($data['policy_id'] !== null) {
            if (!is_string($data['policy_id'])) {
                throw new InvalidArgumentException('Use a retained policy identity.');
            }
            new EntityId($data['policy_id']);
        }
        if ($data['source'] === 'category') {
            $category = $data['category'];
            if (!is_array($category) || count($category) !== 3 || $data['policy_id'] === null) {
                throw new InvalidArgumentException('Use a retained category and policy.');
            }
            new AdmissionRetentionDefinition([$category + ['days' => $data['days'], 'active' => true]]);
        } elseif ($data['category'] !== null) {
            throw new InvalidArgumentException('The deployment fallback has no category.');
        }
        return new self($data);
    }

    public function data(): array
    {
        return $this->data;
    }
}
