<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use Domain\Advising\AppointmentTime;
use Domain\Advising\BookingPolicyRevision;
use Domain\Advising\BookingPolicySnapshot;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class BookingPolicyTest extends TestCase
{
    public function testDefaultsAndExactTimeBoundariesPreserveExistingBehavior(): void
    {
        $defaults = BookingPolicySnapshot::defaults();
        self::assertSame(BookingPolicySnapshot::DEFAULTS, $defaults->rules);
        $rules = new BookingPolicySnapshot(EntityId::generate(), 1, ['maximum_active' => 2,
            'notice_minutes' => 60, 'horizon_days' => 2]);
        $now = new DateTimeImmutable('2026-08-10T10:00:00-07:00');
        foreach (
            ['2026-08-10T11:00' => true, '2026-08-10T10:59' => false, '2026-08-12T10:00' => true,
            '2026-08-12T10:01' => false] as $start => $allowed
        ) {
            $end = new DateTimeImmutable($start)->modify('+30 minutes')->format('Y-m-d\TH:i');
            self::assertSame($allowed, $rules->permits(new AppointmentTime($start, $end, 'America/Los_Angeles'), $now));
        }
        self::assertSame($rules->facts(), BookingPolicySnapshot::from($rules->facts())->facts());
    }

    #[DataProvider('invalidRules')]
    public function testMalformedOrContradictoryRulesFailClosed(array $changes): void
    {
        $this->expectException(ValidationException::class);
        new BookingPolicySnapshot(EntityId::generate(), 1, array_replace(BookingPolicySnapshot::DEFAULTS, $changes));
    }

    public static function invalidRules(): array
    {
        return [[['maximum_active' => 0]], [['maximum_active' => 21]], [['notice_minutes' => -1]],
            [['horizon_days' => 367]], [['maximum_active' => '2']], [['horizon_days' => 1,
                'notice_minutes' => 1440]], [['extra' => true]]];
    }

    public function testCalendarHorizonUsesTheRetainedLocalTimezoneAcrossClockChanges(): void
    {
        $rules = new BookingPolicySnapshot(EntityId::generate(), 1, ['maximum_active' => 2,
            'notice_minutes' => 0, 'horizon_days' => 2]);
        $now = new DateTimeImmutable('2026-10-31T10:00:00-07:00');
        self::assertTrue($rules->permits(new AppointmentTime(
            '2026-11-02T10:00',
            '2026-11-02T10:30',
            'America/Los_Angeles'
        ), $now));
        self::assertFalse($rules->permits(new AppointmentTime(
            '2026-11-02T10:01',
            '2026-11-02T10:31',
            'America/Los_Angeles'
        ), $now));
    }

    public function testOriginalPolicyReceiptIsReconstructedAndDebugOutputContainsNoReasonOrActor(): void
    {
        $id = EntityId::generate();
        $actor = EntityId::generate();
        $r = BookingPolicyRevision::create(
            $id,
            0,
            BookingPolicySnapshot::DEFAULTS,
            $actor,
            'Fictional private reason.',
            '2026-08-10T17:00:00Z'
        );
        self::assertSame($r->facts(), BookingPolicyRevision::restore($r->facts())->facts());
        self::assertStringNotContainsString('Fictional', json_encode($r));
        self::assertArrayNotHasKey('receipt', $r->view());
        $damaged = $r->facts();
        $damaged['rules']['maximum_active'] = 1;
        $this->expectException(CorruptHistoryException::class);
        BookingPolicyRevision::restore($damaged);
    }

    public function testAdditivePolicySchemaCompilesAcrossFiveDialects(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000008_CreateSisAdvisingBookingPolicies.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_advising_booking_policies');
            \CreateSisAdvisingBookingPolicies::define($table);
            $c = $this->createStub(Connection::class);
            $c->method('quoteIdentifier')->willReturnCallback(static fn(string $s): string => '"' . $s . '"');
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($c)->create($table), JSON_THROW_ON_ERROR);
            self::assertStringContainsString('sabp_revision', $sql);
            self::assertStringContainsString('sealed_data', $sql);
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }
}
