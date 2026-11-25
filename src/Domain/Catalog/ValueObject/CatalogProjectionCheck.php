<?php

declare(strict_types=1);

namespace Domain\Catalog\ValueObject;

use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Value-free observation, never a publication, checkpoint, repair or academic certification. */
final readonly class CatalogProjectionCheck
{
    public function __construct(
        public string $kind,
        public EntityId $subject,
        public string $status,
        public int $events,
        public ?int $publications = null,
        public array $issues = [],
    ) {
        if (
            !in_array($kind, ['course', 'section', 'program', 'group', 'curriculum'], true)
            || !in_array($status, ['verified', 'observed_empty', 'unverified', 'changed', 'limited'], true)
            || $events < 0 || $events > 2000
            || ($status === 'verified' ? $publications === null || $publications < 1 || $publications > $events
                : $publications !== null)
            || !array_is_list($issues)
            || array_diff($issues, ['publication', 'course_reference', 'course_binding', 'offering_selection',
                'catalog_identity', 'unavailable']) !== []
            || ($status === 'unverified' ? $issues === [] : $issues !== [])
        ) {
            throw new InvalidArgumentException('Use a bounded, consistent catalog observation.');
        }
    }

    public function describe(): array
    {
        return ['status' => $this->status, 'scope' => $this->kind . '_publication',
            $this->kind . '_reference' => $this->subject->value, 'events_checked' => $this->events,
            'records_checked' => $this->publications, 'issues' => $this->issues, 'records_changed' => false];
    }
}
