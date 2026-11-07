<?php

declare(strict_types=1);

use Domain\Registration\Enum\RegistrationStatus;
use Domain\Registration\RegistrationPolicy;
use Domain\Registration\Term;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Domain\Student\Student;
use PHPUnit\Framework\TestCase;

final class RegistrationPolicyTest extends TestCase
{
    public function testCanonicalUlidsRoundTrip(): void
    {
        $id = EntityId::generate();
        self::assertSame($id->value, new EntityId($id->value)->value);
        self::assertSame(26, strlen($id->value));
    }

    public function testNumericIdentifiersAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new EntityId('123');
    }

    public function testAddDropDeadlineIncludesTheWholeDay(): void
    {
        $term = new Term(EntityId::generate(), new DateRange(
            new DateTimeImmutable('2026-08-01'),
            new DateTimeImmutable('2026-12-20'),
        ), new DateTimeImmutable('2026-08-15'));
        self::assertSame(RegistrationStatus::Dropped, $term->dropStatusOn(new DateTimeImmutable('2026-07-20')));
        self::assertSame(RegistrationStatus::Dropped, $term->dropStatusOn(new DateTimeImmutable('2026-08-15 23:59:59')));
        self::assertSame(RegistrationStatus::Withdrawn, $term->dropStatusOn(new DateTimeImmutable('2026-08-16')));
    }

    public function testRegistrationHoldBlocksSelfServiceButCanAllowStaff(): void
    {
        $person = EntityId::generate();
        $restriction = new Restriction(EntityId::generate(), $person, 'NOREG', 99,
            new DateRange(new DateTimeImmutable('2026-01-01')));
        $on = new DateTimeImmutable('2026-08-10');
        self::assertTrue($restriction->blocksRegistration($on, false));
        self::assertFalse($restriction->blocksRegistration($on, true));
    }

    public function testStaffRestrictionIsExplicitAndHistoricalRestrictionsExpire(): void
    {
        $restriction = new Restriction(EntityId::generate(), EntityId::generate(), 'NOREG', 100,
            new DateRange(new DateTimeImmutable('2026-01-01'), new DateTimeImmutable('2026-02-01')), true);
        self::assertTrue($restriction->blocksRegistration(new DateTimeImmutable('2026-01-15'), true));
        self::assertFalse($restriction->blocksRegistration(new DateTimeImmutable('2026-08-10'), true));
    }

    public function testStaffMustStillSatisfyPrerequisites(): void
    {
        $student = new Student(EntityId::generate(), EntityId::generate(), EntityId::generate(), EntityId::generate());
        $this->expectException(DomainException::class);
        new RegistrationPolicy()->assertEligible($student, [], new DateTimeImmutable(), true, true, true, 0, 5, false);
    }
}
