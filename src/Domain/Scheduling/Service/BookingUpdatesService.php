<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Booking;
use Domain\Scheduling\BookingUpdateKind;
use Domain\Scheduling\Repository\BookingRepository;
use Domain\Scheduling\Repository\RoomRepository;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;

/** Owner-only current updates; acknowledgement is independent of the booking's business revision. */
final readonly class BookingUpdatesService
{
    public function __construct(
        private BookingRepository $bookings,
        private RoomRepository $rooms,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private Clock $clock
    ) {
    }

    /** @return array<string,mixed> */
    public function page(bool $unreadOnly = true, ?string $afterAt = null, ?EntityId $after = null): array
    {
        $owner = $this->access->authenticatedUserId();
        if (($afterAt === null) !== ($after === null)) {
            throw new InvalidArgumentException('Use the complete booking updates cursor.');
        }
        if ($afterAt !== null) {
            $date = preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{6}Z$/D', $afterAt)
            ? DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.u\Z', $afterAt, new DateTimeZone('UTC')) : false;
            if ($date === false || $date->format('Y-m-d\TH:i:s.u\Z') !== $afterAt) {
                throw new InvalidArgumentException('Use a valid booking updates cursor.');
            }
        }
        $rows = $this->bookings->ownerUpdates($owner, $unreadOnly, $afterAt, $after);
        $items = array_map($this->label(...), array_slice($rows, 0, 20));
        $count = $this->bookings->unreadUpdates($owner);
        $this->sameOwner($owner);
        return ['items' => $items, 'unread_count' => $count,
            'next' => count($rows) > 20 ? ['at' => $rows[19]['notice_at'], 'id' => $rows[19]['id']] : null,
            'timezone' => $this->clock->now()->getTimezone()->getName()];
    }

    /** @return array<string,mixed> */
    public function detail(EntityId $id): array
    {
        $owner = $this->access->authenticatedUserId();
        $notice = $this->bookings->ownerUpdate($owner, $id);
        $booking = $this->owned($id, $owner);
        $occurrence = $notice['notice_occurrence_id'] === null ? null : new EntityId($notice['notice_occurrence_id']);
        $replacement = null;
        if ($notice['notice_related_id'] !== null) {
            $links = $this->bookings->links($id);
            if (
                $occurrence === null
                || ($links['replacements'][$occurrence->value] ?? null) !== $notice['notice_related_id']
            ) {
                throw new ConcurrentModificationException('The rescheduled meeting link needs review.');
            }
            $replacement = $this->packet($this->owned(new EntityId($notice['notice_related_id']), $owner));
        }
        $result = $this->label($notice) + [
            'booking' => $this->packet($booking, $occurrence), 'replacement' => $replacement,
        ];
        if ($this->bookings->ownerUpdate($owner, $id)['notice_version'] !== $notice['notice_version']) {
            throw new ConcurrentModificationException('There is a newer booking update. Refresh to read it.');
        }
        $this->sameOwner($owner);
        return $result;
    }

    public function acknowledge(EntityId $id, int $noticeVersion): void
    {
        if ($noticeVersion < 0) {
            throw new InvalidArgumentException('Use the displayed update revision.');
        }
        $owner = $this->access->authenticatedUserId();
        $this->transactions->run(function () use ($id, $noticeVersion, $owner): void {
            $this->sameOwner($owner);
            if ($this->bookings->acknowledgeUpdate($owner, $id, $noticeVersion)) {
                $this->audit->record($owner, 'booking.update_read', $id);
            }
            $this->sameOwner($owner);
        });
    }

    private function owned(EntityId $id, EntityId $owner): Booking
    {
        $booking = $this->bookings->get($id);
        if ($booking->ownerUserId->value !== $owner->value) {
            throw new AccessDeniedException('This booking belongs to another account.');
        }
        return $booking;
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function label(array $row): array
    {
        return ['id' => $row['id'], 'title' => $row['title'], 'status' => $row['status'],
            'version' => (int) $row['notice_version'], 'kind' => $row['notice_kind'],
            'label' => BookingUpdateKind::from($row['notice_kind'])->label(),
            'updated_at' => $row['notice_at'],
            'updated_local' => new DateTimeImmutable($row['notice_at'])->setTimezone($this->clock->now()->getTimezone())
                ->format('M j, Y \a\t g:i a T'),
            'unread' => $row['seen_notice_version'] === null
                || (int) $row['seen_notice_version'] < (int) $row['notice_version']];
    }

    /** @return array<string,mixed> */
    private function packet(Booking $booking, ?EntityId $occurrence = null): array
    {
        $room = $booking->roomId === null ? null : $this->rooms->get($booking->roomId);
        $zone = $this->clock->now()->getTimezone();
        $items = $occurrence === null ? $booking->occurrences : [$booking->occurrence($occurrence)];
        return ['status' => $booking->status, 'location' => $room === null ? 'Online' : ($room->name ?? $room->code),
            'room_code' => $room?->code, 'timezone' => $zone->getName(),
            'meetings' => array_map(static fn($meeting): array => [
                'starts_at' => $meeting->time->startsAt->format(DATE_ATOM),
                'ends_at' => $meeting->time->endsAt->format(DATE_ATOM),
                'local_start' => $meeting->time->startsAt->setTimezone($zone)->format('M j, Y \a\t g:i a T'),
                'local_end' => $meeting->time->endsAt->setTimezone($zone)->format('M j, Y \a\t g:i a T'),
                'cancelled' => $booking->status === 'cancelled' || $meeting->cancelledAt !== null,
            ], $items)];
    }

    private function sameOwner(EntityId $expected): void
    {
        if ($this->access->authenticatedUserId()->value !== $expected->value) {
            throw new AccessDeniedException('The signed-in account changed.');
        }
    }
}
