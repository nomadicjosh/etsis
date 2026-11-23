<?php

declare(strict_types=1);

namespace Infrastructure\Persistence\Repository;

use Domain\Portal\Repository\AnnouncementRepository;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\SqlPortalVisibility;
use Infrastructure\Persistence\SqlRecords;

final readonly class SqlAnnouncementRepository implements AnnouncementRepository
{
    public function __construct(private SqlRecords $sql, private Clock $clock)
    {
    }

    public function page(EntityId $owner, bool $unreadOnly, ?EntityId $after): array
    {
        $q = $this->sql->quote(...);
        $window = SqlPortalVisibility::window($this->sql, 'p', $this->clock->now());
        $where = ["p." . $q('kind') . " = 'news'", "p." . $q('status') . " = 'published'",
            "a." . $q('audience') . " = 'campus'", $window['sql']];
        $parameters = [$owner->value, ...$window['parameters']];
        if ($unreadOnly) {
            $where[] = '(r.' . $q('revision') . ' IS NULL OR r.' . $q('revision') . ' <> p.' . $q('version') . ')';
        }
        if ($after !== null) {
            $where[] = 'p.' . $q('id') . ' < ?';
            $parameters[] = $after->value;
        }
        return $this->sql->fetch(
            'SELECT p.' . $q('id') . ', p.' . $q('title') . ', p.' . $q('version') . ' AS ' . $q('revision')
            . ', r.' . $q('revision') . ' AS ' . $q('read_revision')
            . ' FROM ' . $q('sis_portal_posts') . ' p JOIN ' . $q('sis_portal_audiences') . ' a ON a.'
            . $q('post_id') . ' = p.' . $q('id') . ' LEFT JOIN ' . $q('sis_announcement_reads') . ' r ON r.'
            . $q('post_id') . ' = p.' . $q('id') . ' AND r.' . $q('owner_user_id') . ' = ?'
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.' . $q('id') . ' DESC',
            $parameters,
            21,
        );
    }

    public function available(EntityId $post): bool
    {
        $q = $this->sql->quote(...);
        $window = SqlPortalVisibility::window($this->sql, 'p', $this->clock->now());
        return $this->sql->fetch(
            'SELECT p.' . $q('id') . ' FROM ' . $q('sis_portal_posts') . ' p JOIN '
            . $q('sis_portal_audiences') . ' a ON a.' . $q('post_id') . ' = p.' . $q('id')
            . ' WHERE p.' . $q('id') . ' = ? AND p.' . $q('kind') . " = 'news' AND p."
            . $q('status') . " = 'published' AND a." . $q('audience') . " = 'campus' AND " . $window['sql'],
            [$post->value, ...$window['parameters']],
            1,
        ) !== [];
    }

    public function readState(EntityId $owner, EntityId $post): ?array
    {
        return $this->sql->rows('sis_announcement_reads', [
            'owner_user_id' => $owner->value, 'post_id' => $post->value,
        ], 1)[0] ?? null;
    }

    public function markRead(EntityId $owner, EntityId $post, int $revision, string $at): void
    {
        $criteria = ['owner_user_id' => $owner->value, 'post_id' => $post->value];
        $state = ['revision' => $revision, 'read_at' => $at];
        if ($this->readState($owner, $post) === null) {
            $this->sql->insert('sis_announcement_reads', ['id' => EntityId::generate()->value] + $criteria + $state);
        } else {
            $this->sql->update('sis_announcement_reads', $state, $criteria);
        }
    }
}
