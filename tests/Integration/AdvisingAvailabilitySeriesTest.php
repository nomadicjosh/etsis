<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use Domain\Advising\AvailabilityPattern;
use Domain\Advising\Service\AdvisingAppointmentService;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlAdvisingAvailabilitySeriesRepository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\AdvisingAvailabilityFixture;

final class AdvisingAvailabilitySeriesTest extends TestCase
{
    private function fixture(): AdvisingAvailabilityFixture
    {
        require_once dirname(__DIR__) . '/Support/AdvisingAvailabilityFixture.php';
        $f = new AdvisingAvailabilityFixture();
        $f->appointments->sis->clock->time = new DateTimeImmutable(
            '2026-08-10T10:00:00',
            new \DateTimeZone('America/Los_Angeles')
        );
        return $f;
    }
    private function review(AdvisingAvailabilityFixture $f, array $changes = [], string $channel = 'staff'): array
    {
        return $f->service()->reviewSeries($f->appointments->host, $channel, new AvailabilityPattern(array_replace([
            'from' => '2026-08-11', 'through' => '2026-08-25', 'start_time' => '10:00', 'end_time' => '12:00',
            'weekdays' => [2], 'interval_weeks' => 1, 'excluded_dates' => [], 'timezone' => 'America/Los_Angeles',
        ], $changes)), 30, 'Fictional recurring instructions.');
    }
    private function offer(
        AdvisingAvailabilityFixture $f,
        array $r,
        string $channel = 'staff',
        ?\Domain\Audit\Repository\AuditTrail $audit = null
    ): array {
        return $f->service($audit)->createSeries(
            $f->appointments->host,
            $channel,
            new EntityId($r['series_id']),
            new AvailabilityPattern($r['pattern']),
            $r['slot_minutes'],
            $r['message'],
            $r['window_ids'],
            $r['revision'],
            $r['review_token']
        );
    }
    private function close(
        AdvisingAvailabilityFixture $f,
        array $r,
        ?\Domain\Audit\Repository\AuditTrail $audit = null
    ): array {
        return $f->service($audit)->closeSeries(
            $f->appointments->host,
            'staff',
            new EntityId($r['series_id']),
            $r['version'],
            $r['window_ids'],
            $r['reason'],
            $r['revision'],
            $r['review_token']
        );
    }
    private function rejects(callable $run, string $type = ConcurrentModificationException::class): void
    {
        try {
            $run();
            self::fail('Expected a rejected recurring availability command.');
        } catch (\Throwable $error) {
            self::assertInstanceOf($type, $error);
        }
    }
    public function testReviewedOfferBookingAndRemainingClosurePreserveOriginalFactsAndRetries(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $history = $s->sql->rows('sis_history_events');
        $r = $this->review($f);
        self::assertSame([], $s->sql->rows('sis_advising_availability'));
        $created = $this->offer($f, $r);
        self::assertCount(3, $created['window_ids']);
        self::assertSame($created, $this->offer($f, $r));
        $id = new EntityId($r['series_id']);
        $first = new EntityId($r['window_ids'][0]);
        $s->access->actor = $a->owner;
        $booking = $a->service()->book($a->student, $a->pursuit, EntityId::generate(), $first, 0, '2026-08-11T10:00');
        $appointment = $s->sql->rows('sis_advising_appointments');
        $s->access->actor = $a->staff;
        $f->service()->close($a->host, 'staff', $first, 0, 'Fictional individual closure.');
        $s->clock->time = new DateTimeImmutable('2026-08-19T10:00:00', new \DateTimeZone('America/Los_Angeles'));
        $closure = $f->service()->reviewSeriesClose($a->host, 'staff', $id, 'Fictional remaining closure.');
        self::assertSame([$r['window_ids'][2]], $closure['window_ids']);
        $closed = $this->close($f, $closure);
        self::assertSame($closed, $this->close($f, $closure));
        self::assertSame($created, $this->offer($f, $r));
        $view = $f->service()->seriesView($a->host, 'staff', $id);
        self::assertSame(['closed', 'open', 'closed'], array_column($view['windows'], 'status'));
        self::assertTrue($view['windows'][1]['expired']);
        self::assertSame('Fictional individual closure.', $view['windows'][0]['close_reason']);
        self::assertSame([], $view['remaining_ids']);
        self::assertSame($appointment, $s->sql->rows('sis_advising_appointments'));
        self::assertSame($history, $s->sql->rows('sis_history_events'));
        self::assertSame([], $s->sql->rows('sis_advising_checkins'));
        self::assertSame([], $s->sql->rows('sis_advising_updates'));
        self::assertStringNotContainsString('host_user_id', json_encode($view));
        self::assertStringNotContainsString('actor_id', json_encode($view));
    }
    public function testUnacceptedExpiredChangedActorAndChangedReviewDetailsAreRejected(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $r = $this->review($f);
        $changed = $r;
        $changed['message'] = 'Different instructions.';
        $this->rejects(fn() => $this->offer($f, $changed));
        $s->access->actor = $s->user();
        $this->rejects(fn() => $this->offer($f, $r), AccessDeniedException::class);
        $s->access->actor = $a->staff;
        $s->clock->time = $s->clock->time->modify('+20 minutes');
        $this->rejects(fn() => $this->offer($f, $r));
        self::assertSame([], $s->sql->rows('sis_advising_availability_series'));
        self::assertSame([], $s->sql->rows('sis_advising_availability'));
    }
    public function testNewConflictingWindowAfterReviewRejectsTheWholeOffer(): void
    {
        $f = $this->fixture();
        $s = $f->appointments->sis;
        $r = $this->review($f);
        $f->offer(date: '2026-08-18');
        $before = $s->sql->rows('sis_advising_availability');
        $this->rejects(fn() => $this->offer($f, $r));
        self::assertSame($before, $s->sql->rows('sis_advising_availability'));
        self::assertSame([], $s->sql->rows('sis_advising_availability_series'));
    }
    #[DataProvider('finalChanges')]
    public function testFinalCreateDriftRollsBackEveryWindowAndTheSeriesReceipt(string $change): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $r = $this->review($f);
        $s->access->staff = false;
        $s->access->grants = [AdvisingAppointmentService::PERMISSION, 'sis:student:read'];
        $audit = $a->audit(function () use ($s, $a, $r, $change): void {
            match ($change) {
                'duty' => $s->access->grants = [],
                'account' => $s->sql->update('sis_persons', ['owner_user_id' => null], ['id' => $a->host->value]),
                'member' => $s->sql->update('sis_advising_availability', ['version' => 1], ['id' =>
                    $r['window_ids'][2]]),
                'audit' => throw new \RuntimeException('Fictional required audit failure.'),
                'expiry' => $s->clock->time = $s->clock->time->modify('+20 minutes'),
            };
        });
        $type = match ($change) {
            'duty' => AccessDeniedException::class, 'member' => CorruptHistoryException::class,
            'audit' => \RuntimeException::class, default => ConcurrentModificationException::class
        };
        $this->rejects(fn() => $this->offer($f, $r, audit: $audit), $type);
        self::assertSame([], $s->sql->rows('sis_advising_availability'));
        self::assertSame([], $s->sql->rows('sis_advising_availability_series'));
        self::assertSame($a->advisor->value, $s->admissions->person($a->host)->ownerUserId->value);
    }
    public static function finalChanges(): array
    {
        return [['duty'], ['account'], ['member'], ['audit'], ['expiry']];
    }
    public function testIndividualClosureAfterReviewRequiresFreshCompleteRemainingSelection(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $r = $this->review($f);
        $this->offer($f, $r);
        $id = new EntityId($r['series_id']);
        $close = $f->service()->reviewSeriesClose($a->host, 'staff', $id, 'Fictional reviewed closure.');
        $f->service()->close($a->host, 'staff', new EntityId($r['window_ids'][1]), 0, 'Fictional independent closure.');
        $before = $s->sql->rows('sis_advising_availability');
        $this->rejects(fn() => $this->close($f, $close));
        self::assertSame($before, $s->sql->rows('sis_advising_availability'));
        $fresh = $f->service()->reviewSeriesClose($a->host, 'staff', $id, $close['reason']);
        self::assertCount(2, $fresh['window_ids']);
        $this->close($f, $fresh);
        self::assertSame([], $f->service()->seriesView($a->host, 'staff', $id)['remaining_ids']);
    }
    #[DataProvider('closureChanges')]
    public function testFinalClosureFailureRollsBackAllMembers(string $change): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $r = $this->review($f);
        $this->offer($f, $r);
        $id = new EntityId($r['series_id']);
        $close = $f->service()->reviewSeriesClose($a->host, 'staff', $id, 'Fictional closure.');
        $before = $s->sql->rows('sis_advising_availability');
        $s->access->staff = false;
        $s->access->grants = [AdvisingAppointmentService::PERMISSION, 'sis:student:read'];
        $audit = $a->audit(function () use ($change, $s, $r): void {
            match ($change) {
                'duty' => $s->access->grants = [],
                'member' => $s->sql->update('sis_advising_availability', ['version' => 0], ['id' =>
                    $r['window_ids'][1]]),
                'expiry' => $s->clock->time = $s->clock->time->modify('+20 minutes'),
                default => throw new \RuntimeException('Fictional audit failure.'),
            };
        });
        $type = match ($change) {
            'duty' => AccessDeniedException::class, 'member' => CorruptHistoryException::class,
            'expiry' => ConcurrentModificationException::class, default => \RuntimeException::class
        };
        $this->rejects(fn() => $this->close($f, $close, $audit), $type);
        self::assertSame($before, $s->sql->rows('sis_advising_availability'));
        $s->access->staff = true;
        self::assertSame(0, $f->service()->seriesView($a->host, 'staff', $id)['series']['version']);
        $fresh = $f->service()->reviewSeriesClose($a->host, 'staff', $id, $close['reason']);
        $this->close($f, $fresh);
        self::assertSame('closed', $f->service()->seriesView($a->host, 'staff', $id)['series']['status']);
    }
    public static function closureChanges(): array
    {
        return [['audit'], ['duty'], ['member'], ['expiry']];
    }
    public function testPartialClosureAndChangedReasonCannotUseTheReviewedCompleteSet(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $r = $this->review($f);
        $this->offer($f, $r);
        $close = $f->service()->reviewSeriesClose(
            $a->host,
            'staff',
            new EntityId($r['series_id']),
            'Fictional complete closure.'
        );
        $partial = $close;
        $partial['window_ids'] = [$close['window_ids'][0]];
        $this->rejects(fn() => $this->close($f, $partial));
        $changed = $close;
        $changed['reason'] = 'Fictional changed reason.';
        $this->rejects(fn() => $this->close($f, $changed));
        self::assertSame(['open', 'open', 'open'], array_column($f->service()->seriesView(
            $a->host,
            'staff',
            new EntityId($r['series_id'])
        )['windows'], 'status'));
        $this->close($f, $close);
    }
    public function testOwnerAndOtherAdvisorCannotReadOrManageAHostsSeriesAndRelinkingIsIsolated(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $r = $this->review($f);
        $this->offer($f, $r);
        $id = new EntityId($r['series_id']);
        $s->access->actor = $a->owner;
        $this->rejects(fn() => $f->service()->seriesView($a->host, 'advisor', $id), AccessDeniedException::class);
        $s->access->actor = $a->advisor;
        self::assertCount(1, $f->service()->seriesList($a->host, 'advisor')['items']);
        $new = $s->user();
        $s->sql->update('sis_persons', ['owner_user_id' => $new->value], ['id' => $a->host->value]);
        $s->access->actor = $new;
        self::assertSame([], $f->service()->seriesList($a->host, 'advisor')['items']);
        $this->rejects(fn() => $f->service()->seriesView($a->host, 'advisor', $id), AccessDeniedException::class);
        $s->access->actor = $a->staff;
        $s->sql->update('sis_employees', ['active' => 0], ['person_id' => $a->host->value]);
        self::assertFalse($f->service()->seriesList($a->host, 'staff')['can_create']);
        self::assertCount(3, $f->service()->seriesView($a->host, 'staff', $id)['windows']);
    }
    public function testMissingOrAlteredMembersAndDamagedSeriesRoutingFailWithoutPartialPrivateResults(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $r = $this->review($f);
        $this->offer($f, $r);
        $id = new EntityId($r['series_id']);
        $s->sql->update('sis_advising_availability_series', ['host_user_id' => $a->owner->value], ['id' => $id->value]);
        $this->rejects(fn() => $f->service()->seriesView($a->host, 'staff', $id), CorruptHistoryException::class);
        $s->sql->update(
            'sis_advising_availability_series',
            ['host_user_id' => $a->advisor->value],
            ['id' => $id->value]
        );
        $s->sql->execute('DELETE FROM sis_advising_availability WHERE id = ?', [$r['window_ids'][1]]);
        $this->rejects(fn() => $f->service()->seriesView($a->host, 'staff', $id), CorruptHistoryException::class);
    }
    public function testAdditiveMigrationRetryPreservesEarlierWindowBytesAndJournal(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $f->offer();
        $before = $s->sql->rows('sis_advising_availability');
        $history = $s->sql->rows('sis_history_events');
        $migration = new \CreateSisAdvisingAvailabilitySeries('1');
        $migration->setObjectMap(new \ArrayObject(['phpmig.adapter' =>
            new \Qubus\Expressive\Migration\Adapter\DbalMigrationAdapter($s->connection, 'test_migrations')]));
        $migration->up();
        self::assertSame($before, $s->sql->rows('sis_advising_availability'));
        self::assertSame($history, $s->sql->rows('sis_history_events'));
        $this->expectException(\LogicException::class);
        $migration->down();
    }
    #[DataProvider('capacityBounds')]
    public function testCurrentCapacityBoundsRejectTheEntireReviewedOffer(int $count, bool $daily): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $r = $this->review($f);
        for ($i = 0; $i < $count; $i++) {
            $start = new DateTimeImmutable('2026-08-11T06:00:00', new \DateTimeZone('America/Los_Angeles'));
            $start = $start->modify($daily ? '+' . ($i * 5) . ' minutes' : '+' . $i . ' days');
            $item = \Domain\Advising\AdvisingAvailability::create(
                EntityId::generate(),
                $a->host,
                $a->advisor,
                $a->staff,
                new \Domain\Advising\AppointmentTime(
                    $start->format('Y-m-d\TH:i'),
                    $start->modify('+5 minutes')->format('Y-m-d\TH:i'),
                    'America/Los_Angeles'
                ),
                5,
                'Fictional preexisting offer.',
                '2026-08-10T17:00:00Z'
            );
            $f->records->save($item, null);
        }
        $before = $s->sql->rows('sis_advising_availability');
        $this->rejects(fn() => $this->offer($f, $r));
        $this->rejects(fn() => $this->review($f));
        self::assertSame($before, $s->sql->rows('sis_advising_availability'));
        self::assertSame([], $s->sql->rows('sis_advising_availability_series'));
    }
    public static function capacityBounds(): array
    {
        return [[20, true], [98, false]];
    }
    public function testMaximumOfferAndClosureTextRemainEncryptedWithinTheEnvelopeBound(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        $s = $a->sis;
        $pattern = $this->review($f)['pattern'];
        $pattern['through'] = '2026-12-22';
        $r = $f->service()->reviewSeries(
            $a->host,
            'staff',
            new AvailabilityPattern($pattern),
            30,
            str_repeat('😀', 1000)
        );
        self::assertCount(20, $r['windows']);
        $this->offer($f, $r);
        $close = $f->service()->reviewSeriesClose(
            $a->host,
            'staff',
            new EntityId($r['series_id']),
            str_repeat('😀', 1000)
        );
        $this->close($f, $close);
        $row = $s->sql->rows('sis_advising_availability_series', ['id' => $r['series_id']], 1)[0];
        self::assertLessThanOrEqual(32768, strlen($row['sealed_data']));
        self::assertStringNotContainsString('😀', $row['sealed_data']);
        self::assertCount(20, $f->service()->seriesView($a->host, 'staff', new EntityId($r['series_id']))['windows']);
    }
    public function testSeriesDirectoryPagesBoundedOriginalOffersWithoutConversationText(): void
    {
        $f = $this->fixture();
        $a = $f->appointments;
        for ($i = 0; $i < 11; $i++) {
            $date = new DateTimeImmutable('2026-08-11')->modify('+' . ($i * 7) . ' days')->format('Y-m-d');
            $r = $this->review($f, ['from' => $date, 'through' => $date]);
            $this->offer($f, $r);
        }
        $page = $f->service()->seriesList($a->host, 'staff');
        self::assertCount(10, $page['items']);
        self::assertCount(1, $f->service()->seriesList($a->host, 'staff', new EntityId($page['next_after']))['items']);
        self::assertStringNotContainsString('Fictional recurring instructions.', json_encode($page));
    }
}
