<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\PortalPost;
use Domain\Portal\Repository\PortalRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class PortalService
{
    public function __construct(
        private PortalRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit
    ) {
    }

    public function create(string $kind, string $code, string $title, string $content): PortalPost
    {
        $actor = $this->access->requirePermission('sis:portal:manage');
        $post = new PortalPost(EntityId::generate(), $actor, $kind, $code, $title, $content);
        $this->transactions->run(function () use ($post, $actor): void {
            $this->repository->add($post);
            $this->audit->record($actor, 'portal.created', $post->id);
        });

        return $post;
    }

    public function publish(EntityId $id, bool $publish, int $expectedVersion): void
    {
        $actor = $this->access->requirePermission('sis:portal:manage');
        $post = $this->repository->get($id);
        $this->access->requireOwnerOrPermission($post->ownerUserId, 'sis:portal:admin');
        if ($post->version !== $expectedVersion) {
            throw new ConcurrentModificationException('Reload the current content before publishing.');
        }
        $this->transactions->run(function () use ($post, $publish, $expectedVersion, $actor): void {
            $this->repository->save($post->publish($publish), $expectedVersion);
            $this->audit->record($actor, $publish ? 'portal.published' : 'portal.archived', $post->id);
        });
    }

    /** Publishing explicitly shares institution content with all authenticated portal users. */
    public function published(string $code): PortalPost
    {
        $this->access->authenticatedUserId();

        return $this->repository->published($code);
    }
}
