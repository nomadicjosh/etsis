<?php

declare(strict_types=1);

namespace Domain\Student\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Admissions\Repository\AdmissionsRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Repository\RestrictionRepository;
use Domain\Student\Restriction;
use DomainException;

final readonly class RestrictionService
{
    public function __construct(
        private RestrictionRepository $repository,
        private AdmissionsRepository $admissions,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
        private RestrictionLedger $ledger,
    ) {
    }

    public function create(Restriction $restriction, #[\SensitiveParameter] string $note): void
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $content = $this->content($note);
        $this->transactions->run(function () use ($restriction, $content, $actor): void {
            $this->repository->lockPerson($restriction->personId);
            $this->admissions->person($restriction->personId);
            $intent = $this->intent(['placed', $restriction->facts(), $content]);
            $opened = $this->ledger->open($restriction->personId, $restriction->id, $actor, $intent);
            $this->authorize($actor);
            if ($opened['repeat']) {
                return;
            }
            if ($this->repository->find($restriction->id) !== null) {
                throw new ConcurrentModificationException('The restriction ID already exists.');
            }
            $this->ledger->record($opened['root'], $restriction->id, $actor, 'placed', $restriction, $intent, $content);
            $this->repository->add($restriction);
            $this->storeNote($restriction->id, $restriction->id, $actor, $content);
            $this->audit->record($actor, 'restriction.created', $restriction->id);
            $this->authorize($actor);
        });
    }

    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        $actor = $this->access->requirePermission('sis:registration:manage');

        $hold = $this->get($id);
        $this->ledger->verify($hold->personId);
        $this->authorize($actor);
        return $this->describe($hold);
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function page(EntityId $person, ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $this->admissions->person($person);
        $this->ledger->verify($person);
        $rows = $this->repository->page($person, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = array_map(fn (array $row): array => $this->describe(new Restriction(
            new EntityId($row['id']),
            $person,
            $row['code'],
            (int) $row['severity'],
            new DateRange(
                new DateTimeImmutable($row['starts_on']),
                $row['ends_on'] === null ? null : new DateTimeImmutable($row['ends_on'])
            ),
            (bool) $row['blocks_staff']
        )), $rows);

        $this->authorize($actor);
        return ['items' => $items, 'next_after' => $more ? (string) $rows[49]['id'] : null];
    }

    public function end(
        EntityId $id,
        DateTimeImmutable $on,
        string $revision,
        EntityId $operation,
        #[\SensitiveParameter] string $reason,
    ): void {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $content = $this->content($reason, 'reason');
        $initial = $this->get($id);
        $this->transactions->run(function () use ($id, $initial, $on, $revision, $actor, $operation, $content): void {
            $this->repository->lockPerson($initial->personId);
            $this->repository->lock($id);
            $intent = $this->intent(['ended', $id->value, $on->format('Y-m-d'), $revision, $content]);
            $opened = $this->ledger->open($initial->personId, $operation, $actor, $intent);
            $this->authorize($actor);
            if ($opened['repeat']) {
                return;
            }
            $current = $this->get($id);
            $this->check($this->revision($current), $revision);
            $date = $on->format('Y-m-d');
            $end = $current->dates->end?->format('Y-m-d');
            if ($end === $date) {
                throw new ValidationException([['field' => 'ends_on',
                    'message' => 'Choose an earlier end date; this date is already recorded.']]);
            }
            if ($current->state($this->clock->now()) === 'ended') {
                throw new DomainException('An ended restriction is historical.');
            }
            if ($date < $current->dates->start->format('Y-m-d') || ($end !== null && $date > $end)) {
                throw new ValidationException([['field' => 'ends_on',
                    'message' => 'Use an end date on/after the start and no later than the scheduled end.']]);
            }
            $updated = new Restriction(
                $current->id,
                $current->personId,
                $current->code,
                $current->severity,
                new DateRange($current->dates->start, $on),
                $current->blocksStaffRegistration
            );
            $this->ledger->record($opened['root'], $operation, $actor, 'ended', $updated, $intent, $content);
            $this->repository->setEnd($id, $date);
            $this->storeNote($operation, $id, $actor, $content);
            $this->audit->record($actor, 'restriction.end_changed', $id);
            $this->authorize($actor);
        });
    }

    public function appendNote(EntityId $id, EntityId $noteId, #[\SensitiveParameter] string $note): void
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $content = $this->content($note);
        $initial = $this->get($id);
        $this->transactions->run(function () use ($id, $initial, $noteId, $content, $actor): void {
            $this->repository->lockPerson($initial->personId);
            $this->repository->lock($id);
            $this->get($id);
            $this->ledger->verify($initial->personId);
            $this->authorize($actor);
            if ($this->repository->note($noteId) !== null) {
                if (!$this->sameNote($noteId, $id, $actor, $content)) {
                    throw new ConcurrentModificationException('The operation ID belongs to another note.');
                }
                return;
            }
            $this->storeNote($noteId, $id, $actor, $content);
            $this->audit->record($actor, 'restriction.note_added', $id);
            $this->authorize($actor);
        });
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function notes(EntityId $id, ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $this->ledger->verify($this->get($id)->personId);
        $rows = $this->repository->notes($id, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = array_map(fn (array $row): array => ['id' => $row['id'], 'actor_id' => $row['actor_id'],
            'created_at' => $row['created_at'],
            'actor_name' => trim((string) $row['actor_given_name'] . ' ' . (string) $row['actor_family_name']),
            'content' => $this->cipher->open($row['encrypted_content'])], $rows);

        $this->audit->record($actor, 'restriction.notes_read', $id);
        $this->authorize($actor);

        return ['items' => $items, 'next_after' => $more ? (string) $rows[49]['id'] : null];
    }

    /** @return array<string,mixed> */
    public function history(EntityId $id, ?string $cursor = null): array
    {
        $actor = $this->access->requirePermission('sis:registration:manage');
        $hold = $this->get($id);
        $result = $this->ledger->timeline($hold->personId, $id, $cursor);
        $this->audit->record($actor, 'restriction.history_read', $id);
        $this->authorize($actor);
        return $result;
    }

    private function get(EntityId $id): Restriction
    {
        return $this->repository->find($id) ?? throw new RecordNotFoundException('Restriction not found.');
    }

    /** @return array<string, mixed> */
    private function describe(Restriction $restriction): array
    {
        $now = $this->clock->now();

        return ['id' => $restriction->id->value, 'person_id' => $restriction->personId->value,
            'code' => $restriction->code, 'severity' => $restriction->severity,
            'starts_on' => $restriction->dates->start->format('Y-m-d'),
            'ends_on' => $restriction->dates->end?->format('Y-m-d'),
            'blocks_staff' => $restriction->blocksStaffRegistration,
            'today' => $now->format('Y-m-d'),
            'timezone' => $now->getTimezone()->getName(),
            'state' => $restriction->state($now), 'revision' => $this->revision($restriction)];
    }

    private function revision(Restriction $restriction): string
    {
        return $this->intent($restriction->facts());
    }

    private function check(string $current, string $expected): void
    {
        if (!hash_equals($current, $expected)) {
            throw new ConcurrentModificationException('Reload the restriction before saving.');
        }
    }

    private function content(#[\SensitiveParameter] string $note, string $field = 'note'): string
    {
        $content = trim($note);
        if (
            !mb_check_encoding($content, 'UTF-8') || $content === '' || mb_strlen($content) > 10000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $content)
        ) {
            throw new ValidationException([['field' => $field,
                'message' => 'Enter a staff note of up to 10,000 characters.']]);
        }

        return $content;
    }

    /** @param array<mixed> $data */
    private function intent(#[\SensitiveParameter] array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    private function authorize(EntityId $actor): void
    {
        if ($this->access->requirePermission('sis:registration:manage')->value !== $actor->value) {
            throw new AccessDeniedException('The restriction actor changed.');
        }
    }

    private function storeNote(
        EntityId $noteId,
        EntityId $id,
        EntityId $actor,
        #[\SensitiveParameter] string $content
    ): void {
        $this->repository->addNote(
            $noteId,
            $id,
            $actor,
            $this->cipher->seal($content),
            $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM)
        );
    }

    private function sameNote(
        EntityId $noteId,
        EntityId $id,
        EntityId $actor,
        #[\SensitiveParameter] string $content
    ): bool {
        $note = $this->repository->note($noteId);

        return $note !== null && $note['restriction_id'] === $id->value && $note['actor_id'] === $actor->value
        && hash_equals($this->cipher->open($note['encrypted_content']), $content);
    }
}
