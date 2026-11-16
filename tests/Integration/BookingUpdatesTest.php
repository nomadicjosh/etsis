<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Audit\Repository\AuditTrail;
use Domain\Scheduling\Service\BookingService;
use Domain\Scheduling\Service\BookingUpdatesService;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\RecordNotFoundException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlBookingCategoryRepository;
use Infrastructure\Persistence\Repository\SqlBookingRepository;
use Infrastructure\Persistence\Repository\SqlRoomRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class BookingUpdatesTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . '/Support/SisFixture.php';
    }

    private function bookings(SisFixture $f, ?AuditTrail $audit = null): BookingService
    {
        return new BookingService(
            new SqlBookingRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            new SqlRoomRepository($f->sql),
            $f->clock,
            new SqlBookingCategoryRepository($f->sql),
            $f->bookingSections()
        );
    }

    private function updates(SisFixture $f, ?AuditTrail $audit = null): BookingUpdatesService
    {
        return new BookingUpdatesService(
            new SqlBookingRepository($f->sql),
            new SqlRoomRepository($f->sql),
            $f->access,
            $f->transactions,
            $audit ?? $f->audit,
            $f->clock
        );
    }

    private function time(int $hour = 9): TimeSlot
    {
        return new TimeSlot(
            new DateTimeImmutable('2026-08-10T' . $hour . ':00:00-07:00'),
            new DateTimeImmutable('2026-08-10T' . ($hour + 1) . ':00:00-07:00')
        );
    }

    public function testApprovalCreatesAnOwnerOnlyUpdateAndReadingNeverChangesTheBookingRevision(): void
    {
        $f = new SisFixture();
        $owner = $f->access->actor;
        $service = $this->bookings($f);
        $booking = $service->request(null, 'Fictional private workshop', [$this->time()]);
        $events = $f->sql->rows('sis_history_events');
        $f->access->actor = $f->user();
        $service->approve($booking->id, 0);
        self::assertSame([], $this->updates($f)->page(false)['items']);
        try {
            $this->updates($f)->detail($booking->id);
            self::fail('A scheduling administrator read another account’s private updates.');
        } catch (RecordNotFoundException) {
            $f->access->actor = $owner;
        }
        $f->access->staff = false;
        $updates = $this->updates($f);
        $page = $updates->page();
        self::assertSame(1, $page['unread_count']);
        self::assertSame('Request approved', $page['items'][0]['label']);
        $detail = $updates->detail($booking->id);
        self::assertTrue($detail['unread']);
        self::assertSame('Online', $detail['booking']['location']);
        self::assertTrue($updates->detail($booking->id)['unread']);
        $before = $f->sql->one('sis_bookings', ['id' => $booking->id->value]);
        $updates->acknowledge($booking->id, 1);
        $updates->acknowledge($booking->id, 1);
        self::assertSame(0, $updates->page()['unread_count']);
        self::assertCount(1, $updates->page(false)['items']);
        self::assertFalse($updates->detail($booking->id)['unread']);
        self::assertSame($before['version'], $f->sql->one('sis_bookings', ['id' => $booking->id->value])['version']);
        self::assertCount(1, $f->sql->rows('sis_audit', ['action' => 'booking.update_read']));
        self::assertSame($events, $f->sql->rows('sis_history_events'));
    }

    public function testSelfActionsAreAlreadyReadAndAnOlderAcknowledgementCannotHideANewerUpdate(): void
    {
        $f = new SisFixture();
        $owner = $f->access->actor;
        $bookings = $this->bookings($f);
        $booking = $bookings->request(null, 'Fictional request', [$this->time()]);
        $bookings->approve($booking->id, 0);
        self::assertSame(0, $this->updates($f)->page()['unread_count']);
        self::assertCount(1, $this->updates($f)->page(false)['items']);
        $f->access->actor = $f->user();
        $bookings->cancel($booking->id, 1);
        $f->access->actor = $owner;
        self::assertSame('Booking cancelled', $this->updates($f)->page()['items'][0]['label']);
        try {
            $this->updates($f)->acknowledge($booking->id, 1);
            self::fail('An older acknowledgement cleared a newer update.');
        } catch (ConcurrentModificationException) {
            self::assertSame(1, $this->updates($f)->page()['unread_count']);
            self::assertSame(1, $f->sql->one('sis_bookings', ['id' => $booking->id->value])['seen_notice_version']);
        }
    }

    public function testReschedulingShowsTheAffectedMeetingAndItsVerifiedReplacementWithoutDuplicatingRetries(): void
    {
        $f = new SisFixture();
        $owner = $f->access->actor;
        $bookings = $this->bookings($f);
        $booking = $bookings->request($f->room('LIB.101', 20), 'Fictional seminar', [$this->time()]);
        $bookings->approve($booking->id, 0);
        $f->access->actor = $f->user();
        $occurrence = $booking->occurrences[0]->id;
        $review = $bookings->reviewReplacement($booking->id, $occurrence, null, $this->time(14), 1);
        $replacement = new EntityId($review['new_id']);
        $bookings->replaceOccurrence(
            $booking->id,
            $occurrence,
            $replacement,
            null,
            $this->time(14),
            1,
            $review['revision']
        );
        $before = $f->sql->one('sis_bookings', ['id' => $booking->id->value]);
        $f->clock->time = $f->clock->time->modify('+1 minute');
        $bookings->replaceOccurrence(
            $booking->id,
            $occurrence,
            $replacement,
            null,
            $this->time(14),
            1,
            $review['revision']
        );
        self::assertSame($before, $f->sql->one('sis_bookings', ['id' => $booking->id->value]));
        $f->access->actor = $owner;
        $detail = $this->updates($f)->detail($booking->id);
        self::assertSame('Meeting rescheduled', $detail['label']);
        self::assertTrue($detail['booking']['meetings'][0]['cancelled']);
        self::assertSame('Online', $detail['replacement']['location']);
        self::assertSame('approved', $detail['replacement']['status']);
        self::assertSame(
            $this->time(14)->startsAt->getTimestamp(),
            new DateTimeImmutable($detail['replacement']['meetings'][0]['starts_at'])->getTimestamp()
        );
        self::assertSame(1, $this->updates($f)->page()['unread_count']);
    }

    public function testPartialCancellationIsOneLatestUpdateAndARepeatDoesNotReopenReadTracking(): void
    {
        $f = new SisFixture();
        $owner = $f->access->actor;
        $bookings = $this->bookings($f);
        $booking = $bookings->request(null, 'Fictional recurring meeting', [$this->time(), $this->time(11)]);
        $bookings->approve($booking->id, 0);
        $f->access->actor = $f->user();
        $bookings->cancelOccurrence($booking->id, $booking->occurrences[0]->id, 1);
        $before = $f->sql->one('sis_bookings', ['id' => $booking->id->value]);
        $bookings->cancelOccurrence($booking->id, $booking->occurrences[0]->id, 1);
        self::assertSame($before, $f->sql->one('sis_bookings', ['id' => $booking->id->value]));
        $f->access->actor = $owner;
        $detail = $this->updates($f)->detail($booking->id);
        self::assertSame('Meeting cancelled', $detail['label']);
        self::assertCount(1, $detail['booking']['meetings']);
        self::assertSame('approved', $detail['booking']['status']);
        self::assertTrue($detail['booking']['meetings'][0]['cancelled']);
    }

    public function testOwnerPagesAreBoundedAndTheirCursorCannotCrossIntoAnotherAccount(): void
    {
        $f = new SisFixture();
        $owner = $f->access->actor;
        $bookings = $this->bookings($f);
        $ids = [];
        for ($i = 0; $i < 23; ++$i) {
            $ids[] = $bookings->request(null, 'Fictional request ' . $i, [$this->time()])->id;
        }
        $f->access->actor = $f->user();
        foreach ($ids as $id) {
            $f->clock->time = $f->clock->time->modify('+1 second');
            $bookings->approve($id, 0);
        }
        $f->access->actor = $owner;
        $updates = $this->updates($f);
        $first = $updates->page();
        self::assertCount(20, $first['items']);
        self::assertSame(23, $first['unread_count']);
        $next = $updates->page(true, $first['next']['at'], new EntityId($first['next']['id']));
        self::assertCount(3, $next['items']);
        self::assertNull($next['next']);
        self::assertCount(23, array_unique(array_column([...$first['items'], ...$next['items']], 'id')));
        $f->access->actor = $f->user();
        self::assertSame([], $updates->page(true, $first['next']['at'], new EntityId($first['next']['id']))['items']);
        $this->expectException(RecordNotFoundException::class);
        $updates->acknowledge($ids[0], 1);
    }

    public function testLostSchedulingAuthorityRollsBackTheBookingAndItsUpdateTogether(): void
    {
        $f = new SisFixture();
        $booking = $this->bookings($f)->request(null, 'Fictional request', [$this->time()]);
        $f->access->actor = $f->user();
        $before = $f->sql->rows('sis_bookings');
        $audit = $this->createStub(AuditTrail::class);
        $audit->method('record')->willReturnCallback(static function () use ($f): void {
            $f->access->staff = false;
        });
        try {
            $this->bookings($f, $audit)->approve($booking->id, 0);
            self::fail('A booking/update survived removed scheduling access.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sql->rows('sis_bookings'));
        }
    }

    public function testChangedSignedInOwnerRollsBackReadTrackingAndItsRequiredAudit(): void
    {
        $f = new SisFixture();
        $owner = $f->access->actor;
        $other = $f->user();
        $bookings = $this->bookings($f);
        $booking = $bookings->request(null, 'Fictional request', [$this->time()]);
        $f->access->actor = $other;
        $bookings->approve($booking->id, 0);
        $f->access->actor = $owner;
        $before = $f->sql->one('sis_bookings', ['id' => $booking->id->value]);
        $audit = $this->createStub(AuditTrail::class);
        $callback = static function ($actor, $action, $subject) use ($f, $other): void {
            $f->audit->record($actor, $action, $subject);
            $f->access->actor = $other;
        };
        $audit->method('record')->willReturnCallback($callback);
        try {
            $this->updates($f, $audit)->acknowledge($booking->id, 1);
            self::fail('Read tracking survived an account change.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $f->sql->one('sis_bookings', ['id' => $booking->id->value]));
            self::assertSame([], $f->sql->rows('sis_audit', ['action' => 'booking.update_read']));
        }
    }

    public function testMigrationPreservesEarlierBookingsAndCreatesNoHistoricalUpdates(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000019_AddSisBookingUpdates.php';
        $connection = new \Qubus\Expressive\Connection\Pdo\Sqlite([
            'driver' => 'pdo_sqlite', 'dsn' => 'sqlite::memory:',
        ]);
        $connection->pdo->exec('CREATE TABLE sis_bookings (id VARCHAR(26) PRIMARY KEY, '
            . 'owner_user_id VARCHAR(36), title VARCHAR(191), status VARCHAR(16), version INT)');
        $sql = new \Infrastructure\Persistence\SqlRecords($connection->queryBuilder());
        $owner = EntityId::generate();
        $before = ['id' => EntityId::generate()->value, 'owner_user_id' => $owner->value,
            'title' => 'Fictional earlier approved booking', 'status' => 'approved', 'version' => 7];
        $sql->insert('sis_bookings', $before);
        $migration = new \AddSisBookingUpdates('20261115000019');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'migration')]));
        $migration->up();
        self::assertSame(
            $before + ['notice_version' => null, 'notice_kind' => null, 'notice_at' => null,
            'notice_occurrence_id' => null, 'notice_related_id' => null, 'seen_notice_version' => null],
            $sql->one('sis_bookings', ['id' => $before['id']])
        );
        $repo = new SqlBookingRepository($sql);
        self::assertSame([], $repo->ownerUpdates($owner, false, null, null));
        self::assertSame(0, $repo->unreadUpdates($owner));
    }

    public function testActualMigrationCompilesAdditiveMetadataAndOwnerIndexAcrossAllFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261115000019_AddSisBookingUpdates.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $connection = $this->createStub(\Qubus\Expressive\Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $compilerClass = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $connection->method('schemaCompiler')->willReturn(new $compilerClass($connection));
            $connection->method('getSchema')->willReturn(new \Qubus\Expressive\Schema($connection));
            $commands = [];
            $connection->method('command')->willReturnCallback(static function (string $sql) use (&$commands): bool {
                $commands[] = $sql;
                return true;
            });
            $migration = new \AddSisBookingUpdates('20261115000019');
            $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
                new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($connection, 'migration')]));
            $migration->up();
            $compiled = implode("\n", $commands);
            foreach (
                ['notice_version', 'notice_kind', 'notice_at', 'notice_occurrence_id',
                'notice_related_id', 'seen_notice_version', 'sb_owner_updates'] as $column
            ) {
                self::assertStringContainsString($column, $compiled, $dialect);
            }
            self::assertDoesNotMatchRegularExpression(
                '/CREATE TABLE|INSERT INTO|UPDATE |DELETE FROM|DROP /i',
                $compiled
            );
        }
    }
}
