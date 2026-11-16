<?php

declare(strict_types=1);

namespace Domain\Office\Service;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Office\Repository\OfficeRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;

final readonly class OfficeService
{
    public function __construct(
        private OfficeRepository $records,
        private OfficeScope $scope,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private \Domain\Shared\Service\Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function choices(): array
    {
        try {
            $actor = $this->access->requirePermission(OfficeScope::ADMIN);
            $admin = true;
        } catch (AccessDeniedException) {
            $actor = $this->scope->actor();
            $admin = false;
        }
        $items = $admin ? $this->records->offices(null, '', OfficeScope::MAX_OFFICES + 1) : $this->scope->offices();
        if (count($items) > OfficeScope::MAX_OFFICES) {
            throw new \Domain\Shared\EventSourcing\HistoryReadLimitException();
        }
        $this->checkActor($actor, $admin);
        return ['items' => $items, 'can_administer' => $admin, 'today' => $this->clock->now()->format('Y-m-d')];
    }

    public function save(EntityId $id, string $code, string $name, bool $active, int $revision): void
    {
        $actor = $this->access->requirePermission(OfficeScope::ADMIN);
        $code = mb_strtoupper(trim($code));
        $name = trim($name);
        if (preg_match('~^[A-Z0-9][A-Z0-9._/-]{0,21}$~D', $code) !== 1) {
            throw new ValidationException([['field' => 'code',
                'message' => 'Use a short office code of up to 22 characters.']]);
        }
        if (!$this->text($name, 120)) {
            throw new ValidationException([['field' => 'name',
                'message' => 'Enter an office name of up to 120 characters.']]);
        }
        $this->transactions->run(function () use ($actor, $id, $code, $name, $active, $revision): void {
            $old = $this->records->office($id);
            if ($old !== null) {
                $this->records->lock($id);
                $old = $this->records->office($id);
            }
            $this->checkActor($actor);
            if (($old === null ? -1 : (int) $old['revision']) !== $revision) {
                throw new ConcurrentModificationException('Reload this office before saving.');
            }
            $this->records->saveOffice(['id' => $id->value, 'code' => $code, 'name' => $name,
                'active' => (int) $active, 'revision' => $revision + 1], $old === null);
            $this->audit->record($actor, $old === null ? 'office.created' : 'office.updated', $id);
            $this->checkActor($actor);
        });
    }

    /** @return array<string,mixed> */
    public function delegates(EntityId $office, ?EntityId $after = null): array
    {
        $actor = $this->access->requirePermission(OfficeScope::ADMIN);
        $row = $this->records->office($office) ?? throw new RecordNotFoundException('Office not found.');
        $rows = $this->records->delegates($office, $after, 51);
        $more = count($rows) > 50;
        $items = array_slice($rows, 0, 50);
        $this->checkActor($actor);
        return ['office' => $row, 'items' => $items, 'next_after' => $more ? $items[49]['id'] : null,
            'today' => $this->clock->now()->format('Y-m-d')];
    }

    public function delegate(
        EntityId $office,
        EntityId $user,
        DateTimeImmutable $start,
        bool $active,
        int $revision,
        ?DateTimeImmutable $end = null,
    ): void {
        $actor = $this->access->requirePermission(OfficeScope::ADMIN);
        new DateRange($start, $end);
        $this->transactions->run(function () use ($actor, $office, $user, $start, $end, $active, $revision): void {
            $this->records->lock($office);
            $this->checkActor($actor);
            if (!$this->records->userEnabled($user)) {
                throw new ValidationException([['field' => 'user_id',
                'message' => 'Choose an enabled sign-in account.']]);
            }
            $old = $this->records->delegation($office, $user);
            if (($old === null ? -1 : (int) $old['revision']) !== $revision) {
                throw new ConcurrentModificationException('Reload the delegation before changing it.');
            }
            $id = $old === null ? EntityId::generate() : new EntityId($old['id']);
            $this->records->saveDelegation(['id' => $id->value, 'office_id' => $office->value,
                'user_id' => $user->value, 'starts_on' => $start->format('Y-m-d'), 'ends_on' => $end?->format('Y-m-d'),
                'active' => (int) $active, 'revision' => $revision + 1], $old === null);
            $this->audit->record($actor, 'office.delegation_saved', $id);
            $this->checkActor($actor);
        });
    }

    /** @return array{items:list<array<string,mixed>>} */
    public function people(string $search, bool $accountsOnly = false): array
    {
        $actor = $accountsOnly ? $this->access->requirePermission(OfficeScope::ADMIN) : $this->scope->actor();
        if (!$this->text(trim($search), 80) || mb_strlen(trim($search)) < 2) {
            throw new ValidationException([['field' => 'search',
                'message' => 'Enter at least two characters to search.']]);
        }
        if (!$accountsOnly && $this->scope->visibleIds() === []) {
            throw new AccessDeniedException('Office delegation is required before finding hold subjects.');
        }
        $items = $this->records->people(trim($search), $accountsOnly, 25);
        $this->checkActor($actor, $accountsOnly);
        if (!$accountsOnly && $this->scope->visibleIds() === []) {
            throw new AccessDeniedException('Current office delegation is required.');
        }
        return ['items' => $items];
    }

    private function checkActor(EntityId $actor, bool $admin = true): void
    {
        $current = $admin ? $this->access->requirePermission(OfficeScope::ADMIN) : $this->scope->actor();
        if ($current->value !== $actor->value) {
            throw new AccessDeniedException('The office action actor changed.');
        }
    }

    private function text(string $value, int $max): bool
    {
        return $value !== '' && mb_check_encoding($value, 'UTF-8') && mb_strlen($value) <= $max
        && !preg_match('/[\x00-\x1F\x7F]/', $value);
    }
}
