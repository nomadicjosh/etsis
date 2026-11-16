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
        private \Domain\Office\Service\OfficeScope $scope,
        private \Domain\Office\Repository\OfficeRepository $offices,
    ) {
    }

    public function create(
        Restriction $restriction,
        #[\SensitiveParameter] string $note,
        ?EntityId $office = null,
    ): void {
        $actor = $this->scope->actor();
        $content = $this->content($note);
        $office = $this->chooseOffice($office);
        $this->transactions->run(function () use ($restriction, $content, $actor, $office): void {
            $this->repository->lockPerson($restriction->personId);
            $this->offices->lock($office);
            $authority = $this->scope->office($office);
            $this->admissions->person($restriction->personId);
            $intent = $this->intent(['placed', $restriction->facts(), $content, $office->value]);
            $opened = $this->ledger->open($restriction->personId, $restriction->id, $actor, $intent);
            $this->authorize($actor);
            if ($opened['repeat']) {
                return;
            }
            if ($this->repository->find($restriction->id) !== null) {
                throw new ConcurrentModificationException('The restriction ID already exists.');
            }
            $this->ledger->record(
                $opened['root'],
                $restriction->id,
                $actor,
                'placed',
                $restriction,
                $intent,
                $content,
                $authority,
            );
            $this->repository->add($restriction);
            $this->offices->bind($restriction->id, $office);
            $this->storeNote($restriction->id, $restriction->id, $actor, $content);
            $this->audit->record($actor, 'restriction.created', $restriction->id);
            $this->authorize($actor, $restriction->id);
        });
    }

    /** @return array<string, mixed> */
    public function view(EntityId $id): array
    {
        $actor = $this->scope->actor();

        $this->scope->owner($id);
        $hold = $this->get($id);
        $this->ledger->verify($hold->personId);
        $office = $this->offices->owner($id);
        $this->authorize($actor, $id);
        return $this->describe($hold) + ['office_id' => $office['id'] ?? null,
            'office_name' => $office['name'] ?? null];
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function page(EntityId $person, ?EntityId $after = null): array
    {
        $actor = $this->scope->actor();
        $visible = $this->scope->visibleIds();
        if ($visible === []) {
            return ['items' => [], 'next_after' => null];
        }
        $this->admissions->person($person);
        $this->ledger->verify($person);
        $rows = $this->repository->page($person, $after, 51, $visible);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = array_map(fn (array $row): array => $this->describe($this->fromRow($row))
            + ['office_id' => $row['office_id'], 'office_name' => $row['office_name']], $rows);
        $this->authorize($actor);
        if ($visible !== $this->scope->visibleIds()) {
            throw new AccessDeniedException('Office access changed during this read.');
        }
        return ['items' => $items, 'next_after' => $more ? (string) $rows[49]['id'] : null];
    }

    public function end(
        EntityId $id,
        DateTimeImmutable $on,
        string $revision,
        EntityId $operation,
        #[\SensitiveParameter] string $reason,
    ): void {
        $actor = $this->scope->actor();
        $content = $this->content($reason, 'reason');
        $this->scope->owner($id);
        $initial = $this->get($id);
        $this->transactions->run(function () use ($id, $initial, $on, $revision, $actor, $operation, $content): void {
            $this->repository->lockPerson($initial->personId);
            $this->repository->lock($id);
            $office = $this->scope->owner($id);
            if ($office === null) {
                throw new ValidationException([['field' => 'office_id',
                    'message' => 'Set the owning office before ending this earlier hold.']]);
            }
            $this->offices->lock($office);
            $authority = $this->scope->office($office);
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
            $this->ledger->record(
                $opened['root'],
                $operation,
                $actor,
                'ended',
                $updated,
                $intent,
                $content,
                $authority,
            );
            $this->repository->setEnd($id, $date);
            $this->storeNote($operation, $id, $actor, $content);
            $this->audit->record($actor, 'restriction.end_changed', $id);
            $this->authorize($actor, $id);
        });
    }

    public function appendNote(EntityId $id, EntityId $noteId, #[\SensitiveParameter] string $note): void
    {
        $actor = $this->scope->actor();
        $content = $this->content($note);
        $this->scope->owner($id);
        $initial = $this->get($id);
        $this->transactions->run(function () use ($id, $initial, $noteId, $content, $actor): void {
            $this->repository->lockPerson($initial->personId);
            $this->repository->lock($id);
            $this->get($id);
            $office = $this->scope->owner($id);
            if ($office !== null) {
                $this->offices->lock($office);
            }
            $this->ledger->verify($initial->personId);
            $this->authorize($actor, $id);
            if ($this->repository->note($noteId) !== null) {
                if (!$this->sameNote($noteId, $id, $actor, $content)) {
                    throw new ConcurrentModificationException('The operation ID belongs to another note.');
                }
                return;
            }
            $this->storeNote($noteId, $id, $actor, $content);
            $this->audit->record($actor, 'restriction.note_added', $id);
            $this->authorize($actor, $id);
        });
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function notes(EntityId $id, ?EntityId $after = null): array
    {
        $actor = $this->scope->actor();
        $this->scope->owner($id);
        $this->ledger->verify($this->get($id)->personId);
        $rows = $this->repository->notes($id, $after, 51);
        $more = count($rows) > 50;
        $rows = array_slice($rows, 0, 50);
        $items = array_map(fn (array $row): array => ['id' => $row['id'], 'actor_id' => $row['actor_id'],
            'created_at' => $row['created_at'],
            'actor_name' => trim((string) $row['actor_given_name'] . ' ' . (string) $row['actor_family_name']),
            'content' => $this->cipher->open($row['encrypted_content'])], $rows);

        $this->audit->record($actor, 'restriction.notes_read', $id);
        $this->authorize($actor, $id);

        return ['items' => $items, 'next_after' => $more ? (string) $rows[49]['id'] : null];
    }

    /** @return array<string,mixed> */
    public function history(EntityId $id, ?string $cursor = null): array
    {
        $actor = $this->scope->actor();
        $this->scope->owner($id);
        $hold = $this->get($id);
        $result = $this->ledger->timeline($hold->personId, $id, $cursor);
        $this->audit->record($actor, 'restriction.history_read', $id);
        $this->authorize($actor, $id);
        return $result;
    }

    private function get(EntityId $id): Restriction
    {
        return $this->repository->find($id) ?? throw new RecordNotFoundException('Restriction not found.');
    }

    /** @return array<string,mixed> */
    public function queue(string $search = '', ?EntityId $after = null): array
    {
        $actor = $this->scope->actor();
        if (
            mb_strlen($search) > 80 || !mb_check_encoding($search, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([['field' => 'search', 'message' => 'Use up to 80 search characters.']]);
        }
        $visible = $this->scope->visibleIds();
        $rows = $this->repository->queue($visible, $after, trim($search), 51);
        $more = count($rows) > 50;
        $items = array_slice($rows, 0, 50);
        $items = array_map(fn (array $row): array => $this->describe($this->fromRow($row)) + [
            'office_id' => $row['office_id'], 'office_name' => $row['office_name'],
            'person_name' => trim($row['given_name'] . ' ' . $row['family_name']),
            'institutional_id' => $row['institutional_id'],
        ], $items);
        $this->authorize($actor);
        if ($visible !== $this->scope->visibleIds()) {
            throw new AccessDeniedException('Office access changed during this read.');
        }
        return ['items' => $items, 'next_after' => $more ? $items[49]['id'] : null];
    }

    /** Administrative routing for genuinely earlier unassigned records, without inventing their original office. */
    public function bind(
        EntityId $id,
        EntityId $office,
        EntityId $operation,
        string $revision,
        #[\SensitiveParameter] string $reason,
    ): void {
        $actor = $this->scope->actor();
        $this->access->requirePermission(\Domain\Office\Service\OfficeScope::ADMIN);
        $content = $this->content($reason, 'reason');
        $hold = $this->get($id);
        $this->transactions->run(function () use ($actor, $hold, $office, $operation, $revision, $content): void {
            $this->repository->lockPerson($hold->personId);
            $this->repository->lock($hold->id);
            $this->offices->lock($office);
            $this->access->requirePermission(\Domain\Office\Service\OfficeScope::ADMIN);
            $this->authorize($actor);
            $this->scope->office($office);
            $this->ledger->verify($hold->personId);
            $existing = $this->offices->owner($hold->id);
            if ($existing !== null) {
                if ($existing['id'] !== $office->value) {
                    throw new ConcurrentModificationException('This hold already has an owning office.');
                }
                return;
            }
            $this->check($this->revision($this->get($hold->id)), $revision);
            $this->offices->bind($hold->id, $office);
            $this->storeNote($operation, $hold->id, $actor, $content);
            $this->audit->record($actor, 'restriction.office_assigned', $hold->id);
            $this->access->requirePermission(\Domain\Office\Service\OfficeScope::ADMIN);
            $this->authorize($actor, $hold->id);
        });
    }

    private function chooseOffice(?EntityId $office): EntityId
    {
        if ($office === null) {
            $choices = $this->scope->offices();
            $choices = array_values(array_filter($choices, static fn (array $r): bool => (bool) $r['active']));
            if (count($choices) !== 1) {
                throw new ValidationException([['field' => 'office_id',
                    'message' => 'Choose the office responsible for this hold.']]);
            }
            $office = new EntityId($choices[0]['id']);
        }
        $this->scope->office($office);
        if (!(bool) ($this->offices->office($office)['active'] ?? false)) {
            throw new ValidationException([['field' => 'office_id', 'message' => 'Choose an active office.']]);
        }
        return $office;
    }

    /** @param array<string,mixed> $row */
    private function fromRow(array $row): Restriction
    {
        return Restriction::fromFacts(['id' => $row['id'], 'person_id' => $row['person_id'],
            'code' => $row['code'], 'severity' => (int) $row['severity'], 'starts_on' => $row['starts_on'],
            'ends_on' => $row['ends_on'], 'blocks_staff' => (bool) $row['blocks_staff']]);
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

    private function authorize(EntityId $actor, ?EntityId $restriction = null): void
    {
        if ($this->scope->actor()->value !== $actor->value) {
            throw new AccessDeniedException('The restriction actor changed.');
        }
        if ($restriction !== null) {
            $this->scope->owner($restriction);
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
