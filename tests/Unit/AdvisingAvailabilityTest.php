<?php

declare(strict_types=1);

namespace Tests\Unit;

use Domain\Advising\AdvisingAvailability;
use Domain\Advising\AppointmentTime;
use Domain\Advising\AvailabilityBooking;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Qubus\Expressive\Connection;
use Qubus\Expressive\Schema\CreateTable;

final class AdvisingAvailabilityTest extends TestCase
{
    private function window(
        ?AppointmentTime $time = null,
        int $minutes = 30,
        string $message = 'Fictional instructions.',
    ): AdvisingAvailability {
        return AdvisingAvailability::create(
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            $time ?? new AppointmentTime('2026-08-11T10:00', '2026-08-11T12:00', 'UTC'),
            $minutes,
            $message,
            '2026-08-10T09:00:00Z'
        );
    }

    #[DataProvider('invalidMinutes')]
    public function testOnlyExactBoundedSlotSizesCanBeOffered(int $minutes): void
    {
        $this->expectException(ValidationException::class);
        $this->window(minutes: $minutes);
    }

    public static function invalidMinutes(): array
    {
        return [[0], [4], [7], [121], [240]];
    }

    public function testSlotsHaveUniquePreciseBoundariesAndCompactBookingEvidenceRejectsOffGridTimes(): void
    {
        $window = $this->window();
        $facts = $window->facts();
        self::assertCount(4, $window->slots());
        self::assertSame('2026-08-11T12:00', $window->slots()[3]->end);
        $source = AvailabilityBooking::from($window);
        self::assertNull($source->slot('2026-08-11T10:05'));
        self::assertSame('2026-08-11T10:00:00Z', $source->slot('2026-08-11T10:00')->startsAt);
        $source->verify(
            new EntityId($facts['host_person_id']),
            new EntityId($facts['host_user_id']),
            $window->slots()[0]
        );
        self::assertStringNotContainsString('Fictional', json_encode($window));
        self::assertStringNotContainsString($facts['id'], json_encode($source));
        self::assertSame($facts, AdvisingAvailability::restore($facts)->facts());
    }

    public function testWindowsCrossingAClockChangeAreRejectedBeforeAnySlotsArePublished(): void
    {
        $this->expectException(ValidationException::class);
        AdvisingAvailability::create(
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            new AppointmentTime('2026-11-01T00:00', '2026-11-01T03:00', 'America/Los_Angeles'),
            30,
            'Fictional clock-change window.',
            '2026-10-01T00:00:00Z'
        );
    }

    #[DataProvider('damage')]
    public function testOriginalAvailabilityAndItsReceiptsAreReconstructedRatherThanTrusted(string $field): void
    {
        $data = $this->window()->facts();
        match ($field) {
            'time' => $data['time']['starts_at'] = '2026-08-12T10:00:00Z',
            'version' => $data['version'] = 1,
            'receipt' => $data['receipts']['create']['result']['status'] = 'closed',
            'intent' => $data['receipts']['create']['intent'] = str_repeat('0', 64),
            'extra' => $data['extra'] = 'Unsupported field',
        };
        $this->expectException(CorruptHistoryException::class);
        AdvisingAvailability::restore($data);
    }

    public static function damage(): array
    {
        return [['time'], ['version'], ['receipt'], ['intent'], ['extra']];
    }

    public function testCompactBookingSourceRetainsItsActualWindowVersionAndHost(): void
    {
        $source = AvailabilityBooking::from($this->window())->facts();
        $source['window_version'] = 1;
        $this->expectException(CorruptHistoryException::class);
        new AvailabilityBooking($source);
    }

    public function testAdditiveWorkingSchemaCompilesForFiveDialectsWithoutChangingTheJournal(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/20261125000006_CreateSisAdvisingAvailability.php';
        foreach (['MySQL', 'PostgreSQL', 'SQLite', 'SQLServer', 'Oracle'] as $dialect) {
            $table = new CreateTable('sis_advising_availability');
            \CreateSisAdvisingAvailability::define($table);
            $connection = $this->createStub(Connection::class);
            $connection->method('quoteIdentifier')->willReturnCallback(
                static fn(string $name): string => '"' . $name . '"'
            );
            $compiler = 'Qubus\\Expressive\\Schema\\Compiler\\' . $dialect;
            $sql = json_encode(new $compiler($connection)->create($table), JSON_THROW_ON_ERROR);
            foreach (['host_person_id', 'host_user_id', 'sava_host_time', 'sealed_data'] as $field) {
                self::assertStringContainsString($field, $sql);
            }
            self::assertDoesNotMatchRegularExpression('/DROP |DELETE FROM|UPDATE |INSERT INTO|AUTO_INCREMENT/i', $sql);
            self::assertStringNotContainsString('sis_history_events', $sql);
        }
    }
}
