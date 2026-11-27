<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Registration\Event\SectionMeetingPlanWasPublished;
use Domain\Registration\ValueObject\SectionMeetingPlanDefinition;
use Domain\Registration\ValueObject\SectionMeetingPlanId;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\EventSourcing\EventContext;
use Domain\Shared\EventSourcing\EventPage;
use Domain\Shared\EventSourcing\HistoricalEventStore;
use Domain\Shared\EventSourcing\StoredEvent;
use Domain\Shared\EventSourcing\StreamReference;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Projection\SqlMeetingPlanVerifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AcademicTimetableFixture;

final class MeetingPlanVerificationTest extends TestCase
{
    private function fixture(bool $published = true, bool $meetings = true): AcademicTimetableFixture
    {
        require_once dirname(__DIR__) . '/Support/AcademicTimetableFixture.php';
        $f = new AcademicTimetableFixture();
        if ($meetings) {
            foreach ([10, 12] as $hour) {
                $start = new DateTimeImmutable(sprintf('2026-08-10T%02d:00:00-07:00', $hour));
                $booking = $f->bookings->request(
                    $hour === 10 ? $f->sis->room('Fictional.101', 40) : null,
                    'Fictional PRIVATE booking purpose',
                    [new TimeSlot($start, $start->modify('+1 hour'))],
                    section: $f->section->id
                );
                $f->bookings->approve($booking->id, 0);
            }
        }
        if ($published) {
            $f->publish();
        }
        return $f;
    }

    private function facts(AcademicTimetableFixture $f): array
    {
        return array_map($f->sis->sql->rows(...), ['sis_history_events', 'sis_history_streams',
            'sis_history_operations', 'sis_audit', 'sis_bookings', 'sis_booking_slots', 'sis_section_meeting_plans',
            'sis_enrollments', 'sis_academic_credits', 'sis_section_versions']);
    }

    private function verify(AcademicTimetableFixture $f, int $limit = 500, ?HistoricalEventStore $history = null): array
    {
        return new SqlMeetingPlanVerifier($f->sis->sql, $history ?? $f->history)
            ->verify($f->section->id, $limit)->describe();
    }

    private function append(AcademicTimetableFixture $f, callable $change): void
    {
        $stream = new StreamReference(new SectionMeetingPlanId($f->section->id->value), 'section_meeting_plan');
        $current = $f->history->latest($stream)->event;
        $payload = $current->payload();
        $payload['previous_version_id'] = $payload['version_id'];
        $payload['version_id'] = EntityId::generate()->value;
        $change($payload['definition']);
        $event = SectionMeetingPlanWasPublished::occur(
            $stream->aggregateId,
            $payload,
            ['__aggregate_type' => 'section_meeting_plan']
        )->withPlayhead($current->playhead() + 1);
        $f->history->append(
            $stream,
            new EntityId($payload['version_id']),
            $current->playhead(),
            new EventContext(
                $f->sis->access->actor,
                $f->sis->clock->now(),
                EntityId::generate(),
                reason: 'Fictional protected test evidence.'
            ),
            [$event]
        );
        $f->plans->retain(
            $f->section->id,
            new EntityId($payload['version_id']),
            $event->playhead(),
            new SectionMeetingPlanDefinition($payload['definition'])->fingerprint()
        );
    }

    public function testCurrentOnlinePlanVerifiesWithWritesProhibitedAndNoPrivateValues(): void
    {
        $f = $this->fixture();
        $before = $this->facts($f);
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verify($f, 2);
        self::assertSame('verified', $result['status']);
        self::assertSame(2, $result['events_checked']);
        self::assertSame(1, $result['publications_checked']);
        self::assertSame(2, $result['current_meetings_checked']);
        self::assertFalse($result['records_changed']);
        $privateValues = ['PRIVATE', 'Fictional', '2026-', 'timezone', 'definition', 'booking_id', 'owner', 'reason'];
        foreach ($privateValues as $private) {
            self::assertStringNotContainsString($private, json_encode($result, JSON_THROW_ON_ERROR));
        }
        self::assertSame($before, $this->facts($f));
    }

    public function testAnUnpublishedSectionIsDistinctFromAGenuinelyPublishedEmptyPlan(): void
    {
        $f = $this->fixture(false, false);
        self::assertSame('observed_empty', $this->verify($f)['status']);
        $f->publish();
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verify($f, 1);
        self::assertSame('verified', $result['status']);
        self::assertSame(1, $result['events_checked']);
        self::assertSame(0, $result['current_meetings_checked']);
    }

