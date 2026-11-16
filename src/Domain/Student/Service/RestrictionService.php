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
    public const int HANDOFF_REVIEW_SECONDS = 1200;
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
        $work = $this->offices->followUp($id);
        $this->authorize($actor, $id);
        return $this->describe($hold) + ['office_id' => $office['id'] ?? null,
            'office_name' => $office['name'] ?? null, 'follow_up' => $work === null ? null : [
                'assigned_user_id' => $work['assigned_user_id'], 'assignee_name' => $work['assignee_name'],
                'follow_up_on' => $work['follow_up_on']]];
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
    public function queue(
        string $search = '',
        ?EntityId $after = null,
        string $assignment = 'all',
        bool $due = false,
    ): array {
        $actor = $this->scope->actor();
        if (
            mb_strlen($search) > 80 || !mb_check_encoding($search, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([['field' => 'search', 'message' => 'Use up to 80 search characters.']]);
        }
        if (!in_array($assignment, ['all', 'mine', 'unassigned'], true)) {
            throw new ValidationException([['field' => 'assignment', 'message' => 'Choose a worklist filter.']]);
        }
        $visible = $this->scope->visibleIds();
        $rows = $this->repository->queue(
            $visible,
            $after,
            trim($search),
            51,
            $assignment,
            $actor,
            $due ? $this->clock->now()->format('Y-m-d') : null
        );
        $more = count($rows) > 50;
        $items = array_slice($rows, 0, 50);
        $items = array_map(fn (array $row): array => $this->describe($this->fromRow($row)) + [
            'office_id' => $row['office_id'], 'office_name' => $row['office_name'],
            'person_name' => trim($row['given_name'] . ' ' . $row['family_name']),
            'institutional_id' => $row['institutional_id'],
            'assigned_user_id' => $row['assigned_user_id'],
            'assignee_name' => trim(($row['assignee_first_name'] ?? '') . ' ' . ($row['assignee_last_name'] ?? '')),
            'follow_up_on' => $row['follow_up_on'],
        ], $items);
        $this->authorize($actor);
        if ($visible !== $this->scope->visibleIds()) {
            throw new AccessDeniedException('Office access changed during this read.');
        }
        return ['items' => $items, 'next_after' => $more ? $items[49]['id'] : null];
    }

    /** Current operational work, separate from the hold's retained academic effects.
     * @return array<string,mixed> */
    public function followUp(EntityId $id): array
    {
        $actor = $this->scope->actor();
        $office = $this->scope->owner($id);
        $this->ledger->verify($this->get($id)->personId);
        $work = $this->offices->followUp($id);
        if ($office === null || $work === null) {
            throw new ValidationException([['field' => 'office_id', 'message' => 'Set the owning office first.']]);
        }
        if ($work['office_id'] !== $office->value) {
            throw new ConcurrentModificationException('The owning office changed. Reload this follow-up.');
        }
        $assigned = $work['assigned_user_id'] === null ? null : new EntityId($work['assigned_user_id']);
        $result = $work + ['assignee_eligible' => $assigned === null || $this->eligibleWorker($office, $assigned),
            'can_assign_self' => $this->eligibleWorker($office, $actor),
            'self_user_id' => $actor->value,
            'today' => $this->clock->now()->format('Y-m-d')];
        $this->authorize($actor, $id);
        return $result;
    }

    /** @return array<string,mixed> */
    public function followUpPeople(EntityId $id, string $search = '', ?EntityId $after = null): array
    {
        $actor = $this->scope->actor();
        $office = $this->scope->owner($id);
        if ($office === null) {
            throw new ValidationException([['field' => 'office_id', 'message' => 'Set the owning office first.']]);
        }
        if (
            mb_strlen($search) > 80 || !mb_check_encoding($search, 'UTF-8')
            || preg_match('/[\x00-\x1F\x7F]/', $search)
        ) {
            throw new ValidationException([['field' => 'search', 'message' => 'Use up to 80 search characters.']]);
        }
        $rows = $this->offices->workers($office, $this->clock->now()->format('Y-m-d'), trim($search), $after, 26);
        $more = count($rows) > 25;
        $items = [];
        foreach (array_slice($rows, 0, 25) as $row) {
            if ($this->offices->userCanManageHolds(new EntityId($row['user_id']))) {
                $items[] = ['id' => $row['user_id'], 'name' => trim($row['first_name'] . ' ' . $row['last_name'])];
            }
        }
        $this->authorize($actor, $id);
        if ($this->scope->owner($id)?->value !== $office->value) {
            throw new ConcurrentModificationException('The hold moved to another office.');
        }
        return ['items' => $items, 'next_after' => $more ? $rows[24]['id'] : null];
    }

    public function saveFollowUp(
        EntityId $id,
        EntityId $office,
        int $revision,
        ?EntityId $assignee,
        ?DateTimeImmutable $on,
    ): void {
        $actor = $this->scope->actor();
        $this->scope->owner($id);
        $hold = $this->get($id);
        $date = $on?->format('Y-m-d');
        if ($date !== null && !\Domain\Student\ValueObject\StudentActivitySchema::date($date)) {
            throw new ValidationException([['field' => 'follow_up_on', 'message' => 'Choose a valid follow-up date.']]);
        }
        if ($revision < 0 || $revision >= 2_000_000_000) {
            throw new ValidationException([['field' => 'work_revision', 'message' => 'Reload this follow-up.']]);
        }
        $this->transactions->run(function () use ($id, $office, $revision, $assignee, $date, $actor, $hold): void {
            $this->repository->lockPerson($hold->personId);
            $this->repository->lock($id);
            if (($this->offices->owner($id)['id'] ?? null) !== $office->value) {
                throw new ConcurrentModificationException('The owning office changed. Reload this follow-up.');
            }
            $this->offices->lock($office);
            $this->authorize($actor, $id);
            $work = $this->offices->followUp($id);
            if ($work === null || $work['office_id'] !== $office->value) {
                throw new ConcurrentModificationException('The owning office changed. Reload this follow-up.');
            }
            $this->ledger->verify($hold->personId);
            $same = $work['assigned_user_id'] === $assignee?->value && $work['follow_up_on'] === $date;
            if ((int) $work['work_revision'] !== $revision) {
                if (!$same || (int) $work['work_revision'] !== $revision + 1) {
                    throw new ConcurrentModificationException('Another colleague changed this follow-up. Reload it.');
                }
                $this->authorize($actor, $id);
                return;
            }
            if ($assignee !== null && !$this->eligibleWorker($office, $assignee)) {
                throw new ValidationException([['field' => 'assigned_user_id',
                    'message' => 'Choose a current office delegate with hold-management permission.']]);
            }
            if (!$same) {
                $this->offices->saveFollowUp($id, $office, $assignee, $date, $revision);
                $this->audit->record($actor, 'restriction.follow_up_saved', $id);
            }
            $this->authorize($actor, $id);
            if ($assignee !== null && !$this->eligibleWorker($office, $assignee)) {
                throw new AccessDeniedException('The assigned colleague no longer has current office access.');
            }
        });
    }

    private function eligibleWorker(EntityId $office, EntityId $user): bool
    {
        $delegate = $this->offices->delegation($office, $user);
        $today = $this->clock->now()->format('Y-m-d');
        return (bool) ($this->offices->office($office)['active'] ?? false)
        && $this->offices->userCanManageHolds($user) && $delegate !== null
        && (int) $delegate['active'] === 1 && $delegate['starts_on'] <= $today
        && ($delegate['ends_on'] === null || $delegate['ends_on'] >= $today);
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

    /** A read-only review with a protected, actor-bound ticket kept only in the open form.
     * @return array<string,mixed> */
    public function reviewHandoff(EntityId $id, EntityId $to, #[\SensitiveParameter] string $reason): array
    {
        $actor = $this->handoffActor();
        $this->scope->owner($id);
        $note = $this->content($reason, 'reason');
        $context = $this->handoffContext($this->get($id), $to);
        $now = $this->clock->now()->getTimestamp();
        $operation = EntityId::generate();
        $ticket = ['purpose' => 'hold.office-handoff', 'actor_id' => $actor->value,
            'restriction_id' => $id->value, 'operation_id' => $operation->value,
            'issued_at' => $now, 'expires_at' => $now + self::HANDOFF_REVIEW_SECONDS,
            'context' => $context, 'reason' => $note];
        $sealed = $this->cipher->seal(json_encode(
            $ticket,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $this->handoffAuthority($actor, new EntityId($context['from']['office_id']), $to);
        return ['review_token' => $sealed, 'operation_id' => $operation->value,
            'from_office' => $context['from']['office_name'], 'to_office' => $context['to']['office_name'],
            'hold' => $context['hold'], 'person' => $context['person'], 'notes_count' => $context['notes_count'],
            'review_minutes' => (int) (self::HANDOFF_REVIEW_SECONDS / 60), 'follow_up' => $context['follow_up']];
    }

    /** @return array{id:string,operation_id:string,from_office_id:string,to_office_id:string} */
    public function confirmHandoff(EntityId $id, #[\SensitiveParameter] string $token, bool $acknowledge): array
    {
        $actor = $this->handoffActor();
        $this->scope->owner($id);
        if (!$acknowledge) {
            throw new ValidationException([['field' => 'acknowledge_access',
                'message' => 'Confirm the receiving office may access this hold, its notes and its history.']]);
        }
        $ticket = $this->handoffTicket($token, $actor, $id);
        $initial = $this->get($id);
        return $this->transactions->run(function () use ($actor, $ticket, $initial): array {
            $id = $initial->id;
            $operation = new EntityId($ticket['operation_id']);
            $from = new EntityId($ticket['context']['from']['office_id']);
            $to = new EntityId($ticket['context']['to']['office_id']);
            $this->repository->lockPerson($initial->personId);
            $this->repository->lock($id);
            $current = $this->get($id);
            $owner = $this->offices->owner($id);
            if ($current->personId->value !== $initial->personId->value || $owner === null) {
                throw new ConcurrentModificationException('Review the hold routing again.');
            }
            // All involved office rows use one stable order, including an accepted retry after another handoff.
            $locks = array_unique([$owner['id'], $from->value, $to->value]);
            sort($locks, SORT_STRING);
            foreach ($locks as $office) {
                $this->offices->lock(new EntityId($office));
            }
            $this->handoffActor($actor);
            $this->scope->office(new EntityId($owner['id']));
            $intent = $this->intent($ticket);
            $opened = $this->ledger->open($current->personId, $operation, $actor, $intent);
            $result = ['id' => $id->value, 'operation_id' => $operation->value,
                'from_office_id' => $from->value, 'to_office_id' => $to->value];
            if ($opened['repeat']) {
                $this->handoffActor($actor);
                $this->scope->owner($id);
                return $result;
            }
            $this->handoffExpiry($ticket);
            $context = $this->handoffContext($current, $to, $opened['root']->playhead());
            if (!hash_equals($this->intent($ticket['context']), $this->intent($context))) {
                throw new ConcurrentModificationException('The reviewed hold, notes or office authority changed.');
            }
            $this->ledger->handoff(
                $opened['root'],
                $current,
                $operation,
                $actor,
                $context['from'],
                $context['to'],
                $intent,
                $ticket['reason']
            );
            $this->offices->move($id, $from, $to);
            $this->storeNote($operation, $id, $actor, $ticket['reason']);
            $this->audit->record($actor, 'restriction.office_changed', $id);
            $this->handoffAuthority($actor, $from, $to);
            $this->handoffExpiry($ticket);
            return $result;
        });
    }

    /** @return array<string,mixed> */
    private function handoffContext(Restriction $hold, EntityId $to, ?int $verifiedVersion = null): array
    {
        $office = $this->offices->owner($hold->id);
        if ($office === null) {
            throw new ValidationException([['field' => 'to_office_id',
                'message' => 'Route this earlier unassigned hold before requesting a handoff.']]);
        }
        $from = new EntityId($office['id']);
        $fromAuthority = $this->handoffOffice($from);
        $toAuthority = $this->handoffOffice($to);
        if ($from->value === $to->value || !(bool) ($this->offices->office($to)['active'] ?? false)) {
            throw new ValidationException([[
                'field' => 'to_office_id',
                'message' => 'Choose a different active office.',
            ]]);
        }
        $person = $this->admissions->person($hold->personId);
        $work = $this->offices->followUp($hold->id);
        if ($work === null) {
            throw new ConcurrentModificationException('The office routing changed during review.');
        }
        return ['hold' => $hold->facts(), 'version' => $verifiedVersion ?? $this->ledger->verify($hold->personId),
            'person' => ['id' => $person->id->value, 'name' => trim($person->givenName . ' ' . $person->familyName),
                'institutional_id' => $person->institutionalId->value],
            'from' => $fromAuthority, 'to' => $toAuthority, 'notes_count' => $this->repository->noteCount($hold->id),
            'follow_up' => ['assigned_user_id' => $work['assigned_user_id'], 'assignee_name' => $work['assignee_name'],
                'follow_up_on' => $work['follow_up_on'], 'work_revision' => (int) $work['work_revision']]];
    }

    private function handoffActor(?EntityId $expected = null): EntityId
    {
        $actor = $this->scope->actor();
        if (
            $this->access->requirePermission(\Domain\Office\Service\OfficeScope::HANDOFF)->value !== $actor->value
            || ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException('The handoff actor or authority changed.');
        }
        return $actor;
    }

    /**
     * Retain the actual dated delegation used, without converting reference setup into another journal.
     *
     * @return array<string,mixed>
     */
    private function handoffOffice(EntityId $office): array
    {
        $authority = $this->scope->office($office);
        $delegation = $authority['mode'] === 'delegated'
        ? $this->offices->delegation($office, $this->scope->actor()) : null;
        if (
            $authority['mode'] === 'delegated' && ($delegation === null
            || $delegation['id'] !== $authority['delegation_id']
            || (int) $delegation['revision'] !== $authority['delegation_revision'])
        ) {
            throw new ConcurrentModificationException('The office delegation changed during review.');
        }
        return $authority + [
            'starts_on' => $delegation['starts_on'] ?? null,
            'ends_on' => $delegation['ends_on'] ?? null,
        ];
    }

    private function handoffAuthority(EntityId $actor, EntityId $from, EntityId $to): void
    {
        $this->handoffActor($actor);
        $this->scope->office($from);
        $this->scope->office($to);
        if (!(bool) ($this->offices->office($to)['active'] ?? false)) {
            throw new ConcurrentModificationException('The receiving office was retired.');
        }
    }

    /** @param array<string,mixed> $ticket */
    private function handoffExpiry(array $ticket): void
    {
        $now = $this->clock->now()->getTimestamp();
        if ($now < $ticket['issued_at'] || $now > $ticket['expires_at']) {
            throw new ConcurrentModificationException('The handoff review expired. Review it again.');
        }
    }

    /** @return array<string,mixed> */
    private function handoffTicket(#[\SensitiveParameter] string $token, EntityId $actor, EntityId $id): array
    {
        try {
            if (strlen($token) > 100000) {
                throw new \InvalidArgumentException();
            }
            $p = json_decode($this->cipher->open($token), true, 16, JSON_THROW_ON_ERROR);
            if (
                !is_array($p) || ($p['purpose'] ?? null) !== 'hold.office-handoff'
                || !is_string($p['operation_id'] ?? null) || !is_string($p['reason'] ?? null)
                || !is_int($p['issued_at'] ?? null) || !is_int($p['expires_at'] ?? null)
                || !is_array($p['context'] ?? null) || ($p['restriction_id'] ?? null) !== $id->value
            ) {
                throw new \InvalidArgumentException();
            }
            new EntityId($p['operation_id']);
        } catch (\Throwable) {
            throw new ValidationException([['field' => 'review_token',
                'message' => 'This handoff review could not be verified. Review the handoff again.']]);
        }
        if (($p['actor_id'] ?? null) !== $actor->value) {
            throw new AccessDeniedException('This handoff review belongs to another account.');
        }
        return $p;
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
