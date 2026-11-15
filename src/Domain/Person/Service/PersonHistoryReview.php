<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use DateTimeZone;
use Domain\Audit\Repository\ActorDirectory;
use Domain\Person\Event\PersonContactWasRecorded;
use Domain\Person\Event\PersonEmergencyContactsWereRecorded;
use Domain\Person\ProfileHistory;
use Domain\Person\Person;
use Domain\Person\ValueObject\PersonProfileId;
use Domain\Person\ValueObject\ProfileFacts;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Authorized, value-free summaries. Never a raw journal or credential reader. */
final readonly class PersonHistoryReview
{
    private const int PAGE_SIZE = 20;
    private const array PROFILE_FIELDS = ['given_name' => 'Given name', 'family_name' => 'Family name',
        'institutional_id' => 'Institutional ID', 'directory_restricted' => 'Directory privacy'];
    private const array CONTACT_FIELDS = ['preferred_name' => 'Preferred name', 'phone' => 'Phone number',
        'address' => 'Mailing address'];

    public function __construct(
        private HistoricalEventStore $history,
        private PersonProfileAccess $authorization,
        private StudentAccess $access,
        private ActorDirectory $actors,
    ) {
    }

    /** @return array{items:list<array<string,mixed>>,through_version:int,next_after:?int,earlier_history_unknown:bool} */
    public function page(EntityId $person, int $after = 0, ?int $throughVersion = null): array
    {
        if (
            $after < 0 || $after > ProfileLedger::MAX_EVENTS || $after % self::PAGE_SIZE !== 0
            || ($after !== 0 && $throughVersion === null) || ($throughVersion !== null && $throughVersion < 0)
        ) {
            throw new InvalidArgumentException('Refresh the profile history.');
        }
        $actor = $this->authorization->actor();
        $this->authorize($actor, $person);
        $root = ProfileHistory::root(new PersonProfileId($person->value));
        $stream = new StreamReference($root->aggregateId(), 'person_profile');
        $cursor = null;
        $through = null;
        $items = [];
        $actors = [];
        $unknown = true;
        $count = 0;
        do {
            $page = $this->history->page($stream, $cursor, 100);
            if ($page->throughVersion > ProfileLedger::MAX_EVENTS) {
                throw new HistoryReadLimitException();
            }
            if ($through === null) {
                $through = $throughVersion ?? $page->throughVersion;
                if (
                    $through > $page->throughVersion || ($through !== 0 && $after >= $through)
                    || ($through === 0 && $after !== 0)
                ) {
                    throw new InvalidArgumentException('Refresh the profile history.');
                }
            }
            foreach ($page->events as $stored) {
                if (++$count > ProfileLedger::MAX_EVENTS) {
                    throw new HistoryReadLimitException();
                }
                $sequence = $stored->event->playhead();
                $selected = $sequence <= $through - $after
                && $sequence > $through - $after - self::PAGE_SIZE;
                $before = $selected ? $this->facts($root, $stored) : [];
                $root->acceptRecorded($stored);
                $payload = $stored->event->payload();
                if ($sequence === 1 && $sequence <= $through) {
                    $unknown = $payload['provenance'] === 'observed';
                }
                if (!$selected) {
                    continue;
                }
                $item = $this->summary($root, $stored, $before);
                $recordedBy = $stored->context->actor
                ?? throw new AccessDeniedException('Missing profile attribution.');
                $actors[$recordedBy->value] = $recordedBy;
                $items[] = [$recordedBy->value, $item];
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->playhead() !== $page->throughVersion) {
            throw new \Domain\Shared\EventSourcing\CorruptHistoryException();
        }
        $names = $this->actors->names(array_values($actors));
        $result = [];
        foreach (array_reverse($items) as [$id, $item]) {
            $item['recorded_by'] = $names[$id] ?? 'Unavailable account';
            $result[] = $item;
        }
        $root->verifyPerson($this->authorize($actor, $person));
        return ['items' => $result, 'through_version' => $through,
            'next_after' => $through > $after + self::PAGE_SIZE ? $after + self::PAGE_SIZE : null,
            'earlier_history_unknown' => $unknown];
    }

    private function authorize(EntityId $actor, EntityId $person): Person
    {
        $record = $this->authorization->person($actor, $person, true);
        if ($this->access->requirePermission('sis:audit:read')->value !== $actor->value) {
            throw new AccessDeniedException('The history reviewer changed.');
        }
        return $record;
    }
    /** @return array<string,mixed> */
    private function facts(ProfileHistory $root, StoredEvent $stored): array
    {
        return match (true) {
            $stored->event instanceof PersonContactWasRecorded => $root->contact()?->facts() ?? [],
            $stored->event instanceof PersonEmergencyContactsWereRecorded => $root->emergency()?->facts() ?? [],
            default => $root->person() === null ? [] : ProfileFacts::of($root->person()),
        };
    }
    /** @param array<string,mixed> $before
     * @return array<string,mixed>
     */
    private function summary(ProfileHistory $root, StoredEvent $stored, #[\SensitiveParameter] array $before): array
    {
        $payload = $stored->event->payload();
        $provenance = $payload['provenance'] ?? 'changed';
        $observed = $provenance === 'observed';
        $category = match (true) {
            $stored->event instanceof PersonContactWasRecorded => 'Contact information',
            $stored->event instanceof PersonEmergencyContactsWereRecorded => 'Emergency contacts',
            default => 'Person profile',
        };
        $after = $this->facts($root, $stored);
        $labels = $stored->event instanceof PersonContactWasRecorded ? self::CONTACT_FIELDS : self::PROFILE_FIELDS;
        $fields = [];
        if (!$observed) {
            if ($stored->event instanceof PersonEmergencyContactsWereRecorded) {
                $fields[] = 'Emergency contact details or priority order';
            } else {
                foreach ($labels as $field => $label) {
                    if (!array_key_exists($field, $before) || $before[$field] !== $after[$field]) {
                        $fields[] = $label;
                    }
                }
            }
        }
        return ['sequence' => $stored->event->playhead(), 'category' => $category,
            'action' => $observed ? 'Existing details first recorded'
                : ($provenance === 'created' ? 'Created' : 'Updated'),
            'fields' => $fields, 'earlier_details_unknown' => $observed,
            'recorded_at' => $stored->recordedAt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z'),
            'effective_at' => $stored->context->effectiveAt?->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s.u\Z')];
    }
}
