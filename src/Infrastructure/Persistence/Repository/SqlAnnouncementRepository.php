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
        $parameters = [$owner->value, $owner->value, ...$window['parameters']];
        $where[] = '(d.' . $q('revision') . ' IS NULL OR d.' . $q('revision') . ' < p.' . $q('version') . ')';
        if ($unreadOnly) {
            $where[] = '(r.' . $q('revision') . ' IS NULL OR r.' . $q('revision') . ' <> p.' . $q('version') . ')';
        }
        if ($after !== null) {
            if (!$this->available($after)) {
                throw new \Domain\Shared\Exception\ConcurrentModificationException(
                    \Codefy\Framework\Helpers\trans('Announcements changed. Refresh your notifications.'),
                );
            }
            $audience = $this->sql->rows('sis_portal_audiences', ['post_id' => $after->value], 1)[0] ?? null;
            $cutoff = $audience['published_at'] ?? '';
            $date = 'COALESCE(a.' . $q('published_at') . ", '')";
            $where[] = '(' . $date . ' < ? OR (' . $date . ' = ? AND p.' . $q('id') . ' < ?))';
            array_push($parameters, $cutoff, $cutoff, $after->value);
        }
        return $this->sql->fetch(
            'SELECT p.' . $q('id') . ', p.' . $q('title') . ', p.' . $q('version') . ' AS ' . $q('revision')
            . ', r.' . $q('revision') . ' AS ' . $q('read_revision')
            . ', a.' . $q('published_at') . ' AS ' . $q('recorded_at')
            . ' FROM ' . $q('sis_portal_posts') . ' p JOIN ' . $q('sis_portal_audiences') . ' a ON a.'
            . $q('post_id') . ' = p.' . $q('id') . ' LEFT JOIN ' . $q('sis_announcement_reads') . ' r ON r.'
            . $q('post_id') . ' = p.' . $q('id') . ' AND r.' . $q('owner_user_id') . ' = ?'
            . ' LEFT JOIN ' . $q('sis_announcement_dismissals') . ' d ON d.' . $q('post_id')
            . ' = p.' . $q('id') . ' AND d.' . $q('owner_user_id') . ' = ?'
            . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(a.' . $q('published_at')
            . ", '') DESC, p." . $q('id') . ' DESC',
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

    public function dismissedRevision(EntityId $owner, EntityId $post): ?int
    {
        $row = $this->sql->rows('sis_announcement_dismissals', [
            'owner_user_id' => $owner->value, 'post_id' => $post->value,
        ], 1)[0] ?? null;
        return $row === null ? null : (int) $row['revision'];
    }

    public function dismiss(EntityId $owner, EntityId $post, int $revision): void
    {
        $where = ['owner_user_id' => $owner->value, 'post_id' => $post->value];
        if ($this->dismissedRevision($owner, $post) === null) {
            $this->sql->insert('sis_announcement_dismissals', [
                'id' => EntityId::generate()->value, 'revision' => $revision,
            ] + $where);
        } else {
            $this->sql->update('sis_announcement_dismissals', ['revision' => $revision], $where);
        }
        $q = $this->sql->quote(...);
        $this->sql->execute('DELETE FROM ' . $q('sis_announcement_reads') . ' WHERE '
            . $q('owner_user_id') . ' = ? AND ' . $q('post_id') . ' = ?', [$owner->value, $post->value]);
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
