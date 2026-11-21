<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\ValueObject\EntityId;
use Domain\Registration\ValueObject\ContactHours;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class SectionContactHoursTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/CatalogWorkflowFixture.php";
    }
    public function testFiftyMinuteFallbackUnknownAndExplicitZeroAreDistinct(): void
    {
        $s = new SisFixture();
        $section = $s->section();
        $service = $s->sectionContactHours();
        self::assertNull($service->view($section->id)["calculation"]);
        $service->save($section->id, 150, 0);
        $snapshot = $service->view($section->id)["calculation"];
        self::assertSame(3000, $snapshot["weekly_contact_milli"]);
        self::assertSame(50, $snapshot["unit_minutes"]);
        self::assertSame(0, $snapshot["policy_version"]);
        self::assertNull($snapshot["policy_publication_id"]);
        $service->save($section->id, 0, 1);
        self::assertSame(0, $service->view($section->id)["calculation"]["weekly_contact_milli"]);
        $service->save($section->id, null, 2);
        self::assertNull($service->view($section->id)["calculation"]);
    }
    public function testInstitutionalCalculationAndRoundingAreExplicitAndExact(): void
    {
        self::assertSame(333, ContactHours::calculate(1, 3, "nearest"));
        self::assertSame(333, ContactHours::calculate(1, 3, "down"));
        self::assertSame(334, ContactHours::calculate(1, 3, "up"));
        self::assertSame(63, ContactHours::calculate(1, 16, "nearest"));
        $s = new SisFixture();
        $section = $s->section();
        $s->sectionContactHours()->save($section->id, 150, 0);
        $p = $s->timeApprovalPolicies();
        $r = $p->view();
        $review = $p->review(
            array_replace($r["rules"], [
                "contact_unit_minutes" => 60,
                "contact_rounding" => "down",
            ]),
            0,
            "Fictional institutional faculty contact units.",
        );
        $p->publish(
            new EntityId($review["publication_id"]),
            $review["rules"],
            0,
            $review["reason"],
            $review["review_token"],
        );
        $value = $s->sectionContactHours()->snapshot($section->id);
        self::assertSame(2500, $value["weekly_contact_milli"]);
        self::assertSame(1, $value["policy_version"]);
    }
    public function testDraftConcurrencyFacultyReadAndIndependentPolicyAuthority(): void
    {
        $s = new SisFixture();
        $section = $s->section(faculty: $s->access->actor);
        $service = $s->sectionContactHours();
        $service->save($section->id, 150, 0);
        try {
            $service->save($section->id, 200, 0);
            self::fail("A stale draft overwrote current minutes.");
        } catch (ConcurrentModificationException) {
            self::assertSame(150, $service->view($section->id)["weekly_minutes"]);
        }
        $s->access->staff = false;
        self::assertSame(3000, $service->view($section->id)["calculation"]["weekly_contact_milli"]);
        try {
            $service->save($section->id, 200, 1);
            self::fail("Assigned faculty edited the working definition without authority.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->actor = $s->user();
        $this->expectException(AccessDeniedException::class);
        $service->view($section->id);
    }
}
