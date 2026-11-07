<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Portal\PortalPost;
use Domain\Portal\Repository\PortalRepository;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlPortalRepository implements PortalRepository
{
    public function __construct(private SqlRecords $sql)
    {
    }

    public function add(PortalPost $post): void
    {
        $this->sql->insert('sis_portal_posts', ['id' => $post->id->value, 'owner_user_id' => $post->ownerUserId->value,
            'kind' => $post->kind, 'code' => $post->code, 'title' => $post->title, 'content' => $post->content,
            'status' => $post->status, 'version' => $post->version]);
    }

    public function get(EntityId $id): PortalPost
    {
        return $this->hydrate($this->sql->one('sis_portal_posts', ['id' => $id->value]));
    }

    public function published(string $code): PortalPost
    {
        return $this->hydrate($this->sql->one('sis_portal_posts', ['code' => $code, 'status' => 'published']));
    }

    public function save(PortalPost $post, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                'sis_portal_posts',
                ['status' => $post->status, 'version' => $post->version],
                ['id' => $post->id->value, 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The portal post changed.');
        }
        if ($post->status === 'published') {
            $this->sql->update(
                'sis_portal_audiences',
                ['published_at' => gmdate('Y-m-d\TH:i:s\Z')],
                ['post_id' => $post->id->value]
            );
        }
    }

    public function revise(PortalPost $post, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                'sis_portal_posts',
                ['title' => $post->title, 'content' => $post->content,
                'status' => 'draft', 'version' => $expectedVersion + 1],
                ['id' => $post->id->value, 'version' => $expectedVersion]
            ) !== 1
        ) {
            throw new ConcurrentModificationException('The portal post changed.');
        }
    }

    /** @param array<string, mixed> $r */
    private function hydrate(array $r): PortalPost
    {
        return new PortalPost(
            new EntityId($r['id']),
            new EntityId($r['owner_user_id']),
            $r['kind'],
            $r['code'],
            $r['title'],
            $r['content'],
            $r['status'],
            (int) $r['version']
        );
    }
}