    public static function damage(): array
    {
        return array_map(static fn(string $kind): array => [$kind], ['pointer_hash', 'pointer_id', 'pointer_version',
            'missing_pointer', 'plan_event', 'plan_head', 'offering_event', 'missing_offering_head', 'routing']);
    }

    #[DataProvider('damage')]
    public function testDamageNeverReturnsPartialSuccessOrRepairsRecords(string $damage): void
    {
        $f = $this->fixture();
        $sql = $f->sis->sql;
        $id = $f->section->id->value;
        if ($damage === 'missing_offering_head') {
            $f->sis->connection->pdo->exec('PRAGMA foreign_keys = OFF');
        }
        $eventStream = in_array($damage, ['plan_event', 'offering_event'], true)
        ? $sql->one('sis_history_streams', ['aggregate_id' => $id,
                'stream_type' => $damage === 'plan_event' ? 'section_meeting_plan' : 'section_publication'])['id']
        : null;
        match ($damage) {
            'pointer_hash' => $sql->update(
                'sis_section_meeting_plans',
                ['definition_hash' => str_repeat('0', 64)], ['id' => $id]
            ),
            'pointer_id' => $sql->update(
                'sis_section_meeting_plans',
                ['publication_id' => EntityId::generate()->value], ['id' => $id]
            ),
            'pointer_version' => $sql->update('sis_section_meeting_plans', ['version' => 2], ['id' => $id]),
            'missing_pointer' => $sql->execute('DELETE FROM sis_section_meeting_plans WHERE id = ?', [$id]),
            'plan_event', 'offering_event' => $sql->update('sis_history_events', ['sealed_event' => 'PRIVATE damaged'],
                ['stream_id' => $eventStream]),
            'plan_head' => $sql->update('sis_history_streams', ['version' => 99],
                ['aggregate_id' => $id, 'stream_type' => 'section_meeting_plan']),
            'missing_offering_head' => $sql->execute(
                'DELETE FROM sis_history_streams WHERE aggregate_id = ? AND stream_type = ?',
                [$id, 'section_publication']
            ),
            'routing' => $sql->update(
                'sis_sections',
                ['course_id' => $f->sis->section()->courseId->value],
                ['id' => $id]
            ),
        };
        $before = $this->facts($f);
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verify($f);
        self::assertSame('unverified', $result['status']);
        self::assertNull($result['publications_checked']);
        self::assertNull($result['current_meetings_checked']);
        self::assertStringNotContainsString('PRIVATE', json_encode($result));
        self::assertSame($before, $this->facts($f));
    }

    public static function badReferences(): array
    {
        return [['missing'], ['outside_dates']];
    }

    #[DataProvider('badReferences')]
    public function testAuthenticatedButInconsistentOfferingEvidenceIsRejected(string $kind): void
    {
        $f = $this->fixture();
        $this->append($f, static function (array &$definition) use ($kind): void {
            if ($kind === 'missing') {
                $definition['meetings'][0]['offering_publication_id'] = EntityId::generate()->value;
            } else {
                $definition['meetings'][0]['starts_at'] = '2030-08-10T10:00:00Z';
                $definition['meetings'][0]['ends_at'] = '2030-08-10T11:00:00Z';
            }
        });
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verify($f);
        self::assertSame('unverified', $result['status']);
        self::assertSame(['offering_reference'], $result['issues']);
    }

    public function testWorkingBookingChangesAndLaterCatalogLabelsDoNotReinterpretAPublishedPlan(): void
    {
        $f = $this->fixture();
        $f->sis->sql->update(
            'sis_courses',
            ['title' => 'Fictional later title'],
            ['id' => $f->section->courseId->value]
        );
        $f->sis->sql->update('sis_terms', ['code' => 'LATER'], ['id' => $f->section->termId->value]);
        foreach ($f->sis->sql->rows('sis_bookings') as $booking) {
            $f->bookings->cancel(new EntityId($booking['id']), (int) $booking['version']);
        }
        $before = $this->facts($f);
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        self::assertSame('verified', $this->verify($f)['status']);
        self::assertSame($before, $this->facts($f));
    }

    public function testLaterOfferingDatesDoNotReplaceTheCurrentPlansRetainedOffering(): void
    {
        $f = $this->fixture();
        $publications = $f->catalog->sectionPublications();
        $review = $publications->review(
            $f->section->id,
            '2026-09-01',
            '2026-10-31',
            'Fictional later instructional dates.'
        );
        $publications->publish($f->section->id, $review['review_token']);
        $before = $this->facts($f);
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        $result = $this->verify($f, 3);
        self::assertSame('verified', $result['status']);
        self::assertSame(3, $result['events_checked']);
        self::assertSame(2, $result['current_meetings_checked']);
        self::assertSame($before, $this->facts($f));
    }

