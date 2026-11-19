<?php

declare(strict_types=1);

namespace Tests\Unit;

use DateTimeImmutable;
use Domain\Registration\RegistrationPolicy;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Domain\Student\Restriction;
use Domain\Student\Student;
use DomainException;
use PHPUnit\Framework\TestCase;

final class RegistrationReadinessTest extends TestCase
{
    private function student(bool $active = true): Student
    {
        return new Student(
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            EntityId::generate(),
            $active,
        );
    }

    public function testIndependentBlockersHaveSpecificNextStepsWithoutLeakingRestrictionFacts(): void
    {
        $student = $this->student(false);
        $on = new DateTimeImmutable("2026-08-10");
        $hold = new Restriction(
            EntityId::generate(),
            $student->personId,
            "PRIVATE.FIN",
            99,
            new DateRange($on),
        );
        $policy = new RegistrationPolicy();
        $checks = $policy->checks($student, [$hold], $on, false, false, false, 5, 5, false);
        self::assertCount(6, $checks);
        self::assertSame(["blocked"], array_values(array_unique(array_column($checks, "status"))));
        self::assertSame(
            array_column($checks, "message"),
            $policy->issues($student, [$hold], $on, false, false, false, 5, 5, false),
        );
        foreach ($checks as $check) {
            self::assertNotEmpty($check["next_step"]);
        }
        $json = json_encode($checks, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($hold->code, $json);
        self::assertStringNotContainsString($hold->id->value, $json);
        self::assertStringNotContainsString($student->personId->value, $json);
        $this->expectException(DomainException::class);
        $policy->assertEligible($student, [$hold], $on, false, false, false, 5, 5, false);
    }

    public function testStaffAvailabilityExceptionsDoNotBypassOtherChecks(): void
    {
        $student = $this->student();
        $on = new DateTimeImmutable("2026-08-10");
        $hold = new Restriction(
            EntityId::generate(),
            $student->personId,
            "PRIVATE.HOLD",
            1,
            new DateRange($on),
            true,
        );
        $checks = array_column(
            new RegistrationPolicy()->checks($student, [$hold], $on, true, false, false, 5, 5, false),
            null,
            "code",
        );
        self::assertSame("not_applicable", $checks["registration_window"]["status"]);
        self::assertSame("not_applicable", $checks["web_registration"]["status"]);
        foreach (["prerequisites", "restrictions", "course_limit"] as $key) {
            self::assertSame("blocked", $checks[$key]["status"]);
        }
    }

    public function testClearChecksHaveNoInventedNextActionAndForeignHoldsAreRejected(): void
    {
        $student = $this->student();
        $on = new DateTimeImmutable("2026-08-10");
        $checks = new RegistrationPolicy()->checks($student, [], $on, false, true, true, 0, 5, true);
        self::assertSame(["met"], array_values(array_unique(array_column($checks, "status"))));
        self::assertSame([null], array_values(array_unique(array_column($checks, "next_step"))));
        $foreign = new Restriction(
            EntityId::generate(),
            EntityId::generate(),
            "FOREIGN",
            99,
            new DateRange($on),
        );
        $this->expectException(DomainException::class);
        new RegistrationPolicy()->checks($student, [$foreign], $on, false, true, true, 0, 5, true);
    }
}
