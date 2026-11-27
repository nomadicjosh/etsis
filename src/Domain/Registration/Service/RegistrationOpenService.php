<?php

declare(strict_types=1);

namespace Domain\Registration\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Access\Repository\AccessRepository;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Repository\RegistrationOpenRepository;
use Domain\Registration\Repository\RegistrationWindowRepository;
use Domain\Registration\ValueObject\RegistrationWindowDefinition;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

use function Codefy\Framework\Helpers\trans;

/** Current availability from verified publications; no fan-out, historical notices or registration decisions. */
final readonly class RegistrationOpenService
{
    public function __construct(
        private RegistrationOpenRepository $records,
        private RegistrationWindowRepository $windows,
        private RegistrationWindowService $publications,
        private AccessRepository $permissions,
        private AdmissionsRepository $people,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
    ) {
    }

    private function now(): string
    {
        $time = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        return $time->format('Y-m-d H:i:s') . ($time->format('u') === '000000' ? '' : $time->format('.u'));
    }

    private function owner(EntityId $owner, ?EntityId $person): void
    {
        if (
            $this->access->authenticatedUserId()->value !== $owner->value
            || $this->records->person($owner)?->value !== $person?->value
        ) {
            throw new AccessDeniedException();
        }
    }

    private function checked(array $row): array
    {
        $published = $this->publications->publishedWindow(new EntityId($row['term_id']));
        if ($published === null) {
            throw new RecordNotFoundException();
        }
        $definition = new RegistrationWindowDefinition($published['definition']);
        $window = $definition->window($published['reference']);
        if (
            !$window->isOpen($this->clock->now())
            || $window->dates->start->format('Y-m-d H:i:s') !== $row['opens_at']
            || $window->dates->end->format('Y-m-d H:i:s') !== $row['closes_at']
            || $window->courseLimit !== (int) $row['course_limit']
            || $published['reference'] !== ['term_id' => $row['term_id'], 'publication_id' => $row['publication_id'],
                'version' => (int) $row['policy_version'], 'definition_hash' => $row['definition_hash']]
        ) {
            throw new ConcurrentModificationException();
        }
        return $published;
    }

    private function source(EntityId $owner, EntityId $person, EntityId $term): array
    {
        $this->owner($owner, $person);
        $row = $this->records->candidate($owner, $person, $term, $this->now());
        if ($row === null) {
            throw new RecordNotFoundException();
        }
        return $this->checked($row);
    }

    private function same(?array $state, array $publication): bool
    {
        return $state !== null && $state['publication_id'] === $publication['publication_id']
        && (int) $state['revision'] === $publication['revision'];
    }

    private function publication(array $source): array
    {
        return ['publication_id' => $source['reference']['publication_id'],
            'revision' => $source['reference']['version']];
    }

    public function page(bool $unreadOnly = true, ?string $afterAt = null, ?EntityId $after = null): array
    {
        $owner = $this->access->authenticatedUserId();
        $person = $this->records->person($owner);
        if (($afterAt === null) !== ($after === null)) {
            throw new InvalidArgumentException(trans('Use a complete registration-notice continuation.'));
        }
        if ($person === null) {
            $this->owner($owner, null);
            return ['items' => [], 'next' => null];
        }
        $cursor = null;
        if ($after !== null) {
            $source = $this->source($owner, $person, $after);
            $date = new DateTimeImmutable($source['definition']['opens_at'], new DateTimeZone('UTC'));
            if ($date->format('Y-m-d\TH:i:s.000000\Z') !== $afterAt) {
                throw new ConcurrentModificationException();
            }
            $cursor = ['at' => $date->format('Y-m-d H:i:s'), 'id' => $after->value];
        }
        $rows = $this->records->page($owner, $person, $this->now(), $unreadOnly, $cursor);
        $items = [];
        $sources = [];
        foreach ($rows as $row) {
            $source = $this->checked($row);
            $sources[$row['term_id']] = $source;
            $items[] = ['id' => $row['term_id'], 'revision' => $source['reference']['version'], 'status' => 'open',
                'term_code' => $source['definition']['term_code'],
                'opens_at' => new DateTimeImmutable($source['definition']['opens_at'], new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z'),
                'closes_at' => new DateTimeImmutable($source['definition']['closes_at'], new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s\Z'),
                'recorded_at' => new DateTimeImmutable($source['definition']['opens_at'], new DateTimeZone('UTC'))
                    ->format('Y-m-d\TH:i:s.000000\Z'),
                'unread' => $row['read_publication_id'] !== $row['publication_id']
                    || (int) $row['read_revision'] !== (int) $row['policy_version']];
        }
        $this->owner($owner, $person);
        if ($this->records->page($owner, $person, $this->now(), $unreadOnly, $cursor) !== $rows) {
            throw new ConcurrentModificationException();
        }
        foreach ($sources as $term => $source) {
            if ($this->source($owner, $person, new EntityId($term)) !== $source) {
                throw new ConcurrentModificationException();
            }
        }
        $more = count($items) > 20;
        $items = array_slice($items, 0, 20);
        $last = $more ? end($items) : null;
        return ['items' => $items, 'next' => $last === null ? null
            : ['at' => $last['recorded_at'], 'id' => $last['id']]];
    }

    public function read(EntityId $term, int $revision): array
    {
        return $this->change($term, $revision, false);
    }

    public function delete(EntityId $term, int $revision): array
    {
        return $this->change($term, $revision, true);
    }

    private function change(EntityId $term, int $revision, bool $delete): array
    {
        if ($revision < 1 || $revision > 1000) {
            throw new InvalidArgumentException(trans('Use the displayed registration-notice revision.'));
        }
        $owner = $this->access->authenticatedUserId();
        $person = $this->records->person($owner) ?? throw new RecordNotFoundException();
        return $this->transactions->run(function () use ($term, $revision, $delete, $owner, $person): array {
            $this->permissions->lock();
            $this->people->lockPerson($person);
            $this->windows->lock($term);
            $source = $this->source($owner, $person, $term);
            $publication = $this->publication($source);
            if ($publication['revision'] !== $revision) {
                throw new ConcurrentModificationException();
            }
            $dismissed = $this->records->dismissal($owner, $person, $term);
            $previous = $this->records->readState($owner, $person, $term);
            if ((int) ($dismissed['revision'] ?? 0) > $revision || (int) ($previous['revision'] ?? 0) > $revision) {
                throw new ConcurrentModificationException();
            }
            $result = $delete ? ['deleted' => true] : ['unread' => false];
            if ($this->same($dismissed, $publication)) {
                if (!$delete) {
                    throw new RecordNotFoundException();
                }
                return $result;
            }
            if (!$delete && $this->same($previous, $publication)) {
                return $result;
            }
            $at = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            if ($delete) {
                $this->records->dismiss($owner, $person, $term, $publication);
            } else {
                $this->records->markRead($owner, $person, $term, $publication, $at);
            }
            $this->audit->record(
                $owner,
                $delete ? 'registration.open_notice_deleted' : 'registration.open_notice_read',
                $term
            );
            $this->owner($owner, $person);
            if (
                $this->source($owner, $person, $term) !== $source
                || ($delete ? (!$this->same($this->records->dismissal($owner, $person, $term), $publication)
                        || $this->records->readState($owner, $person, $term) !== null)
                    : (!$this->same($this->records->readState($owner, $person, $term), $publication)
                        || $this->records->readState($owner, $person, $term)['read_at'] !== $at
                        || $this->records->dismissal($owner, $person, $term) !== $dismissed))
            ) {
                throw new ConcurrentModificationException();
            }
            return $result;
        });
    }
}
