<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Catalog\ValueObject\ClassificationSnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Original receiving program and, only when needed, its actual retained cohort classification. */
final readonly class StandingProposalScope implements \JsonSerializable
{
    public function __construct(#[\SensitiveParameter] public array $data)
    {
        try {
            if (
                count($data) !== 3
                || array_diff(array_keys($data), ['program_id', 'pursuit_version', 'level_source']) !== []
                || !is_string($data['program_id']) || new EntityId($data['program_id'])->value !== $data['program_id']
                || !is_int($data['pursuit_version']) || $data['pursuit_version'] < 0
                || $data['pursuit_version'] > 10000
            ) {
                throw new \InvalidArgumentException();
            }
            $source = $data['level_source'];
            if ($source !== null) {
                if (
                    $data['pursuit_version'] < 1 || !is_array($source) || count($source) !== 3
                    || array_diff(
                        array_keys($source),
                        ['academic_year_id', 'program_version_id', 'academic_level']
                    ) !== []
                ) {
                    throw new \InvalidArgumentException();
                }
                foreach (['academic_year_id', 'program_version_id'] as $key) {
                    if (!is_string($source[$key]) || new EntityId($source[$key])->value !== $source[$key]) {
                        throw new \InvalidArgumentException();
                    }
                }
                if ($source['academic_level'] !== null) {
                    ClassificationSnapshot::fromArray($source['academic_level']);
                }
            }
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function levelId(): ?string
    {
        return $this->data['level_source']['academic_level']['id'] ?? null;
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ['scope' => '[protected standing policy scope]'];
    }
}
