<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\PortalPost;
use Domain\Portal\Repository\CampusRepository;
use Domain\Portal\Repository\PortalRepository;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

final readonly class CampusContentService
{
    public function __construct(
        private PortalRepository $posts,
        private CampusRepository $campus,
        private PortalService $portal,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit
    ) {
    }

    /** @return array<string, mixed> */
    public function get(EntityId $id): array
    {
        $this->access->requirePermission('sis:portal:manage');
        $post = $this->posts->get($id);
        $this->access->requireOwnerOrPermission($post->ownerUserId, 'sis:portal:admin');
        return ['id' => $id->value, 'kind' => $post->kind, 'code' => $post->code, 'title' => $post->title,
            'content' => $post->content, 'status' => $post->status, 'version' => $post->version,
            'audience' => $this->campus->audience($id)];
    }

    public function save(
        ?EntityId $id,
        string $kind,
        string $code,
        string $title,
        string $content,
        string $audience,
        ?int $version
    ): EntityId {
        if (!in_array($audience, ['public', 'campus'], true)) {
            throw new InvalidArgumentException('Choose a public or campus audience.');
        }
        return $this->transactions->run(function () use (
            $id,
            $kind,
            $code,
            $title,
            $content,
            $audience,
            $version
        ): EntityId {
            $actor = $this->access->requirePermission('sis:portal:manage');
            if ($id === null) {
                $post = $this->portal->create($kind, $code, $title, $content);
                $id = $post->id;
            } else {
                $existing = $this->posts->get($id);
                $this->access->requireOwnerOrPermission($existing->ownerUserId, 'sis:portal:admin');
                if ($kind !== $existing->kind || $code !== $existing->code || $version === null) {
                    throw new InvalidArgumentException('Keep the original content type and code when editing.');
                }
                $post = new PortalPost(
                    $id,
                    $existing->ownerUserId,
                    $kind,
                    $code,
                    $title,
                    $content,
                    'draft',
                    $version + 1
                );
                $this->posts->revise($post, $version);
                $this->audit->record($actor, 'portal.revised', $id);
            }
            $this->campus->setAudience($id, $audience);
            return $id;
        });
    }
}
