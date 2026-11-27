<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\ValueObject\RegistrationPlanSelection;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class RegistrationPlanTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function fixture(): array
    {
        $f = new SisFixture();
        $owner = $f->user();
        $student = $f->student($owner)->id;
        $a = $f->section(fee: 1000)->id;
        $b = $f->section(fee: 2000)->id;
        return [$f, new RegistrationPlanSelection($student, $a, $b), $owner];
    }

    private function commit(
        SisFixture $f,
        RegistrationPlanSelection $plan,
        array $review,
        bool $staff = true,
    ): array {
        return $f
            ->registrationBatches()
            ->commitPlan(
                new EntityId($review["operation_id"]),
                $plan,
                $review["revision"],
                $review["review_token"],
                $staff,
            );
    }

    private function snapshot(SisFixture $f): array
    {
        return array_map($f->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_enrollments",
            "sis_academic_credits",
            "sis_student_accounts",
            "sis_registration_batches",
            "sis_audit",
        ]);
    }

    public function testOneReviewCreatesAllSectionsAndChargesAndRetriesReturnTheOriginalResult(): void
    {
        [$f, $plan] = $this->fixture();
        $before = $this->snapshot($f);
        $review = $f->registrationBatches()->reviewPlan($plan, true);
        self::assertTrue($review["can_confirm"]);
        self::assertSame(2, $review["planned_schedule"]["unpublished_count"]);
        self::assertSame(3000, $review["totals"]["USD"]);
        self::assertSame($before, $this->snapshot($f));
        $receipt = $this->commit($f, $plan, $review);
        self::assertSame(2, $receipt["created"]);
        self::assertCount(2, $f->sql->rows("sis_enrollments"));
        self::assertCount(2, $f->sql->rows("sis_academic_credits"));
        self::assertSame(3000, $f->sql->rows("sis_account_balances")[0]["balance_minor"]);
        $after = $this->snapshot($f);
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame($receipt, $this->commit($f, $plan, $review));
        self::assertSame($after, $this->snapshot($f));
    }

    public function testStudentOwnerUsesTheSameAtomicFlowButCannotUseStaffConfirmation(): void
    {
        [$f, $plan, $owner] = $this->fixture();
        $f->access->staff = false;
        $f->access->actor = $owner;
        $review = $f->registrationBatches()->reviewPlan($plan, false);
        try {
            $this->commit($f, $plan, $review);
            self::fail("Student used the staff channel.");
        } catch (AccessDeniedException) {
            self::assertSame([], $f->sql->rows("sis_enrollments"));
        }
        self::assertSame(2, $this->commit($f, $plan, $review, false)["created"]);
    }

    public function testCourseLimitIncludesEverySelectedSectionAndFullSectionsBlockTheWholePlan(): void
    {
        foreach (["limit", "full"] as $failure) {
            [$f, $plan] = $this->fixture();
            if ($failure === "limit") {
                $f->sql->update("sis_reg_windows", ["course_limit" => 1], ["term_id" => $f->term->value]);
            } else {
                $f->sql->update("sis_sections", ["active" => 0], ["id" => $plan->sections[1]->value]);
            }
            $before = $this->snapshot($f);
            $review = $f->registrationBatches()->reviewPlan($plan, true);
            self::assertFalse($review["can_confirm"]);
            try {
                $this->commit($f, $plan, $review);
                self::fail("An ineligible schedule was accepted.");
            } catch (ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testFailureInTheLastAuditRollsBackEveryEnrollmentAcademicCreditChargeAndReceipt(): void
    {
        [$f, $plan] = $this->fixture();
        $review = $f->registrationBatches()->reviewPlan($plan, true);
        $before = $this->snapshot($f);
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                throw new \RuntimeException("Fictional plan audit failure.");
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        try {
            $f->registrationBatches($audit)->commitPlan(
                new EntityId($review["operation_id"]),
                $plan,
                $review["revision"],
                $review["review_token"],
                true,
            );
            self::fail("A failed coordinated decision committed.");
        } catch (\RuntimeException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }
    public function testTermFeesAreChargedOnceAndCreditPricesCoverEverySelectedSection(): void
    {
        [$f, $plan] = $this->fixture();
        $db = $f->connection->queryBuilder();
        $rules = new \Domain\Finance\Service\TermBillingService(
            new \Infrastructure\Persistence\Repository\EventSourcedBillingRuleRepository(
                $f->sql,
                \EventStoreFixture::framework($db),
                $f->transactions,
                \EventStoreFixture::financialWriter($db),
            ),
            $f->access,
            $f->transactions,
            $f->audit,
            $f->financialCommands(),
        );
        foreach ([["term", 500], ["registered_credit", 100]] as [$basis, $amount]) {
            $rules->define(
                EntityId::generate(),
                $f->term,
                null,
                "Fictional " . $basis,
                "tuition",
                new \Domain\Finance\ValueObject\Money($amount, "USD"),
                "Fictional approved price.",
                \Domain\Finance\Enum\BillingBasis::from($basis),
            );
        }
        $review = $f->registrationBatches()->reviewPlan($plan, true);
        self::assertTrue($review["can_confirm"]);
        self::assertSame(4100, $review["totals"]["USD"]);
        $items = array_merge(
            ...array_map(fn($row) => $row["student"]["charges"]["items"], $review["sections"]),
        );
        self::assertCount(1, array_filter($items, fn($item) => $item["status"] === "included_in_schedule"));
        $this->commit($f, $plan, $review);
        self::assertSame(4100, $f->sql->rows("sis_account_balances")[0]["balance_minor"]);
    }

    public function testProposedSectionsAreCheckedAgainstEachOtherWithTheInstitutionalOverlapPolicy(): void
    {
        require_once dirname(__DIR__) . "/Support/RegistrationScheduleFixture.php";
        foreach (["block", "warn"] as $mode) {
            [$f, $plan] = $this->fixture();
            $t = new \Tests\Support\RegistrationScheduleFixture($f);
            $t->publish($f->registration->section($plan->sections[0]), [
                ["2026-09-01T16:00:00Z", "2026-09-01T17:00:00Z"],
            ]);
            $t->publish($f->registration->section($plan->sections[1]), [
                ["2026-09-01T16:30:00Z", "2026-09-01T17:30:00Z"],
            ]);
            if ($mode === "warn") {
                $t->policy()->save("warn", 0);
            }
            $review = $f->registrationBatches()->reviewPlan($plan, true);
            self::assertCount(1, $review["planned_schedule"]["conflicts"]);
            self::assertSame($mode === "warn", $review["can_confirm"]);
            if ($mode === "warn") {
                self::assertSame(2, $this->commit($f, $plan, $review)["created"]);
            } else {
                self::assertSame([], $f->sql->rows("sis_enrollments"));
            }
        }
    }

    public function testStaleExpiredChangedSelectionsAndForeignReviewsCannotWrite(): void
    {
        foreach (["stale", "expired", "actor", "selection", "channel"] as $failure) {
            [$f, $plan] = $this->fixture();
            $review = $f->registrationBatches()->reviewPlan($plan, true);
            if ($failure === "expired") {
                $f->clock->time = $f->clock->time->modify("+21 minutes");
            }
            if ($failure === "stale") {
                $f->sql->update("sis_sections", ["fee_minor" => 9999], ["id" => $plan->sections[0]->value]);
            }
            if ($failure === "actor") {
                $f->access->actor = $f->user();
            }
            if ($failure === "selection") {
                $plan = new RegistrationPlanSelection($plan->student, $plan->sections[0]);
            }
            $before = $this->snapshot($f);
            try {
                $this->commit($f, $plan, $review, $failure !== "channel");
                self::fail("An invalid schedule review committed.");
            } catch (AccessDeniedException | ConcurrentModificationException | ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testFinalAuditExpiryPermissionLossAndSectionChangesRollBackTheWholePlan(): void
    {
        foreach (["expiry", "permission", "section", "owner", "policy"] as $failure) {
            [$f, $plan] = $this->fixture();
            $review = $f->registrationBatches()->reviewPlan($plan, true);
            $before = $this->snapshot($f);
            $audit = new class ($f, $plan, $failure) implements \Domain\Audit\Repository\AuditTrail {
                public function __construct(
                    private SisFixture $f,
                    private RegistrationPlanSelection $plan,
                    private string $failure,
                ) {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($this->failure === "expiry") {
                        $this->f->clock->time = $this->f->clock->time->modify("+21 minutes");
                    }
                    if ($this->failure === "permission") {
                        $this->f->access->staff = false;
                        $this->f->access->grants = [];
                    }
                    if ($this->failure === "section") {
                        $this->f->sql->update(
                            "sis_sections",
                            ["capacity" => 30],
                            ["id" => $this->plan->sections[0]->value],
                        );
                    }
                    if ($this->failure === "owner") {
                        $this->f->sql->update(
                            "sis_persons",
                            ["owner_user_id" => $this->f->user()->value],
                            ["id" => $this->f->admissions->student($this->plan->student)->personId->value],
                        );
                    }
                    if ($this->failure === "policy") {
                        require_once dirname(__DIR__) . "/Support/RegistrationScheduleFixture.php";
                        new \Tests\Support\RegistrationScheduleFixture($this->f)->policy()->save("warn", 0);
                    }
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            try {
                $f->registrationBatches($audit)->commitPlan(
                    new EntityId($review["operation_id"]),
                    $plan,
                    $review["revision"],
                    $review["review_token"],
                    true,
                );
                self::fail("A changed or expired final schedule committed.");
            } catch (AccessDeniedException | ConcurrentModificationException | ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testMixedCurrenciesWithinOneTermAreBlockedWithoutDisclosingAnAccountBalance(): void
    {
        [$f, $plan] = $this->fixture();
        $f->sql->update("sis_sections", ["currency" => "EUR"], ["id" => $plan->sections[1]->value]);
        $review = $f->registrationBatches()->reviewPlan($plan, true);
        self::assertFalse($review["can_confirm"]);
        self::assertStringNotContainsString(
            "balance_before_minor",
            json_encode($review, JSON_THROW_ON_ERROR),
        );
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
    }

    public function testAcceptedRetryDoesNotRestoreASectionRemovedByALaterEarlyDrop(): void
    {
        [$f, $plan] = $this->fixture();
        $review = $f->registrationBatches()->reviewPlan($plan, true);
        $receipt = $this->commit($f, $plan, $review);
        $f->drops->drop($plan->student, $plan->sections[0]);
        $before = $this->snapshot($f);
        self::assertSame($receipt, $this->commit($f, $plan, $review));
        self::assertSame($before, $this->snapshot($f));
        self::assertCount(1, $f->sql->rows("sis_enrollments"));
    }

    public function testSelectionRejectsDuplicatesEmptyAndOversizedRequestsBeforeAnyWork(): void
    {
        [$f, $plan] = $this->fixture();
        $cases = [
            [],
            [$plan->sections[0], $plan->sections[0]],
            array_map(static fn() => EntityId::generate(), range(1, 11)),
        ];
        foreach ($cases as $sections) {
            try {
                new RegistrationPlanSelection($plan->student, ...$sections);
                self::fail("An invalid working selection was accepted.");
            } catch (ValidationException) {
                self::assertSame([], $f->sql->rows("sis_enrollments"));
            }
        }
    }
    public function testSelectedPrerequisiteCourseDoesNotCountAsCompletedWork(): void
    {
        [$f, $plan] = $this->fixture();
        $first = $f->registration->section($plan->sections[0])->courseId;
        $second = $f->registration->section($plan->sections[1])->courseId;
        $f->registration->addPrerequisite($second, $first);
        $review = $f->registrationBatches()->reviewPlan($plan, true);
        self::assertFalse($review["can_confirm"]);
        self::assertSame([], $f->sql->rows("sis_academic_credits"));
    }

    public function testCombinedAmountOverflowIsAnExplicitUnknownTotalAndCannotCreateAccounts(): void
    {
        [$f, $plan] = $this->fixture();
        foreach ($plan->sections as $section) {
            $f->sql->update("sis_sections", ["fee_minor" => 1500000000], ["id" => $section->value]);
        }
        $review = $f->registrationBatches()->reviewPlan($plan, true);
        self::assertFalse($review["can_confirm"]);
        self::assertNull($review["totals"]["USD"]);
        self::assertSame([], $f->sql->rows("sis_student_accounts"));
    }

    public function testFailureWhileAcceptingTheSecondSectionRollsBackTheFirstSectionToo(): void
    {
        [$f, $plan] = $this->fixture();
        $review = $f->registrationBatches()->reviewPlan($plan, true);
        $before = $this->snapshot($f);
        $audit = new class implements \Domain\Audit\Repository\AuditTrail {
            public int $calls = 0;
            public function record(EntityId $actor, string $action, EntityId $subject): void
            {
                if (++$this->calls === 2) {
                    throw new \RuntimeException("Fictional second section failure.");
                }
            }
            public function forSubject(EntityId $subject): array
            {
                return [];
            }
        };
        $core = new \Domain\Registration\Service\RegistrationService(
            $f->registration,
            $f->admissions,
            $f->access,
            $f->transactions,
            $f->registrationBilling,
            $audit,
            $f->clock,
            $f->drops,
            $f->academicCredits(),
            $f->enrollmentLedger(),
            $f->activityLedger(),
            $f->primaryProgramLedger(),
            $f->workPursuits(),
            $f->prerequisites(),
            $f->schedules(),
            $f->corequisites(),
            $f->windowPolicies(),
            $f->waitlistGuard($audit),
            $f->graduateGate(),
            $f->enrollmentNotifications(),
        );
        try {
            $f->registrationBatches(registration: $core)->commitPlan(
                new EntityId($review["operation_id"]),
                $plan,
                $review["revision"],
                $review["review_token"],
                true,
            );
            self::fail("A second-section failure left a partial schedule.");
        } catch (\RuntimeException) {
            self::assertSame(2, $audit->calls);
            self::assertSame($before, $this->snapshot($f));
        }
    }
}
