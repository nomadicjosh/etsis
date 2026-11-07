<?php

declare(strict_types=1);

use Domain\Gradebook\AssignmentScore;
use Domain\Scheduling\BookingSeries;
use Domain\Scheduling\TimeSlot;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Transfer\TransferEquivalency;
use PHPUnit\Framework\TestCase;

final class ModulePoliciesTest extends TestCase
{
    public function testBookingDetectsEnclosedConflictAndAllowsAdjacentClasses(): void
    {
        $class = new TimeSlot(new DateTimeImmutable('2026-08-01 10:00'), new DateTimeImmutable('2026-08-01 11:00'));
        self::assertTrue($class->overlaps(new TimeSlot(
            new DateTimeImmutable('2026-08-01 09:00'), new DateTimeImmutable('2026-08-01 12:00'),
        )));
        self::assertFalse($class->overlaps(new TimeSlot(
            new DateTimeImmutable('2026-08-01 11:00'), new DateTimeImmutable('2026-08-01 12:00'),
        )));
    }

    public function testWeeklyClassesKeepLocalTimeAcrossDaylightSaving(): void
    {
        $timezone = new DateTimeZone('America/Los_Angeles');
        $first = new TimeSlot(new DateTimeImmutable('2026-03-01 10:00', $timezone),
            new DateTimeImmutable('2026-03-01 11:00', $timezone));
        $slots = new BookingSeries()->occurrences($first, new DateTimeImmutable('2026-03-15 10:00', $timezone), 7);
        self::assertCount(3, $slots);
        self::assertSame('10:00', $slots[1]->startsAt->format('H:i'));
        self::assertSame('-07:00', $slots[1]->startsAt->format('P'));
    }

    public function testUngradedAssignmentIsDifferentFromZero(): void
    {
        $assignment = EntityId::generate();
        $student = EntityId::generate();
        self::assertNull(new AssignmentScore($assignment, $student, null, 10000)->percentageHundredths());
        self::assertSame(0, new AssignmentScore($assignment, $student, 0, 10000)->percentageHundredths());
        self::assertSame(8750, new AssignmentScore($assignment, $student, 8750, 10000)->percentageHundredths());
    }

    public function testTransferRequiresMatchingInstitutionAndMinimumGrade(): void
    {
        $institution = EntityId::generate();
        $equivalency = new TransferEquivalency(EntityId::generate(), $institution, EntityId::generate(),
            EntityId::generate(), new DateRange(new DateTimeImmutable('2020-01-01')), 2, 3000, 'TR');
        $equivalency->assertEligible($institution, new DateTimeImmutable('2026-05-01'), 2);
        $this->expectException(DomainException::class);
        $equivalency->assertEligible(EntityId::generate(), new DateTimeImmutable('2026-05-01'), 2);
    }
}