    public function testMultiplePagesAndRetainedOfferingsShareOneExactEventAllowance(): void
    {
        $f = $this->fixture();
        for ($index = 0; $index < 20; $index++) {
            $this->append($f, static function (array &$definition) use ($index): void {
                $definition['section_code'] = 'Fictional retained label ' . $index;
            });
        }
        $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
        self::assertSame('verified', $this->verify($f, 22)['status']);
        $limited = $this->verify($f, 21);
        self::assertSame('limited', $limited['status']);
        self::assertSame(21, $limited['events_checked']);
        self::assertNull($limited['current_meetings_checked']);
    }

    public static function drift(): array
    {
        return [['pointer'], ['plan_head'], ['offering_head'], ['identity']];
    }

    #[DataProvider('drift')]
    public function testConcurrentBoundaryChangesWithholdAllCompletedCounts(string $kind): void
    {
        $f = $this->fixture();
        $history = $this->createMock(HistoricalEventStore::class);
        $other = $f->sis->section()->courseId;
        $history->expects(self::never())->method('append');
        $history->expects(self::never())->method('latest');
        $changed = false;
        $history->method('page')->willReturnCallback(static function (
            $stream,
            $cursor,
            $limit
        ) use (
            $f,
            $kind,
            $other,
            &$changed
        ) {
            $page = $f->history->page($stream, $cursor, $limit);
            if (!$changed) {
                $changed = true;
                match ($kind) {
                    'pointer' => $f->sis->sql->update(
                        'sis_section_meeting_plans',
                        ['definition_hash' => str_repeat('0', 64)],
                        ['id' => $f->section->id->value]
                    ),
                    'plan_head', 'offering_head' => $f->sis->sql->update('sis_history_streams', ['version' => 99],
                        ['aggregate_id' => $f->section->id->value, 'stream_type' => $kind === 'plan_head'
                            ? 'section_meeting_plan' : 'section_publication']),
                    'identity' => $f->sis->sql->update(
                        'sis_sections',
                        ['course_id' => $other->value],
                        ['id' => $f->section->id->value]
                    ),
                };
            }
            return $page;
        });
        $result = $this->verify($f, history: $history);
        self::assertSame('changed', $result['status']);
        self::assertNull($result['publications_checked']);
        self::assertNull($result['current_meetings_checked']);
    }

    public function testOversizedPayloadsBrokenCursorsAndProviderErrorsRemainBoundedAndPrivate(): void
    {
        foreach (['bytes', 'cursor', 'exception'] as $kind) {
            $f = $this->fixture();
            $history = $this->createMock(HistoricalEventStore::class);
            $history->expects(self::never())->method('append');
            $history->expects(self::never())->method('latest');
            $history->method('page')->willReturnCallback(static function ($stream, $cursor, $limit) use ($f, $kind) {
                if ($kind === 'exception') {
                    throw new \RuntimeException('PRIVATE upstream details');
                }
                if ($kind === 'cursor') {
                    return new EventPage([], 'PRIVATE repeated cursor', 1);
                }
                $page = $f->history->page($stream, $cursor, $limit);
                $old = $page->events[0];
                $event = SectionMeetingPlanWasPublished::occur(
                    $stream->aggregateId,
                    $old->event->payload(),
                    ['PRIVATE oversized' => str_repeat('x', 4_194_304)]
                )->withPlayhead(1);
                return new EventPage([
                    new StoredEvent($old->id, $event, $old->context, $old->recordedAt, $old->hash),
                ], null, 1);
            });
            $f->sis->connection->pdo->exec('PRAGMA query_only = ON');
            $result = $this->verify($f, history: $history);
            self::assertSame($kind === 'exception' ? 'unverified' : 'limited', $result['status']);
            self::assertStringNotContainsString('PRIVATE', json_encode($result));
            self::assertNull($result['publications_checked']);
        }
    }

    public function testMissingIdentityInheritedTransactionsAndInvalidLimitsDoNotBecomeVerified(): void
    {
        $f = $this->fixture();
        $verifier = new SqlMeetingPlanVerifier($f->sis->sql, $f->history);
        self::assertSame('unverified', $verifier->verify(EntityId::generate())->status);
        $f->sis->transactions->run(function () use ($f, $verifier): void {
            self::assertSame(['unavailable'], $verifier->verify($f->section->id)->issues);
        });
        foreach ([0, 2001] as $limit) {
            try {
                $verifier->verify($f->section->id, $limit);
                self::fail('An invalid event allowance was accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
