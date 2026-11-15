<?php

declare(strict_types=1);

namespace Domain\HumanResources\Service;

use Closure;
use DateTimeZone;
use Domain\HumanResources\Employee;
use Domain\HumanResources\EmploymentHistory;
use Domain\HumanResources\Event\EmploymentProfileWasRecorded;
use Domain\HumanResources\ValueObject\EmploymentFacts;
use Domain\HumanResources\ValueObject\EmploymentProfileId;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DerivedIdentity;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Internal: authorized services own role/person/employee locks, projection and audit transactions. */
final readonly class EmploymentLedger
{
    public const int MAX_EVENTS = 2000;
    public const int REVIEW_SIZE = 20;
    public function __construct(private HistoricalEventStore $history, private Clock $clock)
    {
    }
    /** @param array<mixed> $values */
    public static function intent(#[\SensitiveParameter] array $values): string
    {
        return hash('sha256', json_encode($values, JSON_THROW_ON_ERROR));
    }
    public static function operation(EntityId $employee, EntityId $actor, string $intent): EntityId
    {
        return DerivedIdentity::from($employee, 'employment.profile:' . $actor->value . ':' . $intent);
    }
    /** @return array{revision:string,earlier_unknown:bool} */
    public function inspect(Employee $employee): array
    {
        $root = $this->load($employee->id)['root'];
        $root->verify($employee);
        return ['revision' => self::intent([EmploymentFacts::of($employee), $root->playhead()]),
            'earlier_unknown' => $root->earlierUnknown()];
    }
    public function receipt(EntityId $employee, EntityId $operation, EntityId $actor, string $intent): bool
    {
        return $this->load($employee, $operation, $actor, $intent)['receipt'];
    }

    /** Internal curated HR facts. The authorized service resolves current recorder names and removes account IDs.
     * @return array{items:list<array<string,mixed>>,through_version:int,next_after:?int,earlier_history_unknown:bool}
     */
    public function review(Employee $employee, int $after = 0, ?int $throughVersion = null): array
    {
        if (
            $after < 0 || $after > self::MAX_EVENTS || $after % self::REVIEW_SIZE !== 0
            || ($after !== 0 && $throughVersion === null)
            || ($throughVersion !== null && ($throughVersion < 0 || $throughVersion > self::MAX_EVENTS))
        ) {
            throw new InvalidArgumentException('Refresh employment history.');
        }
        $through = $throughVersion;
        $items = [];
        $previous = [];
        $unknown = true;
        $utc = new DateTimeZone('UTC');
        $root = $this->load($employee->id, observe: static function (
            StoredEvent $stored,
            int $head
        ) use (
            &$through,
            &$items,
            &$previous,
            &$unknown,
            $after,
            $utc
        ): void {
            $through ??= $head;
            $sequence = $stored->event->playhead();
            $payload = $stored->event->payload();
            $facts = $payload['facts'];
            if ($sequence === 1 && $sequence <= $through) {
                $unknown = $payload['provenance'] === 'observed';
            }
            if ($sequence <= $through - $after && $sequence > $through - $after - self::REVIEW_SIZE) {
                $fields = [];
                foreach (['job_title' => 'Job title', 'active' => 'Active status'] as $key => $label) {
                    if (($previous[$key] ?? null) !== $facts[$key] && $payload['provenance'] !== 'observed') {
                        $fields[] = $label;
                    }
                }
                $items[] = ['sequence' => $sequence,
                    'action' => match ($payload['provenance']) {
                        'created' => 'Employment created', 'observed' => 'Existing details first recorded',
                        default => 'Employment updated',
                    }, 'fields' => $fields, 'job_title' => $facts['job_title'], 'active' => $facts['active'],
                    'earlier_details_unknown' => $payload['provenance'] === 'observed',
                    'recorded_at' => $stored->recordedAt->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
                    'effective_at' => $stored->context->effectiveAt?->setTimezone($utc)->format('Y-m-d\TH:i:s.u\Z'),
                    'actor_id' => $stored->context->actor?->value];
            }
            $previous = $facts;
        })['root'];
        $root->verify($employee);
        $through ??= $root->playhead();
        if ($through > $root->playhead() || ($through > 0 && $after >= $through) || ($through === 0 && $after !== 0)) {
            throw new InvalidArgumentException('Refresh employment history.');
        }
        return ['items' => array_reverse($items), 'through_version' => $through,
            'next_after' => $through > $after + self::REVIEW_SIZE ? $after + self::REVIEW_SIZE : null,
            'earlier_history_unknown' => $unknown];
    }
    public function create(Employee $employee, EntityId $operation, EntityId $actor, string $intent): void
    {
        $root = $this->load($employee->id)['root'];
        if ($root->playhead() !== 0) {
            throw new ConcurrentModificationException('Employment already exists.');
        }
        $this->append($root, $employee, $operation, $actor, $intent, 'created', null);
    }
    public function change(
        Employee $before,
        Employee $after,
        string $revision,
        EntityId $operation,
        EntityId $actor,
        string $intent,
        #[\SensitiveParameter] string $reason
    ): bool {
        $root = $this->load($before->id)['root'];
        $root->verify($before);
        if (!hash_equals(self::intent([EmploymentFacts::of($before), $root->playhead()]), $revision)) {
            throw new ConcurrentModificationException('Reload employment before editing.');
        }
        if (EmploymentFacts::of($before) === EmploymentFacts::of($after)) {
            return false;
        }
        if ($root->playhead() === 0) {
            $this->append(
                $root,
                $before,
                DerivedIdentity::from($operation, 'employment.observation'),
                $actor,
                $intent,
                'observed',
                'Current employment profile observed; earlier employment history is unknown.'
            );
        }
        $this->append($root, $after, $operation, $actor, $intent, 'changed', $reason);
        return true;
    }
    private function append(
        EmploymentHistory $root,
        Employee $employee,
        EntityId $operation,
        EntityId $actor,
        string $intent,
        string $provenance,
        #[\SensitiveParameter] ?string $reason
    ): void {
        if ($root->playhead() >= self::MAX_EVENTS) {
            throw new HistoryReadLimitException();
        }
        $event = EmploymentProfileWasRecorded::occur(
            $root->aggregateId(),
            ['operation_id' => $operation->value,
            'previous_operation_id' => $root->operation(), 'provenance' => $provenance,
            'facts' => EmploymentFacts::of($employee), 'intent' => $intent],
            ['__aggregate_type' => 'employment_profile']
        )
            ->withPlayhead($root->playhead() + 1);
        $context = new EventContext($actor, $this->clock->now(), $operation, reason: $reason);
        $version = $root->playhead();
        $root->applyRecord($event);
        $this->history->append(
            new StreamReference($root->aggregateId(), 'employment_profile'),
            $operation,
            $version,
            $context,
            [$event]
        );
    }
    /** @param ?Closure(StoredEvent,int):void $observe
     * @return array{root:EmploymentHistory,receipt:bool}
     */
    private function load(
        EntityId $employee,
        ?EntityId $operation = null,
        ?EntityId $actor = null,
        ?string $intent = null,
        ?Closure $observe = null
    ): array {
        $root = EmploymentHistory::root(new EmploymentProfileId($employee->value));
        $cursor = null;
        $count = 0;
        $receipt = false;
        $head = null;
        do {
            $page = $this->history->page(new StreamReference($root->aggregateId(), 'employment_profile'), $cursor, 100);
            if ($page->throughVersion > self::MAX_EVENTS) {
                throw new HistoryReadLimitException();
            }
            $head ??= $page->throughVersion;
            if (
                $page->throughVersion !== $head
                || ($page->nextCursor !== null && ($page->events === [] || $page->nextCursor === $cursor))
            ) {
                throw new CorruptHistoryException();
            }
            foreach ($page->events as $stored) {
                if (++$count > self::MAX_EVENTS) {
                    throw new HistoryReadLimitException();
                }
                $root->accept($stored);
                if ($observe !== null) {
                    $observe($stored, $head);
                }
                $p = $stored->event->payload();
                if ($p['operation_id'] === $operation?->value) {
                    if ($stored->context->actor?->value !== $actor?->value || $p['intent'] !== $intent) {
                        throw new AccessDeniedException('The employment operation has another actor or intent.');
                    }
                    $receipt = true;
                }
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->playhead() !== $page->throughVersion) {
            throw new CorruptHistoryException();
        }
        return ['root' => $root, 'receipt' => $receipt];
    }
}
