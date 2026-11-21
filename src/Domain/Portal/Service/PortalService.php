<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\PortalPost;
use Domain\Portal\Repository\PortalRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;

final readonly class PortalService
{
    public function __construct(
        private PortalRepository $repository,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private ContentMarkup $markup,
    ) {
    }

    public function create(
        string $kind,
        string $code,
        string $title,
        #[\SensitiveParameter] string $content,
        string $contentFormat = "plain",
    ): PortalPost {
        $actor = $this->access->requirePermission("sis:portal:manage");
        if ($contentFormat === "html") {
            $content = $this->markup->sanitize($content);
        }
        PortalPost::requireWritableContent($content);
        $post = new PortalPost(
            EntityId::generate(),
            $actor,
            $kind,
            $code,
            $title,
            $content,
            contentFormat: $contentFormat,
        );
        $this->transactions->run(function () use ($post, $actor): void {
            $this->repository->add($post);
            $this->audit->record($actor, "portal.created", $post->id);
            if (
                $this->access->requirePermission("sis:portal:manage")->value !== $actor->value ||
                $this->access->authenticatedUserId()->value !== $actor->value ||
                $this->repository->get($post->id) != $post
            ) {
                throw new \Domain\Shared\Exception\ConcurrentModificationException(
                    "The content creator or draft changed.",
                );
            }
        });

        return $post;
    }

    /** Publishing explicitly shares institution content with all authenticated portal users. */
    public function published(string $code): PortalPost
    {
        $this->access->authenticatedUserId();

        return $this->repository->published($code);
    }
}
