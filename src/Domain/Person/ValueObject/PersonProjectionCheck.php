<?php

declare(strict_types=1);

namespace Domain\Person\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Value-free operator result; current comparisons do not certify archive completeness. */
final readonly class PersonProjectionCheck
{
    /** @param list<string> $issues
     * @param list<string> $unrecordedComponents */
    public function __construct(
        public string $status,
        public EntityId $person,
        public int $events,
        public ?int $position = null,
        public array $issues = [],
        public array $unrecordedComponents = [],
        public ?bool $earlierHistoryUnknown = null,
    ) {
        $complete = in_array($status, ['verified', 'history_gaps'], true);
        if (
            !in_array($status, ['verified', 'history_gaps', 'unverified', 'changed', 'limited'], true) ||
            $events < 0 || $events > 2000 || ($position !== null && $position < 0) ||
            !array_is_list($issues) || array_diff($issues, [
                'journal', 'identity', 'account_link', 'contact', 'addresses', 'emergency_contacts',
                'education', 'unavailable',
            ]) !== [] || !array_is_list($unrecordedComponents) ||
            array_diff($unrecordedComponents, ['identity', 'contact']) !== [] ||
            ($status === 'unverified' ? $issues === [] : $issues !== []) ||
            ($complete && ($position === null || $earlierHistoryUnknown === null)) ||
            (!$complete && ($unrecordedComponents !== [] || $earlierHistoryUnknown !== null)) ||
            ($status === 'verified' && ($position === 0 || $unrecordedComponents !== [] || $earlierHistoryUnknown)) ||
            ($status === 'history_gaps' && !$earlierHistoryUnknown && $unrecordedComponents === [])
        ) {
            throw new InvalidArgumentException('Use a bounded, consistent person-verification result.');
        }
    }

    /** @return array<string,mixed> */
    public function describe(): array
    {
        return ['status' => $this->status, 'scope' => 'person_profile', 'person_reference' => $this->person->value,
            'position' => $this->position, 'events_checked' => $this->events, 'issues' => $this->issues,
            'unrecorded_components' => $this->unrecordedComponents,
            'earlier_history_unknown' => $this->earlierHistoryUnknown, 'records_changed' => false];
    }
}
