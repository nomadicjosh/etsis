<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\RegistrationWindow;
use Domain\Registration\Service\RegistrationWindowService;
use Domain\Registration\ValueObject\EnrollmentHistoryId;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\ValueObject\DateRange;
use Domain\Shared\ValueObject\EntityId;
use Infrastructure\Persistence\Repository\SqlRegistrationWindowRepository;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class RegistrationPolicyPublicationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function window(SisFixture $f, int $limit = 5): RegistrationWindow
    {
        return new RegistrationWindow(
            new DateRange(
                $f->clock->now()->modify("-1 day"),
                $f->clock->now()->modify("+1 day"),
            ),
            $limit,
        );
    }

    private function publish(
        SisFixture $f,
        int $version = 0,
        int $limit = 5,
    ): array {
        $review = $f
            ->windowPolicies()
            ->review(
                $f->term,
                $this->window($f, $limit),
                $version,
                "Fictional approved registration settings.",
            );
        return $f->windowPolicies()->publish($f->term, $review["review_token"]);
    }

    public function testReviewDoesNotWriteAndFirstPublicationObservesRatherThanInventsPriorApproval(): void
    {
        $f = new SisFixture();
        $before = $f->sql->rows("sis_history_events");
        $prior = $f->registration->window($f->term)->values();
        $review = $f
            ->windowPolicies()
            ->review(
                $f->term,
                $this->window($f),
                0,
                "Fictional calendar approval.",
            );
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $accepted = $f
            ->windowPolicies()
            ->publish($f->term, $review["review_token"]);
        self::assertSame(1, $accepted["version"]);
        $page = $f->windowPolicies()->page($f->term);
        self::assertEquals(
            $prior,
            array_intersect_key($page["items"][0]["observed_previous"], $prior),
        );
        self::assertSame(5, $page["items"][0]["definition"]["course_limit"]);
        self::assertTrue($f->windowPolicies()->show($f->term)["published"]);
        $events = iterator_to_array(
            \EventStoreFixture::framework(
                $f->connection->queryBuilder(),
            )->getAggregateHistoryFor(
                new \Domain\Registration\ValueObject\RegistrationWindowId(
                    $f->term->value,
                ),
            ),
        );
        self::assertCount(1, $events);
        self::assertStringNotContainsString(
            "Fictional calendar approval.",
            json_encode($f->sql->rows("sis_history_events")),
        );
        self::assertArrayNotHasKey("reason", $page["items"][0]);
    }

    public function testAcceptedRetryReturnsOriginalPublicationAfterLaterChangesAndExpiry(): void
    {
        $f = new SisFixture();
        $review = $f
            ->windowPolicies()
            ->review($f->term, $this->window($f), 0, "Fictional first policy.");
        $original = $f
            ->windowPolicies()
            ->publish($f->term, $review["review_token"]);
        $later = $this->publish($f, 1, 3);
        $f->clock->time = $f->clock->time->modify("+1 day");
        $events = $f->sql->rows("sis_history_events");
        self::assertSame(
            $original,
            $f->windowPolicies()->publish($f->term, $review["review_token"]),
        );
        self::assertSame($events, $f->sql->rows("sis_history_events"));
        self::assertSame(
            $later["publication_id"],
            $f->sql->rows("sis_reg_windows")[0]["publication_id"],
        );
    }

    public function testReviewedPolicyCannotReplaceAConcurrentPublication(): void
    {
        $f = new SisFixture();
        $review = $f
            ->windowPolicies()
            ->review(
                $f->term,
                $this->window($f),
                0,
                "Fictional pending policy.",
            );
        $this->publish($f);
        $before = $f->sql->rows("sis_history_events");
        try {
            $f->windowPolicies()->publish($f->term, $review["review_token"]);
            self::fail("An older review replaced a later publication.");
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $f->sql->rows("sis_history_events"));
        }
    }

    public function testReviewIsBoundToActorAndExpiresBeforeFirstAcceptance(): void
    {
        $f = new SisFixture();
        $review = $f
            ->windowPolicies()
            ->review(
                $f->term,
                $this->window($f),
                0,
                "Fictional pending policy.",
            );
        $actor = $f->access->actor;
        $f->access->actor = $f->user();
        try {
            $f->windowPolicies()->publish($f->term, $review["review_token"]);
            self::fail("Another staff account accepted this review.");
        } catch (AccessDeniedException) {
            self::assertFalse(
                $f->windowPolicies()->show($f->term)["published"],
            );
        }
        $f->access->actor = $actor;
        $f->clock->time = $f->clock->time->modify("+20 minutes");
        $this->expectException(ConcurrentModificationException::class);
        $f->windowPolicies()->publish($f->term, $review["review_token"]);
    }

    public function testPublishedWindowCannotBeChangedByTheOldDirectWriter(): void
    {
        $f = new SisFixture();
        $this->publish($f);
        $this->expectException(\DomainException::class);
        $f->registration->configureWindow($f->term, $this->window($f, 3));
    }

    public function testAuditFailureRollsBackPublicationAndProjection(): void
    {
        $f = new SisFixture();
        $audit = $this->createStub(AuditTrail::class);
        $audit
            ->method("record")
            ->willThrowException(
                new \RuntimeException("Fictional audit failure."),
            );
        $service = new RegistrationWindowService(
            new SqlRegistrationWindowRepository($f->sql),
            \EventStoreFixture::history($f->connection->queryBuilder()),
            $f->access,
            $f->transactions,
            $audit,
            $f->clock,
            $f->dropCipher,
        );
        $review = $service->review(
            $f->term,
            $this->window($f),
            0,
            "Fictional atomic policy.",
        );
        $before = [
            $f->sql->rows("sis_history_events"),
            $f->sql->rows("sis_reg_windows"),
        ];
        try {
            $service->publish($f->term, $review["review_token"]);
            self::fail("A failed audit committed a policy.");
        } catch (\RuntimeException $error) {
            self::assertSame("Fictional audit failure.", $error->getMessage());
            self::assertSame($before, [
                $f->sql->rows("sis_history_events"),
                $f->sql->rows("sis_reg_windows"),
            ]);
        }
    }

    public function testAcceptedEnrollmentRetainsItsOriginalPolicyWhenLaterRegistrationsUseANewOne(): void
    {
        $f = new SisFixture();
        $first = $this->publish($f);
        $student = $f->student();
        $section = $f->section();
        $enrollment = $f->service->register($student->id, $section->id, true);
        $events = \EventStoreFixture::framework(
            $f->connection->queryBuilder(),
        )->getAggregateHistoryFor(
            new EnrollmentHistoryId($enrollment->id->value),
        );
        $stored = iterator_to_array($events)[0];
        self::assertSame(
            $first["publication_id"],
            $stored->metaParam("registration_window")["publication_id"],
        );
        self::assertSame(5, $stored->payload()["eligibility"]["course_limit"]);
        $this->publish($f, 1, 2);
        $before = $f->sql->rows("sis_history_events");
        self::assertSame(
            $enrollment->id->value,
            $f->service->register($student->id, $section->id, true)->id->value,
        );
        self::assertSame($before, $f->sql->rows("sis_history_events"));
        $next = $f->service->register($student->id, $f->section()->id, true);
        $last = iterator_to_array(
            \EventStoreFixture::framework(
                $f->connection->queryBuilder(),
            )->getAggregateHistoryFor(
                new EnrollmentHistoryId($next->id->value),
            ),
        )[0];
        self::assertSame(2, $last->metaParam("registration_window")["version"]);
        self::assertSame(2, $last->payload()["eligibility"]["course_limit"]);
    }

    public function testSameValuesPublishedAgainInvalidateAPendingRegistrationReview(): void
    {
        $f = new SisFixture();
        $this->publish($f);
        $student = $f->student();
        $section = $f->section();
        $selection = new RegistrationBatchSelection($section->id, $student->id);
        $batches = $f->registrationBatches();
        $review = $batches->review($selection);
        self::assertTrue(
            $review["section"]["registration_window"]["published"],
        );
        $this->publish($f, 1);
        try {
            $batches->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
                $review["review_token"],
            );
            self::fail(
                "A changed publication was accepted using an earlier registration review.",
            );
        } catch (ConcurrentModificationException) {
            self::assertSame([], $f->sql->rows("sis_enrollments"));
        }
    }

    public function testPublishedCourseLimitAppliesToStaffAndStudentsWhileTheWindowStillAllowsStaffOverride(): void
    {
        $f = new SisFixture();
        $window = new RegistrationWindow(
            new DateRange(
                $f->clock->now()->modify("-2 days"),
                $f->clock->now()->modify("-1 day"),
            ),
            1,
        );
        $review = $f
            ->windowPolicies()
            ->review(
                $f->term,
                $window,
                0,
                "Fictional closed registration window.",
            );
        $f->windowPolicies()->publish($f->term, $review["review_token"]);
        $student = $f->student();
        $section = $f->section();
        $f->access->staff = false;
        try {
            $f->service->register($student->id, $section->id);
            self::fail("Student self-registration bypassed the closed window.");
        } catch (\DomainException) {
            self::assertSame([], $f->sql->rows("sis_enrollments"));
        }
        $f->access->staff = true;
        $f->service->register($student->id, $section->id, true);
        $this->expectException(\DomainException::class);
        $f->service->register($student->id, $f->section()->id, true);
    }

    public function testTermChangesRequireANewReviewAndNoPermissionIsGrantedByAReviewToken(): void
    {
        $f = new SisFixture();
        $review = $f
            ->windowPolicies()
            ->review(
                $f->term,
                $this->window($f),
                0,
                "Fictional policy review.",
            );
        $f->access->staff = false;
        try {
            $f->windowPolicies()->publish($f->term, $review["review_token"]);
            self::fail("A review token granted catalog authority.");
        } catch (AccessDeniedException) {
            self::assertNull(
                $f->sql->rows("sis_reg_windows")[0]["publication_id"],
            );
        }
        $f->access->staff = true;
        $f->sql->update(
            "sis_terms",
            ["code" => "NEW/FA"],
            ["id" => $f->term->value],
        );
        $this->expectException(ConcurrentModificationException::class);
        $f->windowPolicies()->publish($f->term, $review["review_token"]);
    }

    public function testProjectionTamperingCannotBecomeAnAcceptedRegistrationPolicy(): void
    {
        $f = new SisFixture();
        $this->publish($f);
        $student = $f->student();
        $section = $f->section();
        $f->sql->update(
            "sis_reg_windows",
            ["course_limit" => 9],
            ["term_id" => $f->term->value],
        );
        try {
            $f->service->register($student->id, $section->id, true);
            self::fail(
                "Registration trusted a projection inconsistent with its publication.",
            );
        } catch (CorruptHistoryException) {
            self::assertSame([], $f->sql->rows("sis_enrollments"));
        }
    }
}
