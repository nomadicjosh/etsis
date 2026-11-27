<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Person\Repository\PersonAccountRepository;
use Domain\Communications\Service\MessageCipher;
use Domain\WebsiteForms\Exception\UnreadableInquiryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\Shared\Service\Clock;
use Domain\Shared\Exception\ValidationException;
use Domain\WebsiteForms\Repository\WebsiteFormInboxRepository;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use Throwable;

use function Codefy\Framework\Helpers\trans;

final readonly class WebsiteFormInboxService
{
    private const array DUTIES = ['sis:website:forms:read', 'sis:website:forms:manage'];

    public function __construct(
        private WebsiteFormInboxRepository $inbox,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private \Domain\Access\Repository\AccessRepository $roles,
        private WebsiteFormRoutingService $routing,
        private NewsletterService $newsletters,
        private PersonAccountRepository $people,
        private Clock $clock,
    ) {
    }

    private function authorize(bool $manage = false, ?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:website:forms:read');
        foreach (['sis:website:forms:read', ...$manage ? ['sis:website:forms:manage'] : []] as $duty) {
            if (!$this->roles->allows($actor->value, $duty)) {
                throw new AccessDeniedException();
            }
        }
        if (
            $this->access->requirePermission('sis:website:forms:read')->value !== $actor->value
            || ($manage && $this->access->requirePermission('sis:website:forms:manage')->value !== $actor->value)
            || ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException();
        }
        return $actor;
    }

    private function requireScope(EntityId $actor, EntityId $id): void
    {
        if (!$this->inbox->visible($actor, $id)) {
            throw new RecordNotFoundException();
        }
    }

    public function page(?EntityId $after = null, string $state = 'all', string $scope = 'all'): array
    {
        return $this->transactions->run(function () use ($after, $state, $scope): array {
            $actor = $this->authorize();
            if (
                !in_array($state, ['all', 'open', 'closed'], true)
                || !in_array($scope, ['all', 'mine', 'unassigned'], true)
            ) {
                throw new \InvalidArgumentException();
            }
            $this->roles->lock();
            $person = $scope === 'mine' ? $this->actorPerson($actor) : null;
            $rows = $this->inbox->page($actor, $after, $state, $scope, $person);
            foreach ($rows as $row) {
                $this->requireScope($actor, new EntityId($row['id']));
            }
            $this->authorize(expected: $actor);
            $canManage = false;
            try {
                $this->authorize(true, $actor);
                $canManage = true;
            } catch (AccessDeniedException) {
                $this->authorize(expected: $actor);
            }
            $items = array_slice($rows, 0, 20);
            $eligible = $this->eligibleAssignees($items);
            foreach ($items as &$row) {
                $row['version'] = (int) $row['version'];
                $row['assignee_name'] = $row['assignee_id'] === null ? trans('Unassigned')
                    : (!in_array($row['assignee_id'], $eligible, true) || !(bool) $row['assignee_scope']
                        || $row['given_name'] === null ? trans('Assigned account unavailable')
                        : trim($row['given_name'] . ' ' . $row['family_name']));
                unset(
                    $row['assignee_id'],
                    $row['assignee_person_id'],
                    $row['given_name'],
                    $row['family_name'],
                    $row['assignee_scope']
                );
            }
            unset($row);
            $this->authorize(expected: $actor);
            if ($person !== null && $this->actorPerson($actor)->value !== $person->value) {
                throw new AccessDeniedException();
            }
            if (
                $rows !== $this->inbox->page($actor, $after, $state, $scope, $person)
                || $eligible !== $this->eligibleAssignees(array_slice($rows, 0, 20))
            ) {
                throw new ConcurrentModificationException();
            }
            return ['items' => $items, 'next_after' => count($rows) > 20 ? $rows[19]['id'] : null,
                'can_manage' => $canManage];
        });
    }

    public function show(EntityId $id): array
    {
        $actor = $this->authorize();
        return $this->transactions->run(function () use ($id, $actor): array {
            $this->roles->lock();
            $row = $this->inbox->get($id) ?? throw new RecordNotFoundException();
            $this->requireScope($actor, $id);
            $assignment = $this->inbox->assignment($id);
            try {
                if (strlen($row['sealed_data']) > 524288) {
                    throw new \InvalidArgumentException();
                }
                $payload = json_decode($this->cipher->open($row['sealed_data']), true, 32, JSON_THROW_ON_ERROR);
                if ($payload['purpose'] !== 'website.form.inquiry') {
                    throw new \InvalidArgumentException();
                }
                foreach (
                    ['id', 'page_id', 'form_key', 'department_id', 'page_title', 'form_title',
                    'submitted_at'] as $key
                ) {
                    if ($payload[$key] !== $row[$key]) {
                        throw new \InvalidArgumentException();
                    }
                }
                $definition = new WebsiteFormDefinition($payload['definition']);
                $answers = $definition->answers($payload['answers']);
                if (
                    $definition->facts['key'] !== $row['form_key']
                    || $definition->facts['title'] !== $row['form_title']
                    || $definition->facts['department_id'] !== $row['department_id']
                ) {
                    throw new \InvalidArgumentException();
                }
            } catch (Throwable) {
                throw new UnreadableInquiryException();
            }
            $this->audit->record($actor, 'website.form_inquiry_read', $id);
            $this->authorize(expected: $actor);
            $this->requireScope($actor, $id);
            if ($this->inbox->get($id) !== $row) {
                throw new ConcurrentModificationException();
            }
            $work = $this->describeAssignment($actor, $row, $assignment);
            $this->authorize(expected: $actor);
            $this->requireScope($actor, $id);
            if ($this->inbox->assignment($id) !== $assignment) {
                throw new ConcurrentModificationException();
            }
            unset($row['sealed_data']);
            return $row + ['assignment' => $work, 'fields' => $definition->facts['fields'], 'answers' => $answers,
                'newsletter' => $this->newsletters->status($id)];
        });
    }

    private function eligibleAssignees(array $rows): array
    {
        $users = array_map(
            static fn (string $id): EntityId => new EntityId($id),
            array_values(array_unique(array_filter(array_column($rows, 'assignee_id'))))
        );
        $allowed = array_map(fn (string $duty): array => $this->roles->allowedUsers($users, $duty), self::DUTIES);
        return array_values(array_intersect(...$allowed));
    }

    private function actorPerson(EntityId $actor): EntityId
    {
        $person = $this->people->findByUser($actor);
        if ($person?->ownerUserId?->value !== $actor->value) {
            throw new AccessDeniedException();
        }
        return $person->id;
    }

    private function candidate(EntityId $id, ?EntityId $user, ?EntityId $person): void
    {
        if (($user === null) !== ($person === null)) {
            throw new ValidationException([['field' => 'assignee_id',
                'message' => trans('Choose an eligible colleague or explicitly clear the assignment.')]]);
        }
        if ($user === null) {
            return;
        }
        $current = $this->people->findByUser($user);
        if (
            $current?->id->value !== $person->value || $current?->ownerUserId?->value !== $user->value
            || $this->eligibleAssignees([['assignee_id' => $user->value]]) !== [$user->value]
            || !$this->inbox->visible($user, $id)
        ) {
            throw new ValidationException([['field' => 'assignee_id',
                'message' => trans('Choose an enabled colleague with read and manage access to this inquiry.')]]);
        }
    }

    private function describeAssignment(EntityId $actor, array $row, ?array $assignment): array
    {
        $user = isset($assignment['assignee_id']) ? new EntityId($assignment['assignee_id']) : null;
        $person = isset($assignment['assignee_person_id']) ? new EntityId($assignment['assignee_person_id']) : null;
        $name = trans('Unassigned');
        if ($user !== null) {
            try {
                $this->candidate(new EntityId($row['id']), $user, $person);
                $current = $this->people->findByUser($user);
                $name = trim($current->givenName . ' ' . $current->familyName);
            } catch (ValidationException) {
                $name = trans('Assigned account unavailable');
            }
        }
        $result = ['name' => $name, 'can_assign' => false];
        try {
            $this->authorize(true, $actor);
            $actorPerson = $this->actorPerson($actor);
        } catch (AccessDeniedException) {
            $this->authorize(expected: $actor);
            return $result;
        }
        return ['name' => $name, 'can_assign' => $row['status'] === 'open',
            'version' => (int) ($assignment['version'] ?? 0), 'inquiry_version' => (int) $row['version'],
            'assignee_id' => $user?->value, 'assignee_person_id' => $person?->value,
            'actor_id' => $actor->value, 'actor_person_id' => $actorPerson->value];
    }

    public function assignees(EntityId $id, string $search = '', ?EntityId $after = null): array
    {
        $actor = $this->authorize(true);
        $search = trim($search);
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1f\x7f]/', $search)
        ) {
            throw new ValidationException([['field' => 'search',
                'message' => trans('Search with up to eighty characters.')]]);
        }
        return $this->transactions->run(function () use ($actor, $id, $search, $after): array {
            $this->roles->lock();
            $this->requireScope($actor, $id);
            $row = $this->inbox->get($id) ?? throw new RecordNotFoundException();
            if ($row['status'] !== 'open') {
                throw new ConcurrentModificationException();
            }
            $records = mb_strlen($search) < 2 ? [] : $this->inbox->assignees($id, $search, $after);
            $this->authorize(true, $actor);
            $this->requireScope($actor, $id);
            if (
                $row !== $this->inbox->get($id)
                || ($records !== [] && $records !== $this->inbox->assignees($id, $search, $after))
            ) {
                throw new ConcurrentModificationException();
            }
            return ['items' => array_slice($records, 0, 20), 'next_after' => count($records) > 20
                ? $records[19]['id'] : null, 'search_required' => mb_strlen($search) < 2];
        });
    }

    public function assign(
        EntityId $id,
        int $version,
        int $inquiryVersion,
        ?EntityId $user,
        ?EntityId $person,
    ): array {
        $actor = $this->authorize(true);
        $actorPerson = $this->actorPerson($actor);
        if ($version < 0 || $version > 2147483646 || $inquiryVersion < 0) {
            throw new \InvalidArgumentException();
        }
        return $this->transactions->run(function () use (
            $id,
            $version,
            $inquiryVersion,
            $user,
            $person,
            $actor,
            $actorPerson
        ): array {
            $this->roles->lock();
            $this->inbox->lock($id);
            $row = $this->inbox->get($id) ?? throw new RecordNotFoundException();
            $this->requireScope($actor, $id);
            $before = $this->inbox->assignment($id);
            if (
                $row['status'] !== 'open' || (int) $row['version'] !== $inquiryVersion
                || (int) ($before['version'] ?? 0) !== $version
            ) {
                throw new ConcurrentModificationException(trans(
                    'The inquiry or assignment changed. Reload it before saving.'
                ));
            }
            $this->candidate($id, $user, $person);
            $expected = $before;
            if (
                ($before['assignee_id'] ?? null) !== $user?->value
                || ($before['assignee_person_id'] ?? null) !== $person?->value
            ) {
                $at = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
                $this->inbox->assign($id, $version, $user, $person, $at);
                $expected = ['id' => $id->value, 'version' => $version + 1,
                    'assignee_id' => $user?->value, 'assignee_person_id' => $person?->value, 'updated_at' => $at];
                $this->audit->record($actor, 'website.inquiry_assigned', $id);
            }
            $this->authorize(true, $actor);
            if ($this->actorPerson($actor)->value !== $actorPerson->value) {
                throw new AccessDeniedException();
            }
            $this->requireScope($actor, $id);
            $this->candidate($id, $user, $person);
            if ($this->inbox->get($id) !== $row || $this->inbox->assignment($id) != $expected) {
                throw new ConcurrentModificationException();
            }
            return $this->describeAssignment($actor, $row, $expected);
        });
    }

    public function retryNewsletter(EntityId $id, int $version): array
    {
        $actor = $this->authorize(true);
        return $this->transactions->run(function () use ($actor, $id, $version): array {
            $this->roles->lock();
            $this->inbox->lock($id);
            $this->inbox->get($id) ?? throw new RecordNotFoundException();
            $this->requireScope($actor, $id);
            $this->newsletters->requeue($id, $version);
            $this->audit->record($actor, 'website.newsletter_retry_queued', $id);
            $this->authorize(true, $actor);
            $this->requireScope($actor, $id);
            return ['queued' => true];
        });
    }

    public function change(EntityId $id, int $version, string $action): array
    {
        $actor = $this->authorize(true);
        if (!in_array($action, ['close', 'reopen', 'delete'], true) || $version < 0) {
            throw new \InvalidArgumentException();
        }
        return $this->transactions->run(function () use ($id, $version, $action, $actor): array {
            $this->roles->lock();
            $this->inbox->lock($id);
            $row = $this->inbox->get($id) ?? throw new RecordNotFoundException();
            $this->requireScope($actor, $id);
            if ((int) $row['version'] !== $version) {
                throw new ConcurrentModificationException();
            }
            $status = $action === 'close' ? 'closed' : 'open';
            if ($action === 'delete') {
                $this->newsletters->remove($id);
                $this->inbox->delete($id, $version);
            } else {
                $this->inbox->update($id, $version, $status);
            }
            $this->audit->record($actor, 'website.form_inquiry_' . $action, $id);
            $this->authorize(true, $actor);
            $this->routing->requireScope($actor, $row);
            $expected = $action === 'delete' ? null : array_replace(
                $row,
                ['status' => $status, 'version' => $version + 1]
            );
            $actual = $this->inbox->get($id);
            if ($actual !== null) {
                $actual['version'] = (int) $actual['version'];
            }
            if ($actual != $expected) {
                throw new ConcurrentModificationException();
            }
            return ['id' => $id->value, 'deleted' => $action === 'delete'];
        });
    }
}
