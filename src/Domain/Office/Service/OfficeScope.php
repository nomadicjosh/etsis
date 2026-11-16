<?php

declare(strict_types=1);

namespace Domain\Office\Service;

use Domain\Office\Repository\OfficeRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\EventSourcing\HistoryReadLimitException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;

/** A dated office relationship narrows a capability; it never grants that capability. */
final readonly class OfficeScope
{
    public const string HOLD_MANAGE = 'sis:restrictions:manage';
    public const string ADMIN = 'admin:access:manage';
    public const int MAX_OFFICES = 100;

    public function __construct(
        private OfficeRepository $records,
        private StudentAccess $access,
        private Clock $clock,
    ) {
    }

    public function actor(): EntityId
    {
        $actor = $this->access->requirePermission(self::HOLD_MANAGE);
        if (!$this->records->userEnabled($actor)) {
            throw new AccessDeniedException('Sign-in access is required.');
        }
        return $actor;
    }

    public function administrator(): bool
    {
        $actor = $this->actor();
        try {
            return $this->access->requirePermission(self::ADMIN)->value === $actor->value;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    /** @return list<array<string,mixed>> */
    public function offices(): array
    {
        $actor = $this->actor();
        $rows = $this->records->offices(
            $this->administrator() ? null : $actor,
            $this->clock->now()->format('Y-m-d'),
            self::MAX_OFFICES + 1
        );
        if (count($rows) > self::MAX_OFFICES) {
            throw new HistoryReadLimitException();
        }
        return $rows;
    }

    /** Null denotes explicit administrative scope; an empty list grants no office access.
     * @return list<string>|null */
    public function visibleIds(): ?array
    {
        return $this->administrator() ? null : array_column($this->offices(), 'id');
    }

    /** @return array<string,mixed> Retained evidence, separate from mutable current delegation. */
    public function office(EntityId $id): array
    {
        $actor = $this->actor();
        $office = $this->records->office($id) ?? throw new RecordNotFoundException('Office not found.');
        $admin = $this->administrator();
        $delegate = $admin ? null : $this->records->delegation($id, $actor);
        $on = $this->clock->now()->format('Y-m-d');
        if (
            !$admin && ((int) $office['active'] !== 1 || $delegate === null
            || (int) $delegate['active'] !== 1 || $delegate['starts_on'] > $on
            || ($delegate['ends_on'] !== null && $delegate['ends_on'] < $on))
        ) {
            throw new AccessDeniedException('Current delegation to this office is required.');
        }
        return ['office_id' => $id->value, 'office_code' => $office['code'], 'office_name' => $office['name'],
            'mode' => $admin ? 'administrative' : 'delegated', 'delegation_id' => $delegate['id'] ?? null,
            'delegation_revision' => $delegate === null ? null : (int) $delegate['revision']];
    }

    public function owner(EntityId $restriction): ?EntityId
    {
        $this->actor();
        $office = $this->records->owner($restriction);
        if ($office === null) {
            if (!$this->administrator()) {
                throw new AccessDeniedException('An administrator must route this earlier hold first.');
            }
            return null;
        }
        $id = new EntityId($office['id']);
        $this->office($id);
        return $id;
    }
}
