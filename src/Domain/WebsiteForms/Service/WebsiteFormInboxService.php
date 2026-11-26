<?php

declare(strict_types=1);

namespace Domain\WebsiteForms\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\WebsiteForms\Exception\UnreadableInquiryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use Domain\WebsiteForms\Repository\WebsiteFormInboxRepository;
use Domain\WebsiteForms\ValueObject\WebsiteFormDefinition;
use Throwable;

final readonly class WebsiteFormInboxService
{
    public function __construct(
        private WebsiteFormInboxRepository $inbox,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private \Domain\Access\Repository\AccessRepository $roles,
        private WebsiteFormRoutingService $routing,
        private NewsletterService $newsletters,
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

    public function page(?EntityId $after = null, string $state = 'all'): array
    {
        return $this->transactions->run(function () use ($after, $state): array {
            $actor = $this->authorize();
            if (!in_array($state, ['all', 'open', 'closed'], true)) {
                throw new \InvalidArgumentException();
            }
            $this->roles->lock();
            $rows = $this->inbox->page($actor, $after, $state);
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
            return ['items' => array_map(static function (array $row): array {
                $row['version'] = (int) $row['version'];
                return $row;
            }, array_slice($rows, 0, 20)), 'next_after' => count($rows) > 20 ? $rows[19]['id'] : null,
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
            unset($row['sealed_data']);
            return $row + ['fields' => $definition->facts['fields'], 'answers' => $answers,
                'newsletter' => $this->newsletters->status($id)];
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
