<?php

declare(strict_types=1);

namespace Domain\Access\Service;

use DomainException;
use Domain\Access\AccessDefinition;
use Domain\Access\Enum\DefinitionKind;
use Domain\Access\Enum\GrantKind;
use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class AccessService
{
    public const string MANAGE = 'admin:access:manage';

    public function __construct(
        private AccessRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    /** @return list<AccessDefinition> */
    public function definitions(DefinitionKind $kind, ?EntityId $after = null, int $limit = 100): array
    {
        $this->access->requirePermission(self::MANAGE);
        $this->limit($limit);

        return $this->repository->definitions($kind, $after, $limit);
    }

    /** @return array{items: list<array<string, mixed>>, next_after: ?string} */
    public function directory(
        DefinitionKind $kind,
        string $search = '',
        ?EntityId $after = null,
        int $limit = 50,
        ?GrantKind $grant = null,
        ?EntityId $subject = null,
        bool $assignedOnly = false
    ): array {
        $actor = $this->access->requirePermission(self::MANAGE);
        $this->limit($limit);
        if (
            mb_strlen($search) > 80 || preg_match('/[\x00-\x1f\x7f]/', $search)
            || (($grant === null) !== ($subject === null)) || ($assignedOnly && $grant === null)
        ) {
            throw new InvalidArgumentException('Invalid access directory filter.');
        }
        if ($grant !== null && $subject !== null) {
            if (($grant === GrantKind::UserRole) !== ($kind === DefinitionKind::Role)) {
                throw new InvalidArgumentException('The grant must match the definition kind.');
            }
            $this->subject($grant, $subject);
        }
        $editable = $grant !== GrantKind::RolePermission || ($subject !== null
            && !$this->repository->definition(DefinitionKind::Role, $subject)->isSystem);
        $superActor = $grant !== GrantKind::UserRole || $this->repository->isSuper($actor->value);
        $rows = $this->repository->directory($kind, trim($search), $after, $limit + 1, $grant, $subject, $assignedOnly);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        foreach ($rows as &$row) {
            $row['assignable'] = $editable && ($superActor || $row['name'] !== 'super');
            foreach (['is_system', 'assigned', 'effective'] as $flag) {
                if (array_key_exists($flag, $row)) {
                    $row[$flag] = (bool) $row[$flag];
                }
            }
        }
        unset($row);

        return ['items' => $rows, 'next_after' => $more ? (string) $rows[$limit - 1]['id'] : null];
    }

    public function create(DefinitionKind $kind, string $name, string $description = ''): AccessDefinition
    {
        return $this->transactions->run(function () use ($kind, $name, $description): AccessDefinition {
            $this->repository->lock();
            $actor = $this->access->requirePermission(self::MANAGE);
            $definition = new AccessDefinition(EntityId::generate(), $name, $description);
            $this->repository->add($kind, $definition);
            $this->audit->record($actor, 'access.' . $kind->value . '.created', $definition->id);

            return $definition;
        });
    }

    public function updateDescription(DefinitionKind $kind, EntityId $id, string $description): void
    {
        $this->transactions->run(function () use ($kind, $id, $description): void {
            $this->repository->lock();
            $actor = $this->access->requirePermission(self::MANAGE);
            $existing = $this->repository->definition($kind, $id);
            $this->editable($existing);
            new AccessDefinition($id, $existing->name, $description);
            $this->repository->updateDescription($kind, $id, $description);
            $this->audit->record($actor, 'access.' . $kind->value . '.updated', $id);
        });
    }

    public function delete(DefinitionKind $kind, EntityId $id): void
    {
        $this->transactions->run(function () use ($kind, $id): void {
            $this->repository->lock();
            $actor = $this->access->requirePermission(self::MANAGE);
            $this->editable($this->repository->definition($kind, $id));
            $this->repository->remove($kind, $id);
            if (!$this->repository->anyoneCanManage()) {
                throw new DomainException('The final access administrator cannot be removed.');
            }
            $this->audit->record($actor, 'access.' . $kind->value . '.deleted', $id);
        });
    }

    private function editable(AccessDefinition $definition): void
    {
        if ($definition->isSystem) {
            throw new DomainException('System roles and permissions cannot be edited or deleted.');
        }
    }

    /** @return list<string> */
    public function assignments(
        GrantKind $kind,
        EntityId $subject,
        ?EntityId $after = null,
        int $limit = 100,
    ): array {
        $this->access->requirePermission(self::MANAGE);
        $this->limit($limit);
        $this->subject($kind, $subject);

        return $this->repository->assignments($kind, $subject, $after, $limit);
    }

    public function assign(GrantKind $kind, EntityId $subject, EntityId $target, bool $grant): void
    {
        $this->transactions->run(function () use ($kind, $subject, $target, $grant): void {
            // Serialize before checking authority so a concurrent revocation cannot authorize a stale write.
            $this->repository->lock();
            $actor = $this->access->requirePermission(self::MANAGE);
            $this->subject($kind, $subject);
            $this->repository->definition(
                $kind === GrantKind::UserRole ? DefinitionKind::Role : DefinitionKind::Permission,
                $target,
            );
            if ($kind === GrantKind::RolePermission) {
                $this->editable($this->repository->definition(DefinitionKind::Role, $subject));
            }
            $superAssignment = $kind === GrantKind::UserRole
                && $this->repository->definition(DefinitionKind::Role, $target)->name === 'super';
            if ($superAssignment && !$this->repository->isSuper($actor->value)) {
                throw new AccessDeniedException('Only a super administrator can assign or remove the super role.');
            }
            $this->repository->assign($kind, $subject, $target, $grant);
            if (!$grant && $superAssignment && !$this->repository->anySuper()) {
                throw new DomainException('The final super administrator cannot be removed.');
            }
            if (!$grant && !$this->repository->anyoneCanManage()) {
                throw new DomainException('The final access administrator cannot be removed.');
            }
            $action = $grant ? 'granted' : 'revoked';
            $this->audit->record($actor, 'access.' . $kind->value . '.' . $action, $subject);
            $this->audit->record($actor, 'access.' . $kind->value . '.' . $action . '.target', $target);
        });
    }

    private function subject(GrantKind $kind, EntityId $id): void
    {
        if ($kind === GrantKind::RolePermission) {
            $this->repository->definition(DefinitionKind::Role, $id);
        } else {
            $this->repository->requireUser($id);
        }
    }

    private function limit(int $limit): void
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Access lists are limited to 1–100 records.');
        }
    }
}
