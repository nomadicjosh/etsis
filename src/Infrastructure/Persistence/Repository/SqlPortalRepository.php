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
    public function __construct(private SqlRecords $sql, private \Domain\Shared\Service\Clock $clock)
    {
    }

    public function lock(EntityId $id): void
    {
        $this->sql->lock("sis_portal_posts", $id->value, "version");
    }

    public function receipt(EntityId $operation): ?array
    {
        $r = $this->sql->rows("sis_content_operations", ["id" => $operation->value], 1)[0] ?? null;
        return $r === null
        ? null
        : [
                "post_id" => $r["post_id"],
                "actor_id" => $r["actor_id"],
                "intent" => $r["intent"],
                "result" => json_decode($r["result"], true, 8, JSON_THROW_ON_ERROR),
            ];
    }

    public function saveReceipt(
        EntityId $operation,
        EntityId $post,
        EntityId $actor,
        string $intent,
        array $result,
    ): void {
        $this->sql->insert("sis_content_operations", [
            "id" => $operation->value,
            "post_id" => $post->value,
            "actor_id" => $actor->value,
            "intent" => $intent,
            "result" => json_encode($result, JSON_THROW_ON_ERROR),
        ]);
    }

    public function add(#[\SensitiveParameter] PortalPost $post): void
    {
        $this->sql->insert("sis_portal_posts", [
            "id" => $post->id->value,
            "owner_user_id" => $post->ownerUserId->value,
            "kind" => $post->kind,
            "code" => $post->code,
            "title" => $post->title,
            "content" => $post->content,
            "status" => $post->status,
            "version" => $post->version,
            "visible_from" => $post->visibleFrom,
            "visible_until" => $post->visibleUntil,
            "review_note" => $post->reviewNote,
            "content_format" => $post->contentFormat,
        ]);
    }

    public function get(EntityId $id): PortalPost
    {
        return $this->hydrate($this->sql->one("sis_portal_posts", ["id" => $id->value]));
    }

    public function published(string $code, EntityId $owner): PortalPost
    {
        $q = $this->sql->quote(...);
        $visibility = \Infrastructure\Persistence\SqlPortalVisibility::window(
            $this->sql,
            "p",
            $this->clock->now(),
        );
        $audience = new \Infrastructure\Persistence\SqlContentAudience($this->sql)->predicate($owner);
        $row =
        $this->sql->fetch(
            "SELECT p.* FROM " .
                    $q("sis_portal_posts") .
                    ' p LEFT JOIN ' . $q('sis_portal_audiences') . ' a ON a.' . $q('post_id')
                    . ' = p.' . $q('id') . " WHERE p." .
                    $q("code") .
                    " = ? AND p." .
                    $q("status") .
                    " = 'published' AND " .
                    $visibility["sql"] . ' AND ' . $audience['sql'],
            [$code, ...$visibility["parameters"], ...$audience['parameters']],
            1,
        )[0] ?? throw new \Domain\Shared\Exception\RecordNotFoundException("Content not found.");
        return $this->hydrate($row);
    }

    public function save(#[\SensitiveParameter] PortalPost $post, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                "sis_portal_posts",
                [
                    "status" => $post->status,
                    "version" => $post->version,
                    "visible_from" => $post->visibleFrom,
                    "visible_until" => $post->visibleUntil,
                    "review_note" => $post->reviewNote,
                    "content_format" => $post->contentFormat,
                ],
                ["id" => $post->id->value, "version" => $expectedVersion],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The portal post changed.");
        }
        if ($post->status === "published") {
            $this->sql->update(
                "sis_portal_audiences",
                [
                    "published_at" =>
                        $post->visibleFrom ??
                        $this->clock->now()->setTimezone(new \DateTimeZone("UTC"))->format("Y-m-d\TH:i:s\Z"),
                ],
                ["post_id" => $post->id->value],
            );
        }
    }

    public function revise(#[\SensitiveParameter] PortalPost $post, int $expectedVersion): void
    {
        if (
            $this->sql->update(
                "sis_portal_posts",
                [
                    "title" => $post->title,
                    "content" => $post->content,
                    "status" => "draft",
                    "version" => $expectedVersion + 1,
                    "visible_from" => null,
                    "visible_until" => null,
                    "review_note" => null,
                    "content_format" => $post->contentFormat,
                ],
                ["id" => $post->id->value, "version" => $expectedVersion],
            ) !== 1
        ) {
            throw new ConcurrentModificationException("The portal post changed.");
        }
    }

    /** @param array<string, mixed> $r */
    private function hydrate(array $r): PortalPost
    {
        return new PortalPost(
            new EntityId($r["id"]),
            new EntityId($r["owner_user_id"]),
            $r["kind"],
            $r["code"],
            $r["title"],
            $r["content"],
            $r["status"],
            (int) $r["version"],
            $r["visible_from"],
            $r["visible_until"],
            $r["review_note"],
            $r["content_format"],
        );
    }
}
