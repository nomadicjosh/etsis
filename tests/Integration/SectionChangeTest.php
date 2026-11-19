<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\ValueObject\SectionChangeSelection;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class SectionChangeTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }

    private function fixture(bool $priced = false): array
    {
        $f = new SisFixture();
        $owner = $f->user();
        $student = $f->student($owner)->id;
        $a = $f->section(fee: 1000)->id;
        $b = $f->section(fee: 1500)->id;
        $f->sql->update(
            "sis_sections",
            ["course_id" => $f->registration->section($a)->courseId->value],
            ["id" => $b->value],
        );
        if ($priced) {
            foreach (["term" => 500, "registered_credit" => 100] as $basis => $minor) {
                $this->rules($f)->define(
                    EntityId::generate(),
                    $f->term,
                    null,
                    "Fictional " . $basis,
                    "tuition",
                    new \Domain\Finance\ValueObject\Money($minor, "USD"),
                    "Fictional approved price.",
                    \Domain\Finance\Enum\BillingBasis::from($basis),
                );
            }
        }
        $f->service->register($student, $a, true);
        return [$f, new SectionChangeSelection($student, $a, $b), $owner];
    }

    private function rules(SisFixture $f): \Domain\Finance\Service\TermBillingService
    {
        $db = $f->connection->queryBuilder();
        return new \Domain\Finance\Service\TermBillingService(
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
    }

    private function commit(
        SisFixture $f,
        SectionChangeSelection $selection,
        array $review,
        bool $staff = true,
    ): array {
        return $f
            ->sectionChanges()
            ->commit(
                new EntityId($review["operation_id"]),
                $selection,
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
            "sis_account_balances",
            "sis_registration_batches",
            "sis_course_drop_receipts",
            "sis_audit",
            "sis_sections",
            "sis_terms",
            "sis_registration_policy",
        ]);
    }

    public function testOneConfirmationReplacesEnrollmentAndReversesOriginalCourseCharges(): void
    {
        [$f, $selection] = $this->fixture();
        $before = $this->snapshot($f);
        $review = $f->sectionChanges()->review($selection, true);
        self::assertSame($before, $this->snapshot($f));
        self::assertTrue($review["can_confirm"]);
        self::assertSame(1000, $review["financial"]["credit_minor"]);
        self::assertSame(1500, $review["financial"]["new_charge_minor"]);
        self::assertSame(500, $review["financial"]["net_change_minor"]);
        self::assertArrayNotHasKey("drop", $review);
        $result = $this->commit($f, $selection, $review);
        self::assertSame("reversed", $result["course_charge"]);
        self::assertCount(1, $f->sql->rows("sis_enrollments"));
        self::assertCount(1, $f->sql->rows("sis_academic_credits"));
        self::assertSame(1500, $f->sql->rows("sis_account_balances")[0]["balance_minor"]);
        self::assertNotSame($result["removed_enrollment_id"], $result["enrollment_id"]);
        $after = $this->snapshot($f);
        $f->clock->time = $f->clock->time->modify("+1 day");
        self::assertSame($result, $this->commit($f, $selection, $review));
        self::assertSame($after, $this->snapshot($f));
    }
    public function testStudentOwnerHasTheSameFlowWithoutStaffAuthority(): void
    {
        [$f, $selection, $owner] = $this->fixture();
        $f->access->actor = $owner;
        $f->access->staff = false;
        $review = $f->sectionChanges()->review($selection, false);
        self::assertTrue($review["can_confirm"]);
        self::assertSame(
            $selection->to->value,
            $this->commit($f, $selection, $review, false)["to_section_id"],
        );
    }

    public function testOriginalResultRetryAfterReplacementIsDroppedDoesNotRestoreAnything(): void
    {
        [$f, $selection] = $this->fixture();
        $review = $f->sectionChanges()->review($selection, true);
        $receipt = $this->commit($f, $selection, $review);
        $f->service->drop($selection->student, $selection->to);
        $before = $this->snapshot($f);
        self::assertSame($receipt, $this->commit($f, $selection, $review));
        self::assertSame($before, $this->snapshot($f));
        self::assertSame([], $f->sql->rows("sis_enrollments"));
    }

    public function testChangedPriceFullSectionExpiredReviewAndForeignActorNeverRemoveSource(): void
    {
        foreach (["price", "full", "expired", "actor", "channel", "owner"] as $case) {
            [$f, $selection] = $this->fixture();
            $review = $f->sectionChanges()->review($selection, true);
            if ($case === "price") {
                $f->sql->update("sis_sections", ["fee_minor" => 3000], ["id" => $selection->to->value]);
            }
            if ($case === "full") {
                $f->sql->update("sis_sections", ["capacity" => 1], ["id" => $selection->to->value]);
                $f->service->register($f->student()->id, $selection->to, true);
            }
            if ($case === "expired") {
                $f->clock->time = $f->clock->time->modify("+21 minutes");
            }
            if ($case === "actor") {
                $f->access->actor = $f->user();
            }
            if ($case === "owner") {
                $f->access->staff = false;
                $f->access->actor = $f->user();
            }
            $before = $this->snapshot($f);
            try {
                $this->commit($f, $selection, $review, !in_array($case, ["channel", "owner"], true));
                self::fail("An invalid section change committed.");
            } catch (ConcurrentModificationException | AccessDeniedException | ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testLateGradedDifferentCourseDifferentTermAndExistingTargetAreRejected(): void
    {
        foreach (["late", "grade", "course", "term", "existing"] as $case) {
            [$f, $selection] = $this->fixture();
            if ($case === "late") {
                $f->clock->time = $f->clock->time->setDate(2026, 8, 16);
            }
            if ($case === "grade") {
                $f->sql->update(
                    "sis_academic_credits",
                    ["completed_milli" => 3000],
                    ["student_id" => $selection->student->value],
                );
            }
            if ($case === "course") {
                $selection = new SectionChangeSelection(
                    $selection->student,
                    $selection->from,
                    $f->section()->id,
                );
            }
            if ($case === "term") {
                $term = $f->sql->one("sis_terms", ["id" => $f->term->value]);
                $term["id"] = EntityId::generate()->value;
                $term["code"] = "OTHER";
                $f->sql->insert("sis_terms", $term);
                $f->sql->update("sis_sections", ["term_id" => $term["id"]], ["id" => $selection->to->value]);
            }
            if ($case === "existing") {
                $f->service->register($selection->student, $selection->to, true);
            }
            $before = $this->snapshot($f);
            try {
                $f->sectionChanges()->review($selection, true);
                self::fail("An unsupported section change was offered.");
            } catch (ValidationException) {
                self::assertSame($before, $this->snapshot($f));
            }
        }
    }

    public function testCourseLimitAndOverlapExcludeSourceButKeepOtherEnrollments(): void
    {
        require_once dirname(__DIR__) . "/Support/RegistrationScheduleFixture.php";
        [$f, $selection] = $this->fixture();
        $t = new \Tests\Support\RegistrationScheduleFixture($f);
        $meeting = [["2026-09-01T16:00:00Z", "2026-09-01T17:00:00Z"]];
        $t->publish($f->registration->section($selection->from), $meeting);
        $t->publish($f->registration->section($selection->to), $meeting);
        $f->sql->update("sis_reg_windows", ["course_limit" => 1], ["term_id" => $f->term->value]);
        $review = $f->sectionChanges()->review($selection, true);
        self::assertTrue($review["can_confirm"]);
        self::assertFalse($review["target"]["students"][0]["schedule"]["blocked"]);
        $this->commit($f, $selection, $review);
        self::assertCount(1, $f->sql->rows("sis_enrollments"));
    }

    public function testUnrelatedPublishedMeetingStillBlocksReplacement(): void
    {
        require_once dirname(__DIR__) . "/Support/RegistrationScheduleFixture.php";
        [$f, $selection] = $this->fixture();
        $other = $f->section(fee: 0)->id;
        $f->service->register($selection->student, $other, true);
        $t = new \Tests\Support\RegistrationScheduleFixture($f);
        $meeting = [["2026-09-01T16:00:00Z", "2026-09-01T17:00:00Z"]];
        $t->publish($f->registration->section($other), $meeting);
        $t->publish($f->registration->section($selection->to), $meeting);
        $review = $f->sectionChanges()->review($selection, true);
        self::assertFalse($review["can_confirm"]);
        $before = $this->snapshot($f);
        try {
            $this->commit($f, $selection, $review);
            self::fail("A conflicting replacement committed.");
        } catch (ValidationException) {
            self::assertSame($before, $this->snapshot($f));
        }
    }

    public function testFinalFailureExpiryPermissionAndProjectionChangesRollBackDropAndAddTogether(): void
    {
        $failures = [
            "exception",
            "expiry",
            "permission",
            "section",
            "source",
            "credit",
            "calendar",
            "policy",
            "day",
        ];
        foreach ($failures as $case) {
            [$f, $selection] = $this->fixture();
            $review = $f->sectionChanges()->review($selection, true);
            $before = $this->snapshot($f);
            $audit = new class ($f, $selection, $case) implements \Domain\Audit\Repository\AuditTrail {
                public function __construct(
                    private SisFixture $f,
                    private SectionChangeSelection $selection,
                    private string $case,
                ) {
                }
                public function record(EntityId $actor, string $action, EntityId $subject): void
                {
                    if ($this->case === "exception") {
                        throw new \RuntimeException("Fictional final audit failure.");
                    }
                    if ($this->case === "expiry") {
                        $this->f->clock->time = $this->f->clock->time->modify("+21 minutes");
                    }
                    if ($this->case === "permission") {
                        $this->f->access->staff = false;
                        $this->f->access->actor = $this->f->user();
                    }
                    if ($this->case === "section") {
                        $this->f->sql->update(
                            "sis_sections",
                            ["fee_minor" => 2500],
                            ["id" => $this->selection->to->value],
                        );
                    }
                    if ($this->case === "source") {
                        $row = $this->f->sql->rows("sis_enrollments")[0];
                        $row["id"] = EntityId::generate()->value;
                        $row["section_id"] = $this->selection->from->value;
                        $this->f->sql->insert("sis_enrollments", $row);
                    }
                    if ($this->case === "credit") {
                        $this->f->sql->update(
                            "sis_academic_credits",
                            ["completed_milli" => 3000],
                            ["student_id" => $this->selection->student->value],
                        );
                    }
                    if ($this->case === "calendar") {
                        $this->f->sql->update(
                            "sis_terms",
                            ["add_drop_ends_on" => "2026-08-01"],
                            ["id" => $this->f->term->value],
                        );
                    }
                    if ($this->case === "policy") {
                        $policy = $this->f->sql->rows("sis_registration_policy")[0];
                        $this->f->sql->update(
                            "sis_registration_policy",
                            ["schedule_mode" => "warn", "revision" => 1],
                            ["id" => $policy["id"]],
                        );
                    }
                    if ($this->case === "day") {
                        $this->f->clock->time = $this->f->clock->time->modify("+1 day");
                    }
                }
                public function forSubject(EntityId $subject): array
                {
                    return [];
                }
            };
            try {
                $f->sectionChanges($audit)->commit(
                    new EntityId($review["operation_id"]),
                    $selection,
                    $review["revision"],
                    $review["review_token"],
                    true,
                );
                self::fail("A final failure left a partial section change.");
            } catch (\DomainException | \RuntimeException $e) {
                self::assertSame($before, $this->snapshot($f), $case . ": " . $e->getMessage());
            }
        }
    }

    public function testChoicesAreBoundedSameCourseAndTermAndSearchEscapesWildcards(): void
    {
        [$f, $selection] = $this->fixture();
        for ($i = 0; $i < 22; $i++) {
            $row = $f->sql->one("sis_sections", ["id" => $selection->to->value]);
            $row["id"] = EntityId::generate()->value;
            $row["code"] = "S." . $i;
            $f->sql->insert("sis_sections", $row);
        }
        $f->section();
        $before = $this->snapshot($f);
        $page = $f->sectionChanges()->choices($selection->student, $selection->from, true);
        self::assertCount(20, $page["items"]);
        self::assertNotNull($page["next_after"]);
        $next = $f
            ->sectionChanges()
            ->choices($selection->student, $selection->from, true, after: new EntityId($page["next_after"]));
        self::assertCount(3, $next["items"]);
        self::assertSame(
            [],
            array_intersect(array_column($page["items"], "id"), array_column($next["items"], "id")),
        );
        self::assertSame(
            [],
            $f->sectionChanges()->choices($selection->student, $selection->from, true, "%")["items"],
        );
        self::assertSame($before, $this->snapshot($f));
    }

    public function testReversalAllowsReplacementBelowAccountLimitAndUsesOriginalPrice(): void
    {
        [$f, $selection] = $this->fixture();
        $f->sql->update("sis_sections", ["fee_minor" => 1500000000], ["id" => $selection->to->value]);
        $account = new \Domain\Finance\ValueObject\AccountId($f->sql->rows("sis_student_accounts")[0]["id"]);
        $root = $f->accounts->get($account);
        $root->post(
            EntityId::generate(),
            \Domain\Finance\Enum\EntryType::Charge,
            new \Domain\Finance\ValueObject\Money(500000000, "USD"),
            $f->access->actor,
        );
        $f->transactions->run(
            fn() => $f->accounts->save(
                $root,
                new \Domain\Shared\EventSourcing\EventContext(
                    $f->access->actor,
                    $f->clock->now(),
                    EntityId::generate(),
                    reason: "Fictional unrelated charge.",
                ),
            ),
        );
        $f->sql->update("sis_sections", ["fee_minor" => 9000], ["id" => $selection->from->value]);
        $review = $f->sectionChanges()->review($selection, true);
        self::assertTrue($review["can_confirm"]);
        self::assertSame(1000, $review["financial"]["credit_minor"]);
        $this->commit($f, $selection, $review);
        self::assertSame(2000000000, $f->sql->rows("sis_account_balances")[0]["balance_minor"]);
    }
    public function testReplacementPreservesTermFeeAndReversesOriginalTuitionAndLinkedCredits(): void
    {
        [$f, $selection] = $this->fixture(priced: true);
        $account = new \Domain\Finance\ValueObject\AccountId($f->sql->rows("sis_student_accounts")[0]["id"]);
        $root = $f->accounts->get($account);
        $tuition = array_values(
            array_filter(
                $root->entries(),
                static fn(array $entry): bool => isset($entry["registration_price"]),
            ),
        )[0];
        $credits = new \Domain\Finance\Service\AccountEntryReviewService(
            $f->accounts,
            $f->access,
            $f->transactions,
            $f->financialCommands(),
            $f->audit,
            $f->dropCipher,
            $f->clock,
        );
        $creditReview = $credits->reviewChargeCredit(
            $account,
            new EntityId($tuition["operation_id"]),
            new \Domain\Finance\ValueObject\Money(100, "USD"),
            $root->playhead(),
            "Fictional approved tuition credit.",
        );
        $credits->commitChargeCredit(
            $account,
            new EntityId($tuition["operation_id"]),
            $creditReview["review_token"],
        );
        $rule = $f->sql->rows("sis_billing_rules", ["pricing_basis" => "registered_credit"])[0];
        $this->rules($f)->retire(
            new \Domain\Finance\ValueObject\BillingRuleId($rule["id"]),
            1,
            "Fictional replacement rate.",
        );
        $this->rules($f)->define(
            EntityId::generate(),
            $f->term,
            null,
            "Fictional new credit rate",
            "tuition",
            new \Domain\Finance\ValueObject\Money(200, "USD"),
            "Fictional approved rate.",
            \Domain\Finance\Enum\BillingBasis::RegisteredCredit,
        );
        $review = $f->sectionChanges()->review($selection, true);
        self::assertSame(1200, $review["financial"]["credit_minor"]);
        self::assertSame(2100, $review["financial"]["new_charge_minor"]);
        self::assertSame(900, $review["financial"]["net_change_minor"]);
        $this->commit($f, $selection, $review);
        self::assertSame(2600, $f->accounts->get($account)->balance()->minor);
    }
}
