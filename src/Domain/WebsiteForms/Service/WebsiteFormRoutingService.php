<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Service;

use Domain\Access\Repository\AccessRepository;
use Domain\Audit\Repository\AuditTrail;
use Domain\Catalog\Repository\ClassificationRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Repository\WebsiteFormRoutingRepository;

use function Codefy\Framework\Helpers\trans_html;

/** Current website inbox membership is security configuration, separate from HR appointments. */
final readonly class WebsiteFormRoutingService
{
    public function __construct(
        private WebsiteFormRoutingRepository $records,
        private ClassificationRepository $departments,
        private AccessRepository $roles,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
    ) {
    }

    private function administrator(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('admin:access:manage');
        if (
            !$this->roles->allows($actor->value, 'admin:access:manage')
            || ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    public function authorizeAdministration(): void
    {
        $this->administrator();
    }

    public function canAdminister(): bool
    {
        try {
            $this->administrator();
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }

    public function validateDepartment(?string $id): void
    {
        if ($id === null) {
            return;
        }
        $row = $this->departments->peek(new EntityId($id));
        if ($row['kind'] !== 'department' || !$row['active']) {
            throw new ValidationException([['field' => 'department_id',
                'message' => trans_html('Choose an active department for website replies.')]]);
        }
    }

    public function requireScope(EntityId $actor, array $row): void
    {
        if ($row['department_id'] !== null) {
            $this->validateDepartment($row['department_id']);
            $kind = 'department';
            $scope = $row['department_id'];
            $key = '';
        } else {
            $kind = 'form';
            $scope = $row['page_id'];
            $key = $row['form_key'];
        }
        if (!$this->records->permits($actor, $kind, $scope, $key)) {
            throw new RecordNotFoundException();
        }
    }

    public function choices(string $kind, string $search, ?string $after = null): array
    {
        // Editors may choose delivery departments; account/form access setup remains administrator-only.
        $admin = $this->canAdminister();
        $actor = $kind === 'department' && !$admin
        ? $this->access->requirePermission('vihzhuo:manage') : $this->administrator();
        if (mb_strlen($search) > 80 || preg_match('/[\x00-\x1f\x7f]/', $search)) {
            throw new \InvalidArgumentException();
        }
        $rows = match ($kind) {
            'department' => $this->records->departments($search, $after === null ? null : new EntityId($after)),
            'page' => $this->records->pages($search, $after),
            'account' => $this->roles->eligibleAccounts(['sis:website:forms:read'], $search,
                $after === null ? null : new EntityId($after)),
            default => throw new \InvalidArgumentException(),
        };
        if ($kind === 'department' && !$admin) {
            if ($this->access->requirePermission('vihzhuo:manage')->value !== $actor->value) {
                throw new AccessDeniedException();
            }
        } else {
            $this->administrator($actor);
        }
        $rows = array_slice($rows, 0, 21);
        return ['items' => array_slice($rows, 0, 20), 'next_after' => count($rows) > 20 ? $rows[19]['id'] : null];
    }

    public function forms(string $page): array
    {
        $actor = $this->administrator();
        $rows = $this->records->forms($page);
        $this->administrator($actor);
        return ['items' => $rows];
    }

    private function scope(string $kind, string $scope, string $key, bool $adding): void
    {
        if ($kind === 'department' && $key === '') {
            if ($adding) {
                $this->validateDepartment($scope);
            } else {
                new EntityId($scope);
            }
            return;
        }
        if (
            $kind !== 'form' || $scope === '' || strlen($scope) > 191
            || preg_match('/^[a-z][a-z0-9_]{0,39}$/D', $key) !== 1
        ) {
            throw new \InvalidArgumentException();
        }
        if ($adding && !in_array($key, array_column($this->records->forms($scope), 'key'), true)) {
            throw new RecordNotFoundException();
        }
    }

    public function members(string $kind, string $scope, string $key, ?EntityId $after = null): array
    {
        $actor = $this->administrator();
        $this->scope($kind, $scope, $key, false);
        $rows = $this->records->members($kind, $scope, $key, $after);
        $this->administrator($actor);
        return ['items' => array_slice($rows, 0, 20), 'next_after' => count($rows) > 20 ? $rows[19]['id'] : null];
    }

    public function assign(
        string $kind,
        string $scope,
        string $key,
        EntityId $user,
        bool $grant,
        ?EntityId $expected
    ): void {
        $actor = $this->administrator();
        $this->transactions->run(function () use ($kind, $scope, $key, $user, $grant, $expected, $actor): void {
            // The same lock serializes role changes, inbox reads/writes and department/form membership changes.
            $this->roles->lock();
            $this->scope($kind, $scope, $key, $grant);
            $current = $this->records->grant($kind, $scope, $key, $user);
            if (($current['id'] ?? null) !== $expected?->value || $grant === ($current !== null)) {
                throw new ConcurrentModificationException();
            }
            if ($grant && !$this->roles->allows($user->value, 'sis:website:forms:read')) {
                throw new ValidationException([['field' => 'user_id',
                    'message' => trans_html('Choose an enabled account with website form read permission first.')]]);
            }
            $id = $this->records->save($kind, $scope, $key, $user, $grant);
            $this->audit->record($actor, $grant ? 'website.form_access_granted' : 'website.form_access_removed', $id);
            $this->administrator($actor);
            if ($this->records->permits($user, $kind, $scope, $key) !== $grant) {
                throw new ConcurrentModificationException();
            }
        });
    }
}
