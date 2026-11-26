<?php

declare(strict_types=1);

namespace Domain\Student\ValueObject;

use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\ValueObject\EntityId;

/** Original selected load evidence, protected with the standing decision. */
final readonly class StandingLoadAssessment implements \JsonSerializable
{
    public LoadDeterminationSnapshot $snapshot;

    public function __construct(#[\SensitiveParameter] public array $data)
    {
        try {
            if (
                count($data) !== 2 || array_diff(array_keys($data), ['record_id', 'snapshot']) !== []
                || !is_string($data['record_id'] ?? null)
                || new EntityId($data['record_id'])->value !== $data['record_id']
            ) {
                throw new \InvalidArgumentException();
            }
            $this->snapshot = new LoadDeterminationSnapshot($data['snapshot']);
        } catch (\Throwable) {
            throw new CorruptHistoryException();
        }
    }

    public function validateFor(string $student, string $pursuit, string $asOf, ?string $term, array $requirement): void
    {
        $d = $this->snapshot->data;
        if (
            $d['student_id'] !== $student || $d['pursuit_id'] !== $pursuit || $d['as_of_date'] > $asOf
            || ($term !== null && $d['term']['id'] !== $term)
            || !new StandingLoadRequirement($requirement)->accepts($this->snapshot)
        ) {
            throw new CorruptHistoryException();
        }
    }

    public function summary(): array
    {
        return ['record_id' => $this->data['record_id'], 'determined_on' => $this->snapshot->data['as_of_date'],
            'term_code' => $this->snapshot->data['term']['code'], 'credits_milli' => $this->snapshot->creditsMilli,
            'classification' => $this->snapshot->classification === null ? null : array_intersect_key(
                $this->snapshot->classification,
                array_flip(['code', 'label'])
            )];
    }

    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    public function __debugInfo(): array
    {
        return ['standing_load' => '[protected retained load assessment]'];
    }
}
