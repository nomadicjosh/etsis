<?php

declare(strict_types=1);

namespace Domain\Person\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Person\Event\PersonSsnWasRecorded;
use Domain\Person\Person;
use Domain\Person\PersonSsnHistory;
use Domain\Person\Repository\PersonProfileRepository;
use Domain\Person\Repository\PersonSsnRepository;
use Domain\Person\ValueObject\PersonSsnId;
use Domain\Person\ValueObject\SocialSecurityNumber;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\HistoryReadBudget;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\Service\ReviewProof;
use Domain\Shared\ValueObject\EntityId;

use function Codefy\Framework\Helpers\trans_html;

/** Staff-only masked reads and reviewed corrections; no saved full-number read endpoint. */
final readonly class PersonSsnService
{
    public const string READ = 'sis:person:ssn:read';
    public const string MANAGE = 'sis:person:ssn:manage';
    public const int MAX_EVENTS = 500;

    public function __construct(
        private PersonSsnRepository $records,
        private PersonProfileRepository $people,
        private PersonProfileAccess $profiles,
        private AccessRepository $roles,
        private StudentAccess $access,
        private HistoricalEventStore $history,
        private MessageCipher $cipher,
        private ReviewProof $proofs,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    private function authorize(EntityId $id, EntityId $actor, bool $write, bool $audit = false): Person
    {
        foreach (
            ['sis:person:manage', self::READ, ...$write ? [self::MANAGE] : [],
            ...$audit ? ['sis:audit:read'] : []] as $permission
        ) {
            if (
                $this->access->requirePermission($permission)->value !== $actor->value
                || !in_array($actor->value, $this->roles->allowedUsers([$actor], $permission), true)
            ) {
                throw new AccessDeniedException();
            }
        }
        return $this->profiles->person($actor, $id, true);
    }

    private function withPerson(EntityId $id, bool $write, callable $action, bool $audit = false): array
    {
        $actor = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($id, $write, $action, $audit, $actor): array {
            $this->roles->lock();
            $this->people->lock($id);
            $before = $this->authorize($id, $actor, $write, $audit);
            $result = $action($before, $actor);
            $after = $this->authorize($id, $actor, $write, $audit);
            if (
                $before->ownerUserId?->value !== $after->ownerUserId?->value
                || $before->identityVersion !== $after->identityVersion
            ) {
                throw new ConcurrentModificationException(trans_html('The person changed. Reopen the SSN record.'));
            }
            return $result;
        });
    }

    private function stream(EntityId $person): StreamReference
    {
        return new StreamReference(new PersonSsnId($person->value), 'person_ssn');
    }

    private function load(EntityId $id): PersonSsnHistory
    {
        $root = PersonSsnHistory::root(new PersonSsnId($id->value));
        $budget = new HistoryReadBudget(
            maximumEvents: self::MAX_EVENTS,
            maximumBytes: 2097152,
            maximumMilliseconds: 5000
        );
        $cursor = null;
        do {
            $page = $budget->page($this->history, $this->stream($id), $cursor);
            if ($page->throughVersion > self::MAX_EVENTS) {
                throw new HistoryReadLimitException();
            }
            foreach ($page->events as $stored) {
                $root->accept($stored);
            }
            $cursor = $page->nextCursor;
        } while ($cursor !== null);
        if ($root->playhead() !== $page->throughVersion) {
            throw new CorruptHistoryException();
        }
        $row = $this->records->find($id);
        if ($root->playhead() === 0) {
            if ($row !== null) {
                throw new CorruptHistoryException();
            }
        } else {
            try {
                if (
                    $row === null || (int) $row['version'] !== $root->playhead()
                    || strlen($row['sealed_data']) > 4096
                    || json_decode($this->cipher->open($row['sealed_data']), true, 8, JSON_THROW_ON_ERROR)
                        !== $this->projection($id, $root->playhead(), $root->lastFour())
                ) {
                    throw new CorruptHistoryException();
                }
            } catch (\Throwable) {
                throw new CorruptHistoryException();
            }
        }
        return $root;
    }

    private function projection(EntityId $id, int $version, ?string $lastFour): array
    {
        return ['purpose' => 'person.ssn.mask', 'person_id' => $id->value, 'version' => $version,
            'last_four' => $lastFour];
    }

    private function mask(?string $lastFour): ?string
    {
        return $lastFour === null ? null : '***-**-' . $lastFour;
    }

    public function view(EntityId $id): array
    {
        return $this->withPerson($id, false, function (Person $person, EntityId $actor) use ($id): array {
            $root = $this->load($id);
            $result = ['version' => $root->playhead(), 'masked' => $this->mask($root->lastFour()),
                'can_edit' => $root->playhead() < self::MAX_EVENTS
                    && in_array($actor->value, $this->roles->allowedUsers([$actor], self::MANAGE), true),
                'at_limit' => $root->playhead() >= self::MAX_EVENTS,
                'can_history' => in_array($actor->value, $this->roles->allowedUsers([$actor], 'sis:audit:read'), true)];
            $this->audit->record($actor, 'person.ssn_viewed', $id);
            if ($this->load($id)->playhead() !== $root->playhead()) {
                throw new ConcurrentModificationException();
            }
            return $result;
        });
    }

    public function review(
        EntityId $id,
        int $version,
        #[\SensitiveParameter] ?SocialSecurityNumber $number,
        #[\SensitiveParameter] string $reason
    ): array {
        $reason = PersonSsnHistory::reason($reason);
        return $this->withPerson($id, true, function (
            Person $person,
            EntityId $actor
        ) use (
            $id,
            $version,
            $number,
            $reason
        ): array {
            $root = $this->load($id);
            if ($version !== $root->playhead()) {
                throw new ConcurrentModificationException(trans_html(
                    'The SSN record changed. Reload before reviewing.'
                ));
            }
            $operation = EntityId::generate();
            $intent = PersonSsnHistory::intent(
                $id->value,
                $person->ownerUserId->value,
                $person->identityVersion,
                $actor,
                $version,
                $number,
                $reason
            );
            $this->audit->record($actor, 'person.ssn_reviewed', $id);
            if ($this->load($id)->playhead() !== $version) {
                throw new ConcurrentModificationException();
            }
            return ['version' => $version, 'operation_id' => $operation->value,
                'before' => $this->mask($root->lastFour()), 'after' => $this->mask($number?->lastFour()),
                'reason' => $reason, 'review_token' => $this->proofs->issue(
                    'person.ssn',
                    'staff',
                    $id,
                    $actor,
                    $operation,
                    $intent
                )];
        });
    }

    public function confirm(
        EntityId $id,
        EntityId $operation,
        int $version,
        #[\SensitiveParameter] ?SocialSecurityNumber $number,
        #[\SensitiveParameter] string $reason,
        #[\SensitiveParameter] string $token
    ): array {
        $reason = PersonSsnHistory::reason($reason);
        return $this->withPerson($id, true, function (
            Person $person,
            EntityId $actor
        ) use (
            $id,
            $operation,
            $version,
            $number,
            $reason,
            $token
        ): array {
            $root = $this->load($id);
            $prior = $root->receipt($operation);
            $owner = $prior?->event->payload()['owner_user_id'] ?? $person->ownerUserId->value;
            $identityVersion = $prior?->event->payload()['identity_version'] ?? $person->identityVersion;
            $intent = PersonSsnHistory::intent(
                $id->value,
                $owner,
                $identityVersion,
                $actor,
                $version,
                $number,
                $reason
            );
            $this->proofs->validate($token, 'person.ssn', 'staff', $id, $actor, $operation, $intent, $prior !== null);
            if ($prior !== null) {
                if (
                    $prior->context->actor?->value !== $actor->value || $prior->event->playhead() !== $version + 1
                    || !hash_equals($prior->event->payload()['intent'], $intent)
                ) {
                    throw new AccessDeniedException();
                }
                $this->history->verifyOperation($operation);
                return ['status' => 'recorded', 'operation_id' => $operation->value, 'version' => $version + 1];
            }
            if ($root->playhead() !== $version) {
                throw new ConcurrentModificationException(trans_html(
                    'The SSN record changed. Review the current record again.'
                ));
            }
            if ($version >= self::MAX_EVENTS) {
                throw new HistoryReadLimitException();
            }
            $event = PersonSsnWasRecorded::occur(new PersonSsnId($id->value), [
                'person_id' => $id->value, 'owner_user_id' => $person->ownerUserId->value,
                'identity_version' => $person->identityVersion, 'number' => $number?->digits(),
                'intent' => $intent,
                // Prevent visible operation fingerprints from becoming low-entropy number lookup hashes.
                'privacy_nonce' => bin2hex(random_bytes(32)),
            ], ['__aggregate_type' => 'person_ssn'])->withPlayhead($version + 1);
            $this->history->append(
                $this->stream($id),
                $operation,
                $version,
                new EventContext($actor, $this->clock->now(), $operation, reason: $reason),
                [$event]
            );
            $this->records->save($id, $version, $this->cipher->seal(json_encode(
                $this->projection($id, $version + 1, $number?->lastFour()),
                JSON_THROW_ON_ERROR
            )));
            $this->audit->record($actor, 'person.ssn_recorded', $id);
            $this->proofs->validate($token, 'person.ssn', 'staff', $id, $actor, $operation, $intent);
            $after = $this->load($id);
            if ($after->playhead() !== $version + 1 || $after->lastFour() !== $number?->lastFour()) {
                throw new CorruptHistoryException();
            }
            $this->history->verifyOperation($operation);
            return ['status' => 'recorded', 'operation_id' => $operation->value, 'version' => $version + 1];
        });
    }

    public function timeline(EntityId $id, ?string $cursor = null): array
    {
        return $this->withPerson($id, false, function (Person $person, EntityId $actor) use ($id, $cursor): array {
            $root = $this->load($id);
            $page = $this->history->page($this->stream($id), $cursor, 20);
            $items = array_map(static fn($stored): array => [
                'version' => $stored->event->playhead(), 'recorded_at' => $stored->recordedAt->format(DATE_ATOM),
                'action' => $stored->event->payload()['number'] === null ? 'cleared' : 'recorded',
                'reason' => $stored->context->reason,
            ], $page->events);
            $this->audit->record($actor, 'person.ssn_history_read', $id);
            if ($this->load($id)->playhead() !== $root->playhead()) {
                throw new ConcurrentModificationException();
            }
            return ['items' => $items, 'next_cursor' => $page->nextCursor];
        }, audit: true);
    }
}
