<?php

declare(strict_types=1);

namespace Tests\Integration;

use Domain\Registration\Waitlist\WaitlistPolicy;
use Domain\Audit\Repository\AuditTrail;
use Domain\Registration\Service\RegistrationService;
use Domain\Shared\Exception\ConcurrentModificationException;
use Domain\Shared\EventSourcing\CorruptHistoryException;
use Domain\Registration\ValueObject\RegistrationBatchSelection;
use Domain\Shared\Exception\AccessDeniedException;
use Domain\Shared\Exception\ValidationException;
use Domain\Shared\ValueObject\EntityId;
use PHPUnit\Framework\TestCase;
use SisFixture;

final class WaitlistWorkflowTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once dirname(__DIR__) . "/Support/SisFixture.php";
    }
    private function fixture(): array
    {
        $s = new SisFixture();
        $section = $s->section(capacity: 1);
        $filler = $s->student($s->user());
        $s->service->register($filler->id, $section->id, true);
        return [$s, $section, $filler];
    }
    private function policy(
        SisFixture $s,
        EntityId $section,
        bool $enabled = true,
        int $maximum = 50,
    ): array {
        $service = $s->waitlists();
        $r = $service->reviewPolicy(
            $section,
            new WaitlistPolicy($enabled, $maximum)->describe(),
            $service->policy($section)["version"],
            "Fictional section waitlisting approval.",
        );
        return $service->confirmPolicy(
            $section,
            new EntityId($r["operation_id"]),
            $r["policy"],
            $r["version"],
            $r["reason"],
            $r["review_token"],
        );
    }
    private function confirm(
        SisFixture $s,
        EntityId $student,
        EntityId $section,
        array $r,
        bool $staff = true,
    ): array {
        return $s
            ->waitlists()
            ->confirm(
                $student,
                $section,
                new EntityId($r["operation_id"]),
                $r["action"],
                $staff,
                $r["revision"],
                $r["review_token"],
                $r["reason"],
            );
    }
    private function join(
        SisFixture $s,
        EntityId $student,
        EntityId $section,
        bool $staff = true,
    ): array {
        $r = $s->waitlists()->review($student, $section, "requested", $staff, null);
        return $this->confirm($s, $student, $section, $r, $staff);
    }
    private function drop(SisFixture $s, EntityId $student, EntityId $section): void
    {
        $r = $s->drops->review($student, $section);
        $s->drops->commit(
            $student,
            $section,
            new EntityId($r["enrollment_id"]),
            $r["version"],
            new EntityId($r["operation_id"]),
            $r["revision"],
            $r["effective_on"],
        );
    }
    public function testEverySectionStartsDisabledAndEnablingOneDoesNotEnableAnother(): void
    {
        [$s, $section] = $this->fixture();
        $other = $s->section();
        self::assertFalse($s->waitlists()->policy($section->id)["policy"]["enabled"]);
        $this->policy($s, $section->id);
        self::assertTrue($s->waitlists()->policy($section->id)["policy"]["enabled"]);
        self::assertFalse($s->waitlists()->policy($other->id)["policy"]["enabled"]);
        $student = $s->student($s->user());
        $this->expectException(ValidationException::class);
        $this->join($s, $student->id, $other->id);
    }
    public function testAcceptedServerOrderControlsAllRegistrationAndAtomicAcademicBillingAllocation(): void
    {
        [$s, $section, $filler] = $this->fixture();
        $this->policy($s, $section->id);
        $a = $s->student($s->user());
        $b = $s->student($s->user());
        $c = $s->student($s->user());
        $before = $this->facts($s);
        $firstPreview = $s->waitlists()->review($a->id, $section->id, "requested", true, null);
        $secondPreview = $s->waitlists()->review($b->id, $section->id, "requested", true, null);
        $bResult = $this->confirm($s, $b->id, $section->id, $secondPreview);
        $aResult = $this->confirm($s, $a->id, $section->id, $firstPreview);
        self::assertSame(2, $bResult["accepted_position"]);
        self::assertSame(3, $aResult["accepted_position"]);
        self::assertSame($before, $this->facts($s));
        self::assertSame(1, $s->waitlists()->view($b->id, $section->id, true)["rank"]);
        self::assertSame(2, $s->waitlists()->view($a->id, $section->id, true)["rank"]);
        $this->drop($s, $filler->id, $section->id);
        $before = $this->facts($s);
        try {
            $s->service->register($c->id, $section->id, true);
            self::fail("An outsider bypassed the queue.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($s));
        }
        try {
            $s->service->register($a->id, $section->id, true);
            self::fail("A later accepted request bypassed the head.");
        } catch (ValidationException) {
            self::assertSame($before, $this->facts($s));
        }
        $batch = $s->registrationBatches();
        $selection = new RegistrationBatchSelection($section->id, $b->id);
        $review = $batch->review($selection, true);
        self::assertTrue($review["students"][0]["waitlist"]["can_register"]);
        $result = $batch->commit(
            new EntityId($review["operation_id"]),
            $selection,
            $review["revision"],
            $review["review_token"],
            true,
        );
        self::assertCount(1, $result["registrations"]);
        $account = $s->sql->one("sis_student_accounts", ["student_id" => $b->id->value]);
        self::assertSame(
            10000,
            $s->accounts->get(new \Domain\Finance\ValueObject\AccountId($account["id"]))->balance()
                ->minor,
        );
        self::assertSame(
            "registered",
            $s->sql->one("sis_waitlist_entries", ["id" => $bResult["entry_id"]])["status"],
        );
        self::assertSame(1, $s->waitlists()->view($a->id, $section->id, true)["rank"]);
        self::assertCount(
            1,
            $s->sql->rows("sis_academic_credits", ["student_id" => $b->id->value]),
        );
        $events = $s->sql->rows("sis_history_events");
        self::assertSame(
            $result,
            $batch->commit(
                new EntityId($review["operation_id"]),
                $selection,
                $review["revision"],
                $review["review_token"],
                true,
            ),
        );
        self::assertSame($events, $s->sql->rows("sis_history_events"));
    }
    private function facts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_enrollments",
            "sis_academic_credits",
            "sis_student_accounts",
            "sis_account_entries",
            "sis_account_balances",
        ]);
    }
    public function testDisablingNewRequestsPreservesPriorityAndCancellationRejoinGoesToTheBack(): void
    {
        [$s, $section] = $this->fixture();
        $this->policy($s, $section->id);
        $a = $s->student($s->user());
        $b = $s->student($s->user());
        $c = $s->student($s->user());
        $aResult = $this->join($s, $a->id, $section->id);
        $this->join($s, $b->id, $section->id);
        $this->policy($s, $section->id, false);
        self::assertSame(2, $s->waitlists()->listing($section->id)["waiting_count"]);
        self::assertFalse($s->waitlistGuard()->status($section->id, $c->id)["can_register"]);
        $r = $s
            ->waitlists()
            ->review($a->id, $section->id, "cancelled", true, new EntityId($aResult["entry_id"]));
        $this->confirm($s, $a->id, $section->id, $r);
        self::assertSame(
            "cancelled",
            $s->sql->one("sis_waitlist_entries", ["id" => $aResult["entry_id"]])["status"],
        );
        $this->policy($s, $section->id, true);
        $again = $this->join($s, $a->id, $section->id);
        self::assertNotSame($aResult["entry_id"], $again["entry_id"]);
        self::assertSame(2, $s->waitlists()->view($a->id, $section->id, true)["rank"]);
        self::assertSame(1, $s->waitlists()->view($b->id, $section->id, true)["rank"]);
    }
    public function testOwnerScopeOriginalExpiredRetryAndStaffDutiesAreIndependent(): void
    {
        [$s, $section] = $this->fixture();
        $this->policy($s, $section->id);
        $owner = $s->user();
        $otherOwner = $s->user();
        $student = $s->student($owner);
        $other = $s->student($otherOwner);
        $s->access->actor = $owner;
        $s->access->staff = false;
        $r = $s->waitlists()->review($student->id, $section->id, "requested", false, null);
        $result = $this->confirm($s, $student->id, $section->id, $r, false);
        self::assertCount(1, $s->waitlists()->mine($student->id)["items"]);
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        self::assertSame($result, $this->confirm($s, $student->id, $section->id, $r, false));
        try {
            $s->waitlists()->view($other->id, $section->id, false);
            self::fail("An owner read another student.");
        } catch (AccessDeniedException) {
            self::assertTrue(true);
        }
        $s->access->grants = ["sis:registration:waitlist"];
        $this->expectException(AccessDeniedException::class);
        $s->waitlists()->listing($section->id);
    }
    private function allFacts(SisFixture $s): array
    {
        return array_map($s->sql->rows(...), [
            "sis_history_events",
            "sis_history_streams",
            "sis_history_operations",
            "sis_waitlist_state",
            "sis_waitlist_entries",
            "sis_enrollments",
            "sis_academic_credits",
            "sis_student_accounts",
            "sis_account_entries",
            "sis_account_balances",
            "sis_audit",
            "sis_registration_updates",
            "sis_registration_batches",
        ]);
    }

    public function testLateAllocationAuditFailureRollsBackEveryBusinessFactAndRestoresPriority(): void
    {
        [$s, $section, $filler] = $this->fixture();
        $this->policy($s, $section->id);
        $student = $s->student($s->user());
        $this->join($s, $student->id, $section->id);
        $this->drop($s, $filler->id, $section->id);
        $before = $this->allFacts($s);
        $audit = $this->createMock(AuditTrail::class);
        $audit
            ->expects(self::atLeastOnce())
            ->method("record")
            ->willReturnCallback(static function (
                EntityId $actor,
                string $action,
                EntityId $subject,
            ) use ($s): void {
                $s->audit->record($actor, $action, $subject);
                if ($action === "registration.waitlist_registered") {
                    throw new \RuntimeException("Fictional required audit failure.");
                }
            });
        $service = new RegistrationService(
            $s->registration,
            $s->admissions,
            $s->access,
            $s->transactions,
            $s->termFees(),
            $audit,
            $s->clock,
            $s->drops,
            $s->academicCredits(),
            $s->enrollmentLedger(),
            $s->activityLedger(),
            $s->primaryProgramLedger(),
            $s->workPursuits(),
            $s->prerequisites(),
            $s->schedules(),
            $s->corequisites(),
            $s->windowPolicies(),
            $s->waitlistGuard($audit),
        );
        try {
            $service->register($student->id, $section->id, true);
            self::fail("Partial allocation survived.");
        } catch (\RuntimeException $e) {
            self::assertSame("Fictional required audit failure.", $e->getMessage());
        }
        self::assertSame($before, $this->allFacts($s));
        self::assertSame(1, $s->waitlists()->view($student->id, $section->id, true)["rank"]);
        $s->service->register($student->id, $section->id, true);
        self::assertNull($s->waitlists()->view($student->id, $section->id, true)["rank"]);
    }

    public function testSelectedOldRequestCannotCancelAReplacementRequestAndOldConfirmationIsOnlyAReceipt(): void
    {
        [$s, $section] = $this->fixture();
        $this->policy($s, $section->id);
        $student = $s->student($s->user());
        $first = $this->join($s, $student->id, $section->id);
        $cancel = $s
            ->waitlists()
            ->review(
                $student->id,
                $section->id,
                "cancelled",
                true,
                new EntityId($first["entry_id"]),
            );
        $accepted = $this->confirm($s, $student->id, $section->id, $cancel);
        $replacement = $this->join($s, $student->id, $section->id);
        self::assertSame($accepted, $this->confirm($s, $student->id, $section->id, $cancel));
        self::assertSame(
            $replacement["entry_id"],
            $s->waitlists()->view($student->id, $section->id, true)["entry_id"],
        );
        $this->expectException(ConcurrentModificationException::class);
        $s->waitlists()->review(
            $student->id,
            $section->id,
            "removed",
            true,
            new EntityId($first["entry_id"]),
            "A stale fictional selection.",
        );
    }

    public function testMissingActiveProjectionCannotRemoveItsAuthoritativePriority(): void
    {
        [$s, $section, $filler] = $this->fixture();
        $this->policy($s, $section->id);
        $student = $s->student($s->user());
        $outsider = $s->student($s->user());
        $entry = $this->join($s, $student->id, $section->id);
        $this->drop($s, $filler->id, $section->id);
        $s->connection
            ->queryBuilder()
            ->table("sis_waitlist_entries")
            ->where("id", $entry["entry_id"])
            ->delete();
        $before = $this->allFacts($s);
        try {
            $s->service->register($outsider->id, $section->id, true);
            self::fail("Damaged index bypassed history.");
        } catch (CorruptHistoryException) {
            self::assertSame($before, $this->allFacts($s));
        }
    }

    public function testConcurrentJoinReviewsRecheckTheSectionLimitAndExpiryWithoutPartialFacts(): void
    {
        [$s, $section] = $this->fixture();
        $this->policy($s, $section->id, maximum: 1);
        $a = $s->student($s->user());
        $b = $s->student($s->user());
        $ra = $s->waitlists()->review($a->id, $section->id, "requested", true, null);
        $rb = $s->waitlists()->review($b->id, $section->id, "requested", true, null);
        $this->confirm($s, $a->id, $section->id, $ra);
        $before = $this->allFacts($s);
        try {
            $this->confirm($s, $b->id, $section->id, $rb);
            self::fail("The reviewed limit was bypassed.");
        } catch (ValidationException) {
            self::assertSame($before, $this->allFacts($s));
        }
        $this->policy($s, $section->id, maximum: 2);
        $rb = $s->waitlists()->review($b->id, $section->id, "requested", true, null);
        $before = $this->allFacts($s);
        $s->clock->time = $s->clock->time->modify("+21 minutes");
        try {
            $this->confirm($s, $b->id, $section->id, $rb);
            self::fail("An expired new request was accepted.");
        } catch (ValidationException | ConcurrentModificationException) {
            self::assertSame($before, $this->allFacts($s));
        }
    }

    public function testDisabledNewJoinsStillRequireTheOldestRequestAndAllowItsOrdinaryRegistration(): void
    {
        [$s, $section, $filler] = $this->fixture();
        $this->policy($s, $section->id);
        $a = $s->student($s->user());
        $b = $s->student($s->user());
        $this->join($s, $a->id, $section->id);
        $this->join($s, $b->id, $section->id);
        $this->policy($s, $section->id, false);
        $this->drop($s, $filler->id, $section->id);
        $before = $this->allFacts($s);
        try {
            $s->service->register($b->id, $section->id, true);
            self::fail("Disabling requests erased priority.");
        } catch (ValidationException) {
            self::assertSame($before, $this->allFacts($s));
        }
        $s->service->register($a->id, $section->id, true);
        self::assertSame(1, $s->waitlists()->view($b->id, $section->id, true)["rank"]);
    }

    public function testAcceptedPagesAndFrameworkReplayPreserveTheSameQueueWithoutOwnerIdentityExposure(): void
    {
        [$s, $section] = $this->fixture();
        $this->policy($s, $section->id, maximum: 25);
        $owner = $s->user();
        $mine = $s->student($owner);
        $this->join($s, $mine->id, $section->id);
        for ($i = 0; $i < 20; $i++) {
            $this->join($s, $s->student($s->user())->id, $section->id);
        }
        $page = $s->waitlists()->listing($section->id);
        self::assertCount(20, $page["items"]);
        $last = $s->waitlists()->listing($section->id, $page["next_after"]);
        self::assertCount(1, $last["items"]);
        self::assertSame(21, $last["items"][0]["rank"]);
        self::assertNull($last["next_after"]);
        $id = new \Domain\Registration\Waitlist\WaitlistId($section->id->value);
        $history = \EventStoreFixture::framework(
            $s->connection->queryBuilder(),
        )->getAggregateHistoryFor($id);
        $replayed = \Domain\Registration\Waitlist\SectionWaitlist::reconstituteFromEventStream(
            $history,
        );
        self::assertSame(
            $s
                ->waitlistLedger()
                ->load($section->id, \Domain\Registration\Waitlist\WaitlistLedger::budget())
                ->projection(),
            $replayed->projection(),
        );
        $s->access->actor = $owner;
        $s->access->staff = false;
        $visible = $s->waitlists()->view($mine->id, $section->id, false);
        self::assertSame(1, $visible["rank"]);
        self::assertArrayNotHasKey("items", $visible);
        self::assertArrayNotHasKey("current_name", $visible);
    }

    private function coordinated(int $count = 3, int $seats = 2): array
    {
        [$s, $section, $filler] = $this->fixture();
        $this->policy($s, $section->id);
        $waiting = [];
        for ($index = 0; $index < $count; $index++) {
            $waiting[] = $s->student($s->user());
        }
        // Deliberately reverse student identity order: accepted queue order is a separate fact.
        usort($waiting, static fn($a, $b): int => strcmp($b->id->value, $a->id->value));
        foreach ($waiting as $student) {
            $this->join($s, $student->id, $section->id);
        }
        $this->drop($s, $filler->id, $section->id);
        // Fictional ordinary capacity setup after the filler was removed; no historical event is edited.
        $s->sql->update('sis_sections', ['capacity' => $seats], ['id' => $section->id->value]);
        return [$s, $section, $waiting];
    }

    private function confirmGroup(
        SisFixture $s,
        RegistrationBatchSelection $selection,
        array $review,
        ?\Domain\Registration\Service\RegistrationBatchService $service = null
    ): array {
        return ($service ?? $s->registrationBatches())->commit(
            new EntityId($review['operation_id']),
            $selection,
            $review['revision'],
            $review['review_token'],
            true
        );
    }

    public function testCoordinatedOldestRequestsKeepFifoChargesNotificationsAndOriginalReceipts(): void
    {
        [$s, $section, $waiting] = $this->coordinated();
        $selection = new RegistrationBatchSelection($section->id, $waiting[0]->id, $waiting[1]->id);
        $before = $this->allFacts($s);
        try {
            $s->service->register($waiting[1]->id, $section->id, true);
            self::fail('A later individual request bypassed the oldest waiting student.');
        } catch (ValidationException) {
            self::assertSame($before, $this->allFacts($s));
        }
        $review = $s->registrationBatches()->review($selection);
        self::assertSame([true, true], array_column(array_column($review['students'], 'waitlist'), 'can_register'));
        self::assertSame(
            [true, false],
            array_column(array_column($review['students'], 'waitlist'), 'selected_predecessors'),
        );
        self::assertSame($before, $this->allFacts($s));
        $result = $this->confirmGroup($s, $selection, $review);
        self::assertSame(
            array_map(static fn($student): string => $student->id->value, array_slice($waiting, 0, 2)),
            array_column($result['registrations'], 'student_id')
        );
        self::assertSame(2, $result['created']);
        self::assertSame(2, $s->registration->sectionCount($section->id));
        self::assertSame([$waiting[2]->id->value], array_column($s->waitlistLedger()->load(
            $section->id,
            \Domain\Registration\Waitlist\WaitlistLedger::budget()
        )->active(), 'student_id'));
        self::assertCount(2, $s->sql->rows('sis_registration_updates'));
        foreach (array_slice($waiting, 0, 2) as $student) {
            $account = $s->sql->one('sis_student_accounts', ['student_id' => $student->id->value]);
            self::assertSame(
                10000,
                $s->accounts->get(new \Domain\Finance\ValueObject\AccountId($account['id']))->balance()->minor,
            );
        }
        $this->drop($s, $waiting[0]->id, $section->id);
        $notice = $s->sql->rows('sis_registration_updates')[0];
        $s->sql->execute('DELETE FROM sis_registration_updates WHERE id = ?', [$notice['id']]);
        $s->clock->time = $s->clock->time->modify('+1 day');
        $before = $this->allFacts($s);
        self::assertSame($result, $this->confirmGroup($s, $selection, $review));
        self::assertSame($before, $this->allFacts($s));
    }

    public static function incompleteCoordinatedGroups(): array
    {
        return [[[0, 2]], [[1, 2]]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteCoordinatedGroups')]
    public function testCoordinatedGroupsCannotSkipAnyUnselectedEarlierRequest(array $indexes): void
    {
        [$s, $section, $waiting] = $this->coordinated();
        $selection = new RegistrationBatchSelection(
            $section->id,
            ...array_map(static fn(int $index): EntityId => $waiting[$index]->id, $indexes)
        );
        $review = $s->registrationBatches()->review($selection);
        self::assertContains(false, array_column(array_column($review['students'], 'waitlist'), 'can_register'));
        $before = $this->allFacts($s);
        try {
            $this->confirmGroup($s, $selection, $review);
            self::fail('A group skipped an earlier waiting request.');
        } catch (ValidationException) {
            self::assertSame($before, $this->allFacts($s));
        }
    }

    public function testCoordinatedFailureAtTheSecondSeatRestoresEveryFirstSeatEffect(): void
    {
        [$s, $section, $waiting] = $this->coordinated(seats: 1);
        $selection = new RegistrationBatchSelection($section->id, $waiting[0]->id, $waiting[1]->id);
        $review = $s->registrationBatches()->review($selection);
        $before = $this->allFacts($s);
        try {
            $this->confirmGroup($s, $selection, $review);
            self::fail('Insufficient seats left a partial queue allocation.');
        } catch (ValidationException) {
            self::assertSame($before, $this->allFacts($s));
        }
    }

    public function testCoordinatedReviewDetectsQueueDecisionsEvenWhenPolicyValuesAreRestored(): void
    {
        [$s, $section, $waiting] = $this->coordinated();
        $selection = new RegistrationBatchSelection($section->id, $waiting[0]->id, $waiting[1]->id);
        $review = $s->registrationBatches()->review($selection);
        $this->policy($s, $section->id, false);
        $this->policy($s, $section->id, true);
        $before = $this->allFacts($s);
        try {
            $this->confirmGroup($s, $selection, $review);
            self::fail('A restored policy hid intervening queue decisions.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->allFacts($s));
        }
    }

    public static function coordinatedFinalFailures(): array
    {
        return [['queue', ConcurrentModificationException::class], ['hold', \DomainException::class],
            ['authority', AccessDeniedException::class], ['audit', \LogicException::class]];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coordinatedFinalFailures')]
    public function testCoordinatedFinalFailuresRestoreQueueAcademicsFinanceNoticesAndReceipt(
        string $failure,
        string $expected
    ): void {
        [$s, $section, $waiting] = $this->coordinated();
        $selection = new RegistrationBatchSelection($section->id, $waiting[0]->id, $waiting[1]->id);
        $audit = $this->createStub(AuditTrail::class);
        $onAudit = function ($actor, $action) use ($s, $section, $waiting, $failure): void {
            if ($action !== 'registration.batch_completed') {
                return;
            }
            if ($failure === 'queue') {
                $this->policy($s, $section->id, false);
            } elseif ($failure === 'hold') {
                $s->sql->insert('sis_restrictions', ['id' => EntityId::generate()->value,
                    'person_id' => $waiting[0]->personId->value, 'code' => 'ADVISING', 'severity' => 99,
                    'starts_on' => '2026-08-01', 'ends_on' => null, 'blocks_staff' => 1]);
            } elseif ($failure === 'authority') {
                $s->access->staff = false;
                $s->access->grants = [];
            } else {
                throw new \LogicException('Fictional final group audit failure.');
            }
        };
        $audit->method('record')->willReturnCallback($onAudit);
        $service = $s->registrationBatches(audit: $audit);
        $review = $service->review($selection);
        $tables = $this->allFacts($s);
        $holds = $s->sql->rows('sis_restrictions');
        $error = null;
        try {
            $this->confirmGroup($s, $selection, $review, $service);
        } catch (\Throwable $caught) {
            $error = $caught;
        }
        self::assertInstanceOf($expected, $error);
        self::assertSame($tables, $this->allFacts($s));
        self::assertSame($holds, $s->sql->rows('sis_restrictions'));
    }

    public function testCoordinatedOwnerCannotRegisterAGroupAndAnotherStaffActorCannotUseTheProof(): void
    {
        [$s, $section, $waiting] = $this->coordinated();
        $selection = new RegistrationBatchSelection($section->id, $waiting[0]->id, $waiting[1]->id);
        $review = $s->registrationBatches()->review($selection);
        $before = $this->allFacts($s);
        $staff = $s->access->actor;
        $s->access->staff = false;
        $s->access->actor = $s->admissions->person($waiting[0]->personId)->ownerUserId;
        try {
            $s->registrationBatches()->review($selection, false);
            self::fail('An owner opened a multi-student registration.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->allFacts($s));
        }
        $s->access->staff = true;
        // The currently signed-in actor changed; the reviewing staff actor remains distinct.
        try {
            $this->confirmGroup($s, $selection, $review);
            self::fail('Another actor used the original group proof.');
        } catch (AccessDeniedException) {
            self::assertSame($before, $this->allFacts($s));
        }
        $s->access->actor = $staff;
        $s->clock->time = $s->clock->time->modify('+21 minutes');
        try {
            $this->confirmGroup($s, $selection, $review);
            self::fail('An expired queue-group review was accepted.');
        } catch (ConcurrentModificationException) {
            self::assertSame($before, $this->allFacts($s));
        }
    }

    public function testCoordinatedFullSelectedQueuePrecedesAnUnqueuedStudent(): void
    {
        [$s, $section, $waiting] = $this->coordinated(count: 2, seats: 3);
        $unqueued = $s->student($s->user());
        $selection = new RegistrationBatchSelection($section->id, $unqueued->id, $waiting[1]->id, $waiting[0]->id);
        $review = $s->registrationBatches()->review($selection);
        $result = $this->confirmGroup($s, $selection, $review);
        self::assertSame(
            [$waiting[0]->id->value, $waiting[1]->id->value, $unqueued->id->value],
            array_column($result['registrations'], 'student_id')
        );
        self::assertCount(2, $s->sql->rows('sis_registration_updates'));
    }

    public function testCoordinatedMaximumGroupIsBoundedAndPreservesEveryQueuePosition(): void
    {
        [$s, $section, $waiting] = $this->coordinated(count: 25, seats: 25);
        $selection = new RegistrationBatchSelection(
            $section->id,
            ...array_map(static fn($student): EntityId => $student->id, $waiting)
        );
        $review = $s->registrationBatches()->review($selection);
        $result = $this->confirmGroup($s, $selection, $review);
        self::assertSame(25, $result['created']);
        self::assertSame(
            array_map(static fn($student): string => $student->id->value, $waiting),
            array_column($result['registrations'], 'student_id')
        );
        self::assertCount(25, $s->sql->rows('sis_registration_updates'));
        self::assertSame([], $s->waitlistLedger()->load(
            $section->id,
            \Domain\Registration\Waitlist\WaitlistLedger::budget()
        )->active());
    }

    public function testCoordinatedReplayAllowanceDoesNotRestartForEachWaitingStudent(): void
    {
        [$s, $section, $waiting] = $this->coordinated(count: 25, seats: 25);
        for ($index = 0; $index < 30; $index++) {
            $this->policy($s, $section->id, false);
            $this->policy($s, $section->id, true);
        }
        $selection = new RegistrationBatchSelection(
            $section->id,
            ...array_map(static fn($student): EntityId => $student->id, $waiting),
        );
        $review = $s->registrationBatches()->review($selection);
        $before = $this->allFacts($s);
        try {
            $this->confirmGroup($s, $selection, $review);
            self::fail('A group restarted its replay allowance for each waiting student.');
        } catch (\Domain\Shared\EventSourcing\HistoryReadLimitException) {
            self::assertSame($before, $this->allFacts($s));
        }
    }
}
