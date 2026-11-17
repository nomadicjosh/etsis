<?php

declare(strict_types=1);

namespace Domain\Admissions;

use Domain\Admissions\ValueObject\CaseworkChecklist;
use Domain\Shared\ValueObject\EntityId;

/** Current working case data; it does not change an application's formal status or admission requirements. */
final readonly class AdmissionCasework
{
    public const array FIELDS = ['office', 'work_status', 'follow_up_on', 'private_notes', 'next_step', 'items'];

    /** @param array<string,mixed> $values */
    private function __construct(
        public EntityId $application,
        public ?EntityId $assignedTo,
        public int $version,
        public array $values,
    ) {
    }

    /** @param array<string,mixed> $input */
    public static function fromInput(
        EntityId $application,
        #[\SensitiveParameter] array $input,
        ?EntityId $assignedTo,
        int $version
    ): self {
        if ($version < 0 || array_diff(array_keys($input), self::FIELDS) !== []) {
            CaseworkChecklist::invalid('version', 'Reload the application casework.');
        }
        $state = $input['work_status'] ?? 'open';
        if (!in_array($state, ['open', 'waiting', 'done'], true) || !is_array($input['items'] ?? [])) {
            CaseworkChecklist::invalid('work_status', 'Choose an available casework status.');
        }
        $items = CaseworkChecklist::items($input['items'] ?? []);
        if ($state === 'done') {
            foreach ($items as $item) {
                if ($item['needed'] && in_array($item['status'], ['pending', 'received'], true)) {
                    CaseworkChecklist::invalid('work_status', 'Finish needed items before marking casework done.');
                }
            }
        }
        return new self($application, $assignedTo, $version, [
            'office' => CaseworkChecklist::text($input['office'] ?? '', 'office', 191),
            'work_status' => $state,
            'follow_up_on' => CaseworkChecklist::date($input['follow_up_on'] ?? '', 'follow_up_on'),
            'private_notes' => CaseworkChecklist::text($input['private_notes'] ?? '', 'private_notes', 5000),
            'next_step' => CaseworkChecklist::text($input['next_step'] ?? '', 'next_step', 2000),
            'items' => $items,
        ]);
    }

    public function nextDueOn(): ?string
    {
        if ($this->values['work_status'] === 'done') {
            return null;
        }
        $dates = [$this->values['follow_up_on']];
        foreach ($this->values['items'] as $item) {
            if (in_array($item['status'], ['pending', 'received'], true)) {
                $dates[] = $item['due_on'];
            }
        }
        $dates = array_values(array_filter($dates, static fn (string $value): bool => $value !== ''));
        return $dates === [] ? null : min($dates);
    }

    /** @return array<string,mixed> */
    public function facts(): array
    {
        return ['application_id' => $this->application->value, 'assigned_to' => $this->assignedTo?->value,
            'version' => $this->version] + $this->values;
    }

    /** Curated owner view: staff-only tasks, notes, routing identities and revision metadata stay private.
     * @return array<string,mixed> */
    public function applicantView(): array
    {
        $items = [];
        $fields = array_flip(['label', 'instructions', 'needed', 'status', 'due_on']);
        foreach ($this->values['items'] as $item) {
            if ($item['visible']) {
                $items[] = array_intersect_key($item, $fields);
            }
        }
        return ['office' => $this->values['office'], 'next_step' => $this->values['next_step'], 'items' => $items];
    }

    /** @return array<string,string> */
    public function __debugInfo(): array
    {
        return ['casework' => '[protected application casework]'];
    }
}
