<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Person\Event\PersonProfileWasRecorded;
use Domain\Person\Person;
use Domain\Person\ProfileHistory;
use Domain\Person\ValueObject\PersonProfileId;
use Domain\Person\ValueObject\ProfileFacts;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** Internal: authorized entry points own person locks, projection writes, audits and the enclosing transaction. */
final readonly class ProfileLedger
{
    public function __construct(private HistoricalEventStore $history, private Clock $clock)
    {
    }
    public function accountContext(EntityId $actor, EntityId $operation): EventContext
    {
        return new EventContext($actor, $this->clock->now(), $operation);
    }
    /** @param array<array-key,mixed> $values */
    public static function intent(#[\SensitiveParameter] array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
    public static function operation(EntityId $person, EntityId $actor, string $intent): EntityId
    {
        return DerivedIdentity::from($person, 'person.profile:' . $actor->value . ':' . $intent);
    }
    public function verify(Person $person): int
    {
        $root = $this->load($person->id)['root'];
        if ($root->person() !== null && ProfileFacts::of($root->person()) !== ProfileFacts::of($person)) {
            throw new CorruptHistoryException();
        }
        return $root->playhead();
    }
    public function receipt(EntityId $person, EntityId $operation, EntityId $actor, string $intent): ?Person
    {
        return $this->load($person, $operation, $actor, $intent)['receipt'];
    }
    public function create(Person $person, EntityId $operation, EntityId $actor, string $intent): void
    {
        $root = $this->load($person->id)['root'];
        if ($root->playhead() !== 0) {
            throw new CorruptHistoryException();
        }
        $this->append($root, $person, $operation, $actor, $intent, 'created');
    }
    public function record(Person $before, Person $after, EntityId $operation, EntityId $actor, string $intent): void
    {
        $root = $this->load($before->id)['root'];
        if (
            $before->id->value !== $after->id->value || ($root->person() !== null &&
            ProfileFacts::of($root->person()) !== ProfileFacts::of($before))
        ) {
            throw new CorruptHistoryException();
        }
        if (ProfileFacts::of($before) === ProfileFacts::of($after)) {
            return;
        }
        if ($root->playhead() === 0) {
            $baseline = DerivedIdentity::from($operation, 'person.observation');
            $this->append($root, $before, $baseline, $actor, $intent, 'observed');
        }
        $this->append($root, $after, $operation, $actor, $intent, 'changed');
    }
    private function append(
        ProfileHistory $root,
        Person $p,
        EntityId $operation,
        EntityId $actor,
        string $intent,
        string $provenance
    ): void {
        $version = $root->playhead();
        $e = PersonProfileWasRecorded::occur(
            $root->aggregateId(),
            ['operation_id' => $operation->value,
            'previous_operation_id' => $root->operation(), 'provenance' => $provenance,
            'facts' => ProfileFacts::of($p),
                'intent' => $intent],
            ['__aggregate_type' => 'person_profile']
        )->withPlayhead($version + 1);
        $root->acceptHistorical($e);
        $this->history->append(
            new StreamReference($root->aggregateId(), 'person_profile'),
            $operation,
            $version,
            new EventContext(
                $actor,
                $this->clock->now(),
                $operation,
                reason: $provenance === 'observed'
                    ? 'Current profile observed; earlier personal history is unknown.'
                    : null
            ),
            [$e]
        );
    }
    /** @return array{root:ProfileHistory,receipt:?Person} */
    private function load(
        EntityId $id,
        ?EntityId $operation = null,
        ?EntityId $actor = null,
        ?string $intent = null
    ): array {
        $root = ProfileHistory::root(new PersonProfileId($id->value));
        $receipt = null;
        $cursor = null;
        $count = 0;
        do {
            $page = $this->history->page(new StreamReference($root->aggregateId(), 'person_profile'), $cursor, 20);
            foreach ($page->events as $stored) {
                if (++$count > 2000) {
                    throw new HistoryReadLimitException();
                }
                $root->acceptHistorical($stored->event);
                $p = $stored->event->payload();
                if (
                    $stored->context->actor === null || $stored->context->effectiveAt === null ||
                    $stored->context->correlation?->value !== $p['operation_id']
                ) {
                    throw new CorruptHistoryException();
                }
                if ($p['operation_id'] === $operation?->value) {
                    if ($stored->context->actor->value !== $actor?->value || $p['intent'] !== $intent) {
                        throw new AccessDeniedException('This profile operation has another actor or intent.');
                    }
                    $receipt = $root->person();
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        return ['root' => $root, 'receipt' => $receipt];
    }
}
