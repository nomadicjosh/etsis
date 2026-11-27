<?php

declare(strict_types=1);

namespace Domain\Portal\Service;

use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Portal\PortalPost;
use Domain\Portal\Repository\AnnouncementRepository;
use Domain\Portal\Repository\PortalRepository;
use Domain\Portal\ValueObject\PublicationWindow;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Campus editorial announcements and owner-only read state, not a second content or event store. */
final readonly class CampusAnnouncementService
{
    public function __construct(
        private AnnouncementRepository $announcements,
        private PortalRepository $posts,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock,
        private ContentMarkup $markup,
    ) {
    }

    /** @return array<string, mixed> */
    public function page(bool $unreadOnly = true, ?EntityId $after = null): array
    {
        $owner = $this->access->authenticatedUserId();
        $rows = $this->announcements->page($owner, $unreadOnly, $after);
        $more = count($rows) > 20;
        $rows = array_slice($rows, 0, 20);
        $items = array_map(static fn (array $row): array => [
            'id' => $row['id'], 'title' => $row['title'], 'revision' => (int) $row['revision'],
            'recorded_at' => $row['recorded_at'] ?? null,
            'unread' => $row['read_revision'] === null || (int) $row['read_revision'] !== (int) $row['revision'],
        ], $rows);
        $this->owner($owner);
        return ['items' => $items, 'next_after' => $more ? end($items)['id'] : null];
    }

    /** @return array<string, mixed> */
    public function detail(EntityId $id): array
    {
        $owner = $this->access->authenticatedUserId();
        $post = $this->visible($id, $owner);
        $read = $this->announcements->readState($owner, $id);
        $html = $post->contentFormat === 'html' ? $this->markup->sanitize($post->content) : null;
        $text = $html === null ? $post->content : $this->markup->plainText($html);
        $this->owner($owner);
        return ['id' => $id->value, 'title' => $post->title, 'revision' => $post->version,
            'content' => $text, 'content_format' => $post->contentFormat, 'content_html' => $html,
            'unread' => $read === null || (int) $read['revision'] !== $post->version];
    }

    /** @return array{unread: bool} */
    public function read(EntityId $id, int $revision): array
    {
        if ($revision < 0 || $revision > 2147483646) {
            throw new InvalidArgumentException('Use a valid announcement revision.');
        }
        $owner = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($id, $revision, $owner): array {
            $this->posts->lock($id);
            $this->owner($owner);
            $post = $this->visible($id, $owner);
            if ($post->version !== $revision) {
                throw new ConcurrentModificationException('This announcement changed. Read its current version.');
            }
            $previous = $this->announcements->readState($owner, $id);
            if ($previous !== null && (int) $previous['revision'] === $revision) {
                $this->owner($owner);
                $this->visible($id, $owner);
                return ['unread' => false];
            }
            if ($previous !== null && (int) $previous['revision'] > $revision) {
                throw new ConcurrentModificationException('The announcement read state needs review.');
            }
            $at = $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.u\Z');
            $this->announcements->markRead($owner, $id, $revision, $at);
            $this->audit->record($owner, 'portal.announcement.read', $id);
            $this->owner($owner);
            if ($this->visible($id, $owner) != $post) {
                throw new ConcurrentModificationException('This announcement changed. Read its current version.');
            }
            $saved = $this->announcements->readState($owner, $id);
            if ($saved === null || (int) $saved['revision'] !== $revision || $saved['read_at'] !== $at) {
                throw new ConcurrentModificationException('The announcement read state changed. Reload it.');
            }
            return ['unread' => false];
        });
    }

    public function delete(EntityId $id, int $revision): array
    {
        if ($revision < 0 || $revision > 2147483646) {
            throw new InvalidArgumentException('Use a valid announcement revision.');
        }
        $owner = $this->access->authenticatedUserId();
        return $this->transactions->run(function () use ($id, $revision, $owner): array {
            $this->posts->lock($id);
            $this->owner($owner);
            if (($this->announcements->dismissedRevision($owner, $id) ?? -1) >= $revision) {
                return ['deleted' => true];
            }
            $post = $this->visible($id);
            if ($post->version !== $revision) {
                throw new ConcurrentModificationException('This announcement changed. Refresh before deleting it.');
            }
            $this->announcements->dismiss($owner, $id, $revision);
            $this->audit->record($owner, 'portal.announcement.deleted', $id);
            $this->owner($owner);
            if (
                $this->visible($id) != $post
                || $this->announcements->dismissedRevision($owner, $id) !== $revision
                || $this->announcements->readState($owner, $id) !== null
            ) {
                throw new ConcurrentModificationException('The announcement changed before deletion completed.');
            }
            return ['deleted' => true];
        });
    }

    private function visible(EntityId $id, ?EntityId $owner = null): PortalPost
    {
        $post = $this->posts->get($id);
        if (
            ($owner !== null && ($this->announcements->dismissedRevision($owner, $id) ?? -1) >= $post->version)
            || $post->kind !== 'news' || $post->status !== 'published'
            || !$this->announcements->available($id)
            || !new PublicationWindow($post->visibleFrom, $post->visibleUntil)->visible($this->clock->now())
        ) {
            throw new RecordNotFoundException('Announcement unavailable.');
        }
        return $post;
    }

    private function owner(EntityId $expected): void
    {
        if ($this->access->authenticatedUserId()->value !== $expected->value) {
            throw new AccessDeniedException();
        }
    }
}
