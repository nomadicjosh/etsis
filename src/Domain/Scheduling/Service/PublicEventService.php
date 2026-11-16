<?php

declare(strict_types=1);

namespace Domain\Scheduling\Service;

use DateTimeImmutable;
use DateTimeZone;
use Domain\Audit\Repository\AuditTrail;
use Domain\Communications\Service\MessageCipher;
use Domain\Scheduling\CalendarPeriod;
use Domain\Scheduling\Repository\PublicEventRepository;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\Persistence\UnitOfWork;
use Domain\Shared\Security\StudentAccess;
use Domain\Shared\Service\Clock;
use Domain\Shared\ValueObject\EntityId;
use InvalidArgumentException;
use Throwable;

/** Explicit public facilities content. Academic history and private booking purposes are separate. */
final readonly class PublicEventService
{
    public function __construct(
        private PublicEventRepository $events,
        private StudentAccess $access,
        private UnitOfWork $transactions,
        private AuditTrail $audit,
        private MessageCipher $cipher,
        private Clock $clock,
    ) {
    }

    /** @return array<string,mixed> */
    public function setup(EntityId $booking): array
    {
        $actor = $this->authorize();
        $current = $this->events->current($booking);
        try {
            $source = $this->events->source($booking);
        } catch (ValidationException) {
            $source = null;
        }
        if ($current !== null) {
            $current['version'] = (int) $current['version'];
            $current['published'] = (int) $current['published'] === 1;
        }
        $this->authorize($actor);
        return ['current' => $current === null ? null : array_intersect_key($current, array_flip([
            'id', 'version', 'published', 'title', 'description', 'published_at',
        ])), 'preview' => $source === null ? null : $this->preview($source)];
    }

    /** @return array<string,mixed> */
    public function review(EntityId $booking, string $title, string $description): array
    {
        $actor = $this->authorize();
        $this->text($title, $description);
        $source = $this->events->source($booking);
        $current = $this->events->current($booking);
        $review = ['kind' => 'public-campus-event', 'booking_id' => $booking->value,
            'actor_id' => $actor->value, 'operation_id' => EntityId::generate()->value,
            'event_id' => $current['id'] ?? EntityId::generate()->value,
            'expected_version' => (int) ($current['version'] ?? 0),
            'source' => $source, 'timezone' => $this->clock->now()->getTimezone()->getName(),
            'title' => trim($title), 'description' => trim($description),
            'expires_at' => $this->clock->now()->getTimestamp() + 1200];
        $this->authorize($actor);
        return ['review_token' => $this->cipher->seal(json_encode($review, JSON_THROW_ON_ERROR)),
            'title' => $review['title'], 'description' => $review['description'],
            'preview' => $this->preview($source), 'expires_at' => $review['expires_at']];
    }

    /** @return array{id:string,version:int} */
    public function publish(EntityId $booking, #[\SensitiveParameter] string $token): array
    {
        $actor = $this->authorize();
        $review = $this->decode($booking, $token);
        if ($review['actor_id'] !== $actor->value) {
            throw new AccessDeniedException('Review this event using your own account.');
        }
        return $this->transactions->run(function () use ($booking, $actor, $review, $token): array {
            $this->events->lock($booking, $review['source']);
            $this->authorize($actor);
            $current = $this->events->current($booking);
            $hash = hash('sha256', $token);
            if ($current !== null && $current['operation_id'] === $review['operation_id']) {
                if (!hash_equals($current['request_hash'], $hash)) {
                    throw new ConcurrentModificationException('This review has already been used.');
                }
                // Withdrawal does not turn an uncertain publication retry into another publication.
                $this->authorize($actor);
                return ['id' => $current['id'], 'version' => (int) $current['operation_version']];
            }
            if (
                $review['expires_at'] <= $this->clock->now()->getTimestamp()
                || (int) ($current['version'] ?? 0) !== $review['expected_version']
            ) {
                throw new ConcurrentModificationException('Review the public event again before publishing.');
            }
            $source = $this->events->source($booking);
            if (
                $source !== $review['source']
                || $review['timezone'] !== $this->clock->now()->getTimezone()->getName()
            ) {
                throw new ConcurrentModificationException('The approved dates or location changed. Review again.');
            }
            $version = $review['expected_version'] + 1;
            $this->events->publish(
                ['id' => $review['event_id'], 'booking_id' => $booking->value,
                'room_id' => $source['room_id'], 'version' => $version, 'published' => 1,
                'title' => $review['title'], 'description' => $review['description'],
                'timezone' => $review['timezone'],
                'room_label' => $source['room_label'], 'building_label' => $source['building_label'],
                'operation_id' => $review['operation_id'], 'operation_version' => $version,
                'request_hash' => $hash, 'published_at' => $this->clock->now()->format(DATE_ATOM)],
                $source['times'],
                $review['expected_version']
            );
            $this->audit->record($actor, 'campus_event.published', new EntityId($review['event_id']));
            $this->authorize($actor);
            return ['id' => $review['event_id'], 'version' => $version];
        });
    }

    public function withdraw(EntityId $booking, int $version): void
    {
        $actor = $this->authorize();
        $candidate = $this->events->current($booking) ?? throw new RecordNotFoundException('Event not found.');
        $this->transactions->run(function () use ($booking, $version, $actor, $candidate): void {
            $this->events->lock($booking, ['room_id' => $candidate['room_id'], 'building_id' => null]);
            $this->authorize($actor);
            $current = $this->events->current($booking) ?? throw new RecordNotFoundException('Event not found.');
            if ((int) $current['published'] === 0 && (int) $current['version'] === $version + 1) {
                $this->authorize($actor);
                return;
            }
            if ((int) $current['version'] !== $version || (int) $current['published'] !== 1) {
                throw new ConcurrentModificationException('Reload the public event before withdrawing it.');
            }
            $this->events->withdraw($booking, $version);
            $this->audit->record($actor, 'campus_event.withdrawn', new EntityId($current['id']));
            $this->authorize($actor);
        });
    }

    /** @return array{today:string,timezone:string} */
    public function context(): array
    {
        $now = $this->clock->now();
        return ['today' => $now->format('Y-m-d'), 'timezone' => $now->getTimezone()->getName()];
    }

    /** Prepare editable text only; recipients, encrypted persistence and delivery belong to Communications.
     * @return array{subject:string,body:string}
     */
    public function announcement(EntityId $booking): array
    {
        $actor = $this->authorizeAnnouncement();
        $times = $this->events->publishedTimes($booking);
        if ($times === [] || count($times) > 366) {
            throw new ValidationException([['field' => 'event',
                'message' => 'Publish an approved event with between one and 366 active public meetings first.']]);
        }
        $event = $times[0];
        $zone = new DateTimeZone($event['timezone']);
        $lines = [$event['title'], '', $event['description'], '',
            $event['room_label'] . ' · ' . $event['building_label'], '', 'Dates · ' . $zone->getName()];
        foreach ($times as $time) {
            $starts = new DateTimeImmutable($time['starts_at'])->setTimezone($zone);
            $ends = new DateTimeImmutable($time['ends_at'])->setTimezone($zone);
            $lines[] = $starts->format('D, M j, Y H:i P') . ' through ' . $ends->format('D, M j, Y H:i P');
        }
        $this->authorizeAnnouncement($actor);
        return ['subject' => $event['title'], 'body' => implode("\n", $lines)];
    }

    /** A deliberately small teaser, not a partial calendar total.
     * @return list<array<string,mixed>>
     */
    public function upcoming(): array
    {
        $now = $this->clock->now();
        return $this->events->calendar(new TimeSlot($now, $now->modify('+31 days')), '', 3);
    }

    /** Public, bounded read. No accounts, academic publications or journal streams are read.
     * @return array<string,mixed>
     */
    public function calendar(?string $date = null, string $view = 'month', string $search = ''): array
    {
        if (
            !mb_check_encoding($search, 'UTF-8') || mb_strlen($search) > 80
            || preg_match('/[\x00-\x1f\x7f]/', $search)
        ) {
            throw new ValidationException([['field' => 'search', 'message' => 'Use a search of up to 80 characters.']]);
        }
        $now = $this->clock->now();
        $period = new CalendarPeriod($date ?? $now->format('Y-m-d'), $now->getTimezone(), $view);
        $rows = $this->events->calendar(new TimeSlot($period->startsAt, $period->endsAt), trim($search), 501);
        if (count($rows) > 500) {
            throw new ValidationException([['field' => 'search',
                'message' => 'More than 500 event times match. Choose a week or search for an event or location.']]);
        }
        return $period->summarize($rows, ['published']) + $this->context();
    }

    /** @param array<string,mixed> $source
     * @return array<string,mixed>
     */
    private function preview(array $source): array
    {
        return ['room_label' => $source['room_label'], 'building_label' => $source['building_label'],
            'timezone' => $this->clock->now()->getTimezone()->getName(),
            'times' => array_map(static fn(array $time): array => [
                'starts_at' => str_replace(' ', 'T', $time['starts_at']) . 'Z',
                'ends_at' => str_replace(' ', 'T', $time['ends_at']) . 'Z',
            ], $source['times'])];
    }

    private function text(string $title, string $description): void
    {
        $errors = [];
        if (
            trim($title) === '' || !mb_check_encoding($title, 'UTF-8') || mb_strlen($title) > 191
            || preg_match('/[\x00-\x1f\x7f]/', $title)
        ) {
            $errors[] = ['field' => 'title', 'message' => 'Enter a public title of up to 191 characters.'];
        }
        if (
            trim($description) === '' || !mb_check_encoding($description, 'UTF-8') || mb_strlen($description) > 4000
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $description)
        ) {
            $errors[] = ['field' => 'description', 'message' => 'Enter a public description of up to 4000 characters.'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /** @return array<string,mixed> */
    private function decode(EntityId $booking, #[\SensitiveParameter] string $token): array
    {
        try {
            if (strlen($token) > 262144) {
                throw new InvalidArgumentException();
            }
            $r = json_decode($this->cipher->open($token), true, 12, JSON_THROW_ON_ERROR);
            if (
                !is_array($r) || ($r['kind'] ?? null) !== 'public-campus-event'
                || ($r['booking_id'] ?? null) !== $booking->value || !is_int($r['expires_at'])
                || !is_int($r['expected_version']) || $r['expected_version'] < 0 || !is_array($r['source'])
            ) {
                throw new InvalidArgumentException();
            }
            foreach (['event_id', 'actor_id', 'operation_id'] as $field) {
                new EntityId($r[$field]);
            }
            if ($r['source']['room_id'] !== null) {
                new EntityId($r['source']['room_id']);
            }
            if ($r['source']['building_id'] !== null) {
                new EntityId($r['source']['building_id']);
            }
            $this->text($r['title'], $r['description']);
            return $r;
        } catch (Throwable) {
            throw new InvalidArgumentException('Review the public event again.');
        }
    }

    private function authorize(?EntityId $expected = null): EntityId
    {
        $actor = $this->access->requirePermission('sis:scheduling:manage');
        if (
            $this->access->requirePermission('sis:portal:manage')->value !== $actor->value
            || ($expected !== null && $expected->value !== $actor->value)
        ) {
            throw new AccessDeniedException('Access denied.');
        }
        return $actor;
    }

    private function authorizeAnnouncement(?EntityId $expected = null): EntityId
    {
        $actor = $this->authorize($expected);
        if ($this->access->requirePermission('sis:communications:manage')->value !== $actor->value) {
            throw new AccessDeniedException('Access denied.');
        }
        return $actor;
    }
}
