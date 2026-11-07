<?php

declare(strict_types=1);

namespace Domain\Rules\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Rules\Repository\RuleRepository;
use Domain\Rules\RuleSet;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class RuleService
{
    public const array FIELDS = ['program_id' => 'string', 'term_id' => 'string', 'active' => 'boolean'];

    public function __construct(
        private RuleRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit
    ) {
    }

    /** @param array<string, mixed> $tree */
    public function save(string $name, array $tree): EntityId
    {
        $actor = $this->access->requirePermission('sis:rules:manage');
        if (trim($name) === '' || mb_strlen($name) > 191 || preg_match('/[\x00-\x1f\x7f]/', $name)) {
            throw new InvalidArgumentException('A rule name is required.');
        }
        $rules = new RuleSet($tree, self::FIELDS);
        $id = EntityId::generate();
        $this->transactions->run(function () use ($id, $actor, $name, $rules): void {
            $this->repository->save($id, $actor, $name, $rules);
            $this->audit->record($actor, 'rules.created', $id);
        });

        return $id;
    }

    /** @return array<string, mixed> */
    public function get(EntityId $id): array
    {
        $this->access->requirePermission('sis:rules:manage');
        $record = $this->repository->get($id);
        $this->access->requireOwnerOrPermission(new EntityId($record['owner_user_id']), 'admin:access:manage');

        return $record;
    }

    /**
     * @param array<string, mixed> $tree
     * @return list<array<string, mixed>>
     */
    public function preview(array $tree): array
    {
        $actor = $this->access->requirePermission('sis:rules:run');
        $this->access->requirePermission('sis:student:read');
        $rules = new RuleSet($tree, self::FIELDS);
        $this->audit->record($actor, 'rules.previewed', $actor);

        return $this->repository->students($rules);
    }
}
