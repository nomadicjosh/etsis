<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use Domain\Shared\ValueObject\EntityId;

/** No number, mask, reason, hash, ciphertext, review proof or current identity label. */
final readonly class PersonSsnProjectionCheck
{
    public function __construct(
        public string $status,
        public EntityId $person,
        public int $events,
        public ?int $position = null,
        public array $issues = [],
    ) {
        $complete = in_array($status, ['verified', 'observed_empty'], true);
        if (
            !in_array($status, ['verified', 'observed_empty', 'unverified', 'changed', 'limited'], true)
            || $events < 0 || $events > 500 || !array_is_list($issues)
            || array_diff($issues, ['identity', 'journal', 'ssn_projection', 'unavailable']) !== []
            || ($status === 'unverified' ? $issues === [] : $issues !== [])
            || ($complete ? $position === null : $position !== null)
            || ($status === 'verified' && ($position < 1 || $position !== $events))
            || ($status === 'observed_empty' && ($position !== 0 || $events !== 0))
        ) {
            throw new \InvalidArgumentException('Use a bounded, value-free SSN verification result.');
        }
    }

    public function describe(): array
    {
        return ['status' => $this->status, 'scope' => 'person_ssn', 'person_reference' => $this->person->value,
            'events_checked' => $this->events, 'position' => $this->position,
            'issues' => $this->issues, 'records_changed' => false];
    }
}
